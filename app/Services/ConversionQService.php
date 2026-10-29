<?php

namespace App\Services;

use App\Enums\TipoVinculacion;
use App\Models\TipoPrestamo;
use App\Rules\MontoColombiano;
use App\Services\Carga\LectorCsv;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * TEMPORAL — conversión puntual de dos exportes del AS/400 (docs/conversion-q.md) a las plantillas
 * de «Cargar empleados» y «Cargar préstamos vigentes». No es parte del producto: solo la usa el
 * comando datos:convertir-q.
 *
 *  - Q09 (nómina): título en la fila 1 y encabezados en la 2 (se busca la fila de encabezados).
 *  - Q15 (cartera): encabezados en la fila 1.
 *
 * Privacidad: trabaja en memoria con lo que recibe; devuelve los CSV y un informe de AGREGADOS
 * (conteos). Ningún mensaje ni resultado lleva nombres, cédulas, salarios ni montos individuales.
 */
class ConversionQService
{
    public const COLUMNAS_NOMINA = ['cedula', 'nombre', 'programa', 'cargo', 'ingreso', 'asignacion', 'tipo_contrato'];

    public const COLUMNAS_CARTERA = ['cedula_nueva', 'concepto_nuevo', 'fecha_prestavo', 'valor_monto', 'total_quincena', 'quincena_nueva', 'saldo_nuevo'];

    /** Columna de la cartera que no es obligatoria (solo la usa la tasa deducida). */
    public const COLUMNAS_OPCIONALES_CARTERA = ['valor_de_cuota'];

    public const INTERPRETACIONES = ['auto', 'restantes', 'pagadas'];

    public const MODOS_TASA = ['derivada', 'vacia'];

    /** Rango en el que se acepta una tasa deducida (% efectivo anual). */
    public const TASA_MINIMA = 0.0;

    public const TASA_MAXIMA = 40.0;

    /** Un préstamo de vivienda con la tasa deducida a más de estos puntos de la del aplicativo no se usa: la cuota puede incluir seguros. */
    public const VIVIENDA_DIFERENCIA_MAXIMA = 2.0;

    /** Con menos préstamos que estos en un año no se detalla mínimo, mediana y máximo (para no identificar a nadie). */
    public const MINIMO_PARA_RESUMIR = 3;

    public function __construct(
        private readonly PrestamoService $prestamoService,
        private readonly CalculoFinancieroService $calculoFinanciero,
    ) {
        //
    }

    /**
     * @return array{empleados_csv: string, prestamos_csv: string, informe: array<string, mixed>}
     *
     * @throws InvalidArgumentException con un mensaje sin datos del archivo
     */
    public function convertir(string $contenidoQ09, string $contenidoQ15, string $interpretacion = 'auto', int $muestra = 500, string $tasa = 'derivada'): array
    {
        if (! in_array($interpretacion, self::INTERPRETACIONES, true)) {
            throw new InvalidArgumentException('La interpretación debe ser auto, restantes o pagadas.');
        }

        if (! in_array($tasa, self::MODOS_TASA, true)) {
            throw new InvalidArgumentException('La tasa debe ser derivada o vacia.');
        }

        $nomina = $this->leerTabla($contenidoQ09, self::COLUMNAS_NOMINA, 'q09');
        $cartera = $this->leerTabla($contenidoQ15, self::COLUMNAS_CARTERA, 'q15', self::COLUMNAS_OPCIONALES_CARTERA);

        $empleados = $this->convertirEmpleados($nomina);
        $informe = $empleados['informe'];

        $prestamos = $this->convertirCartera($cartera, $empleados['cedulas'], $informe, $interpretacion, $muestra, $tasa);

        return [
            'empleados_csv' => LectorCsv::escribir([CargaEmpleadosService::COLUMNAS, ...$empleados['filas']]),
            'prestamos_csv' => LectorCsv::escribir([CargaPrestamosVigentesService::COLUMNAS, ...$prestamos['filas']]),
            'informe' => $prestamos['informe'],
        ];
    }

    /**
     * Las modalidades de vivienda que existen en la base (nombres, sin datos personales).
     *
     * @return list<string>
     */
    public function modalidadesDeVivienda(): array
    {
        return TipoPrestamo::where('nombre', 'like', 'Vivienda%')->orderBy('nombre')->pluck('nombre')->all();
    }

    // ───────── Lectura ─────────

    /**
     * Busca la fila de encabezados (en las primeras 10), la reescribe con nombres canónicos
     * (sin tildes, en minúscula, con guion bajo) y lee la tabla con el LectorCsv común.
     *
     * @param  list<string>  $requeridas
     * @param  list<string>  $opcionales  columnas que se leen si están, sin exigirlas
     * @return list<array<string, string>>
     */
    private function leerTabla(string $contenido, array $requeridas, string $nombre, array $opcionales = []): array
    {
        $lineas = preg_split('/\r\n|\n|\r/', LectorCsv::decodificar($contenido)) ?: [];

        foreach (array_slice($lineas, 0, 10, true) as $indice => $linea) {
            $separador = substr_count($linea, ';') >= substr_count($linea, ',') ? ';' : ',';
            $celdas = array_map(fn (?string $celda): string => $this->clave((string) $celda), str_getcsv($linea, $separador, '"', ''));

            if (array_diff($requeridas, $celdas) !== []) {
                continue;
            }

            $datos = array_slice($lineas, $indice + 1);

            if (trim(implode('', $datos), " \t;,\r\n") === '') {
                return [];
            }

            $tabla = LectorCsv::leer(implode($separador, $celdas)."\n".implode("\n", $datos), [...$requeridas, ...$opcionales], $requeridas, PHP_INT_MAX, 'de datos');

            if ($tabla['errores'] !== []) {
                throw new InvalidArgumentException("El archivo {$nombre} no se pudo leer debajo de los encabezados.");
            }

            return array_map(fn (array $fila): array => $fila['datos'], $tabla['filas']);
        }

        throw new InvalidArgumentException("No se encontró la fila de encabezados esperada en {$nombre}: faltan columnas obligatorias.");
    }

    private function clave(string $texto): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '_', Str::lower(Str::ascii(trim($texto)))), '_');
    }

    // ───────── Q09: empleados ─────────

    /**
     * @param  list<array<string, string>>  $filas
     * @return array{filas: list<list<string>>, cedulas: array<string, true>, informe: array<string, mixed>}
     */
    private function convertirEmpleados(array $filas): array
    {
        $salida = [];
        $cedulas = [];
        $omitidos = ['cedula vacía o inválida' => 0, 'cédula repetida' => 0, 'salario vacío o cero' => 0];
        $porTipo = [];
        $contratos = [];
        $contradicciones = 0;
        $fechasInvalidas = 0;

        foreach ($filas as $fila) {
            $cedula = $this->cedula($fila['cedula']);

            if ($cedula === null) {
                $omitidos['cedula vacía o inválida']++;

                continue;
            }

            if (isset($cedulas[$cedula])) {
                $omitidos['cédula repetida']++;

                continue;
            }

            $salario = $this->monto($fila['asignacion']);

            if ($salario === null || $salario <= 0) {
                $omitidos['salario vacío o cero']++;

                continue;
            }

            $contrato = trim($fila['tipo_contrato']);
            $clasificacion = $this->clasificarContrato($contrato);
            $contratos[$contrato === '' ? '(vacío)' : $contrato] = [
                'conteo' => ($contratos[$contrato === '' ? '(vacío)' : $contrato]['conteo'] ?? 0) + 1,
                'significado' => $clasificacion['significado'],
            ];

            $tipoPorCargo = $this->tipoVinculacion($fila['cargo'], $clasificacion['ocasional']);
            $tipo = $clasificacion['tipo'] ?? $tipoPorCargo;

            if ($tipo !== $tipoPorCargo) {
                $contradicciones++;
            }

            $porTipo[$tipo] = ($porTipo[$tipo] ?? 0) + 1;

            $ingreso = $this->fechaAaaammdd($fila['ingreso']);

            if ($ingreso === null && trim($fila['ingreso']) !== '') {
                $fechasInvalidas++;
            }

            $cedulas[$cedula] = true;
            $salida[] = [
                $cedula,
                trim((string) preg_replace('/\s+/', ' ', $fila['nombre'])),
                $tipo,
                $clasificacion['de_carrera'],
                trim($fila['cargo']),
                trim($fila['programa']),
                $this->numero($salario),
                $ingreso ?? '',
                '',
            ];
        }

        ksort($contratos);

        return [
            'filas' => $salida,
            'cedulas' => $cedulas,
            'informe' => [
                'empleados' => [
                    'leidos' => count($filas),
                    'convertidos' => count($salida),
                    'omitidos' => $omitidos,
                    'por_tipo_vinculacion' => $porTipo,
                    'codigo_contradice_el_cargo' => $contradicciones,
                    'fechas_de_ingreso_invalidas' => $fechasInvalidas,
                ],
                'tipo_contrato' => $contratos,
            ],
        ];
    }

    /**
     * @return array{ocasional: bool, de_carrera: string, significado: string, tipo: ?string}
     */
    private function clasificarContrato(string $contrato): array
    {
        $valor = $this->mayusculas($contrato);

        $ocasional = $valor !== '' && collect((array) config('conversion_q.tipo_contrato.ocasional_contiene'))
            ->contains(fn (string $texto): bool => str_contains($valor, $this->mayusculas($texto)));

        $esCarrera = in_array($valor, array_map($this->mayusculas(...), (array) config('conversion_q.tipo_contrato.carrera')), true);
        $noEsCarrera = in_array($valor, array_map($this->mayusculas(...), (array) config('conversion_q.tipo_contrato.no_carrera')), true);

        $deCarrera = $esCarrera ? 'si' : ($noEsCarrera ? 'no' : '');

        $tipo = $this->tipoPorCodigo($valor);

        $significado = match (true) {
            $tipo !== null => 'código → '.TipoVinculacion::from($tipo)->etiqueta(),
            $ocasional => 'ocasional',
            $esCarrera => 'de carrera',
            $noEsCarrera => 'no de carrera',
            default => 'sin clasificar',
        };

        return ['ocasional' => $ocasional, 'de_carrera' => $deCarrera, 'significado' => $significado, 'tipo' => $tipo];
    }

    /**
     * El tipo de vinculación del código de Tipo Contrato (el valor es solo el número, con o sin
     * ceros a la izquierda), o null si el valor no es un código configurado.
     */
    private function tipoPorCodigo(string $valor): ?string
    {
        if (preg_match('/^\d+$/', $valor) !== 1) {
            return null;
        }

        $tipo = ((array) config('conversion_q.tipo_contrato.codigos'))[(int) $valor] ?? null;

        return is_string($tipo) && TipoVinculacion::tryFrom($tipo) !== null ? $tipo : null;
    }

    private function tipoVinculacion(string $cargo, bool $contratoOcasional): string
    {
        $cargo = $this->mayusculas($cargo);

        if (in_array($cargo, array_map($this->mayusculas(...), (array) config('conversion_q.cargos.jubilado')), true)) {
            return 'jubilado';
        }

        if (in_array($cargo, array_map($this->mayusculas(...), (array) config('conversion_q.cargos.docente')), true)) {
            return $contratoOcasional ? 'docente_ocasional' : 'docente';
        }

        return 'vinculado';
    }

    // ───────── Q15: préstamos ─────────

    /**
     * @param  list<array<string, string>>  $filas
     * @param  array<string, true>  $cedulasConvertidas
     * @param  array<string, mixed>  $informe
     * @return array{filas: list<list<string>>, informe: array<string, mixed>}
     */
    private function convertirCartera(array $filas, array $cedulasConvertidas, array $informe, string $interpretacion, int $muestra, string $modoTasa): array
    {
        $candidatas = [];
        $excluidos = [];
        $sinMapeo = [];
        $sinEmpleado = [];
        $datosInvalidos = ['monto' => 0, 'fecha' => 0, 'plazo' => 0, 'cuotas' => 0, 'cédula' => 0];
        $modalidadesInexistentes = [];
        $nombresExistentes = TipoPrestamo::pluck('nombre')->flip();
        $mapeo = (array) config('conversion_q.mapeo');
        $motivosExcluidos = (array) config('conversion_q.excluidos');

        foreach ($filas as $fila) {
            $concepto = $this->concepto($fila['concepto_nuevo']);

            if ($concepto !== null && isset($motivosExcluidos[$concepto])) {
                $excluidos[$concepto] = ['conteo' => ($excluidos[$concepto]['conteo'] ?? 0) + 1, 'motivo' => $motivosExcluidos[$concepto]];

                continue;
            }

            $modalidad = $concepto === null ? null : ($mapeo[$concepto] ?? null);

            if ($modalidad === null) {
                $clave = $concepto === null ? '(sin concepto)' : (string) $concepto;
                $sinMapeo[$clave] = ($sinMapeo[$clave] ?? 0) + 1;

                continue;
            }

            if (! isset($nombresExistentes[$modalidad])) {
                $modalidadesInexistentes[$modalidad] = ($modalidadesInexistentes[$modalidad] ?? 0) + 1;

                continue;
            }

            $cedula = $this->cedula($fila['cedula_nueva']);

            if ($cedula === null) {
                $datosInvalidos['cédula']++;

                continue;
            }

            if (! isset($cedulasConvertidas[$cedula])) {
                $sinEmpleado[$cedula] = true;

                continue;
            }

            $monto = $this->monto($fila['valor_monto']);
            $fecha = $this->fechaDdMmAaaa($fila['fecha_prestavo']);
            $total = $this->entero($fila['total_quincena']);
            $quincena = $this->entero($fila['quincena_nueva']);
            $saldo = $fila['saldo_nuevo'] === '' ? null : $this->monto($fila['saldo_nuevo']);
            $cuota = ($fila['valor_de_cuota'] ?? '') === '' ? null : $this->monto($fila['valor_de_cuota']);

            if ($monto === null || $monto <= 0) {
                $datosInvalidos['monto']++;
            } elseif ($fecha === null) {
                $datosInvalidos['fecha']++;
            } elseif ($total === null || $total < 1) {
                $datosInvalidos['plazo']++;
            } elseif ($quincena === null || $quincena < 0 || $quincena > $total) {
                $datosInvalidos['cuotas']++;
            } else {
                $candidatas[] = compact('cedula', 'modalidad', 'concepto', 'monto', 'fecha', 'total', 'quincena', 'saldo', 'cuota');
            }
        }

        $verificacion = $this->verificar($candidatas, $interpretacion, $muestra);
        $restantes = $verificacion['elegida'] === 'restantes';
        $tipos = TipoPrestamo::all()->keyBy('nombre');

        $salida = [];
        $tasas = ['deducidas' => 0, 'no_deducibles' => 0, 'por_motivo' => [], 'por_anio' => []];
        $porAnio = [];

        foreach ($candidatas as $fila) {
            $pagadas = $restantes ? $fila['total'] - $fila['quincena'] : $fila['quincena'];
            $tasaDeducida = '';

            if ($modoTasa === 'derivada') {
                $deduccion = $this->tasaParaSalida($fila, $restantes ? $fila['quincena'] : $fila['total'] - $fila['quincena'], $tipos);

                if ($deduccion['tasa'] === null) {
                    $tasas['no_deducibles']++;
                    $tasas['por_motivo'][$deduccion['motivo']] = ($tasas['por_motivo'][$deduccion['motivo']] ?? 0) + 1;
                } else {
                    $tasas['deducidas']++;
                    $porAnio[substr($fila['fecha'], 0, 4)][] = $deduccion['tasa'];
                    $tasaDeducida = $this->numero($deduccion['tasa']);
                }
            }

            $salida[] = [
                $fila['cedula'],
                $fila['modalidad'],
                $this->numero($fila['monto']),
                $fila['fecha'],
                (string) $fila['total'],
                (string) $pagadas,
                $tasaDeducida,
                '',
                $fila['saldo'] === null ? '' : $this->numero($fila['saldo']),
            ];
        }

        ksort($porAnio);
        ksort($tasas['por_motivo']);

        foreach ($porAnio as $anio => $valores) {
            $tasas['por_anio'][$anio] = $this->resumirTasas($valores);
        }

        $tasas['modo'] = $modoTasa;

        ksort($excluidos);
        ksort($sinMapeo);

        $informe['prestamos'] = [
            'leidos' => count($filas),
            'convertidos' => count($salida),
            'excluidos_por_concepto' => $excluidos,
            'sin_mapeo_por_concepto' => $sinMapeo,
            'modalidad_del_mapeo_inexistente' => $modalidadesInexistentes,
            'cedulas_sin_empleado' => count($sinEmpleado),
            'datos_invalidos' => array_filter($datosInvalidos),
        ];
        $informe['tasa'] = $tasas;
        $informe['verificacion'] = $verificacion;
        $informe['modalidades_de_vivienda'] = $this->modalidadesDeVivienda();

        return ['filas' => $salida, 'informe' => $informe];
    }

    /**
     * Para una muestra de filas calcula el saldo con la cuota francesa y la tasa del aplicativo
     * (la del año del desembolso) y lo compara con SALDO_NUEVO bajo cada interpretación de
     * QUINCENA_NUEVA: cuotas restantes (pagadas = total − quincena) o cuotas pagadas.
     *
     * @param  list<array<string, mixed>>  $candidatas
     * @return array<string, mixed>
     */
    private function verificar(array $candidatas, string $interpretacion, int $muestra): array
    {
        $resultado = [
            'muestra' => 0,
            'sin_tasa_o_saldo' => 0,
            'restantes_exactas' => 0,
            'pagadas_exactas' => 0,
            'restantes_aproximadas' => 0,
            'pagadas_aproximadas' => 0,
            'derivadas_restantes_en_rango' => 0,
            'derivadas_pagadas_en_rango' => 0,
            'elegida' => $interpretacion === 'auto' ? 'restantes' : $interpretacion,
            'criterio' => $interpretacion === 'auto' ? 'sin evidencia: se usa «restantes»' : 'indicada con --interpretacion',
        ];

        $tipos = TipoPrestamo::all()->keyBy('nombre');
        $segundas = (array) config('conversion_q.segunda_solicitud');

        $derivadas = ['restantes' => [], 'pagadas' => []];

        foreach (array_slice($candidatas, 0, max(0, $muestra)) as $fila) {
            $resultado['muestra']++;
            $tipo = $tipos[$fila['modalidad']];
            $tasa = $this->prestamoService->tasaDetallada($tipo, (int) substr($fila['fecha'], 0, 4), $fila['total'], ! in_array($fila['concepto'], $segundas, true), $fila['fecha'])['tasa'];

            if ($tasa === null && $fila['saldo'] !== null && $fila['cuota'] !== null) {
                // Sin la tasa del año en la base: se prueba qué lectura de QUINCENA_NUEVA da una tasa plausible.
                $anio = substr($fila['fecha'], 0, 4);

                foreach (['restantes' => $fila['quincena'], 'pagadas' => $fila['total'] - $fila['quincena']] as $nombre => $restantes) {
                    $deducida = $this->deducirTasa($fila['saldo'], $fila['cuota'], $restantes);

                    if ($deducida['tasa'] !== null) {
                        $resultado['derivadas_'.$nombre.'_en_rango']++;
                        $derivadas[$nombre][$anio][] = $deducida['tasa'];
                    }
                }

                continue;
            }

            if ($tasa === null || $fila['saldo'] === null) {
                $resultado['sin_tasa_o_saldo']++;

                continue;
            }

            $periodica = $this->calculoFinanciero->tasaPeriodicaQuincenal($tasa);
            $tabla = $this->calculoFinanciero->tablaAmortizacion($fila['monto'], $periodica, $fila['total'], $this->calculoFinanciero->cuotaFija($fila['monto'], $periodica, $fila['total']));
            $saldoTras = fn (int $pagadas): float => $pagadas === 0 ? $fila['monto'] : $tabla[$pagadas - 1]['saldo'];

            foreach (['restantes' => $fila['total'] - $fila['quincena'], 'pagadas' => $fila['quincena']] as $nombre => $pagadas) {
                $diferencia = abs($saldoTras($pagadas) - $fila['saldo']);

                $resultado[$nombre.'_exactas'] += $diferencia <= CargaPrestamosVigentesService::TOLERANCIA_SALDO ? 1 : 0;
                $resultado[$nombre.'_aproximadas'] += $diferencia <= max(1.0, 0.01 * $fila['saldo']) ? 1 : 0;
            }
        }

        if ($interpretacion === 'auto') {
            foreach (['exactas', 'aproximadas'] as $tipoDeCoincidencia) {
                [$r, $p] = [$resultado['restantes_'.$tipoDeCoincidencia], $resultado['pagadas_'.$tipoDeCoincidencia]];

                if ($r !== $p) {
                    $resultado['elegida'] = $r > $p ? 'restantes' : 'pagadas';
                    $resultado['criterio'] = "coincidencias {$tipoDeCoincidencia}: restantes {$r}, pagadas {$p}";

                    break;
                }
            }

            if ($resultado['elegida'] === 'restantes' && str_starts_with($resultado['criterio'], 'sin evidencia')) {
                [$r, $p] = [$resultado['derivadas_restantes_en_rango'], $resultado['derivadas_pagadas_en_rango']];

                if ($r !== $p) {
                    $resultado['elegida'] = $r > $p ? 'restantes' : 'pagadas';
                    $resultado['criterio'] = "tasa deducida plausible (0–40 %): restantes {$r}, pagadas {$p}";
                } elseif ($r > 0) {
                    [$dr, $dp] = [$this->dispersion($derivadas['restantes']), $this->dispersion($derivadas['pagadas'])];

                    if (abs($dr - $dp) > 1e-9) {
                        $resultado['elegida'] = $dr < $dp ? 'restantes' : 'pagadas';
                        $resultado['criterio'] = 'tasa deducida más consistente dentro de cada año';
                    }
                }
            }
        }

        return $resultado;
    }

    /**
     * Tasa efectiva anual (%) con la que una cuota francesa quincenal de $saldo a $restantes
     * quincenas da la cuota indicada, resuelta por bisección sobre r = (1 + EA)^(1/24) − 1.
     * Solo se acepta entre TASA_MINIMA y TASA_MAXIMA.
     *
     * @return array{tasa: ?float, motivo: ?string} motivo: sin_datos, fuera_de_rango o no_converge
     */
    public function deducirTasa(float $saldo, float $cuota, int $restantes): array
    {
        if ($saldo <= 0 || $cuota <= 0 || $restantes < 1) {
            return ['tasa' => null, 'motivo' => 'sin_datos'];
        }

        $cuotaA = fn (float $tasa): float => $this->calculoFinanciero->cuotaFija($saldo, $this->calculoFinanciero->tasaPeriodicaQuincenal($tasa), $restantes);

        if ($cuota < $cuotaA(self::TASA_MINIMA) - 0.5 || $cuota > $cuotaA(self::TASA_MAXIMA) + 0.5) {
            return ['tasa' => null, 'motivo' => 'fuera_de_rango'];
        }

        [$bajo, $alto] = [self::TASA_MINIMA, self::TASA_MAXIMA];

        for ($i = 0; $i < 80; $i++) {
            $medio = ($bajo + $alto) / 2;

            if ($cuotaA($medio) < $cuota) {
                $bajo = $medio;
            } else {
                $alto = $medio;
            }
        }

        $tasa = ($bajo + $alto) / 2;

        if (abs($cuotaA($tasa) - $cuota) > 1.0) {
            return ['tasa' => null, 'motivo' => 'no_converge'];
        }

        return ['tasa' => round($tasa, 2), 'motivo' => null];
    }

    /**
     * La tasa que se escribe en tasa_anual para una fila: la deducida, salvo en vivienda, donde la
     * cuota puede incluir seguros y la tasa se descarta si se aparta mucho de la del aplicativo.
     *
     * @param  array<string, mixed>  $fila
     * @param  Collection<string, TipoPrestamo>  $tipos
     * @return array{tasa: ?float, motivo: ?string}
     */
    private function tasaParaSalida(array $fila, int $restantes, $tipos): array
    {
        if ($fila['saldo'] === null || $fila['cuota'] === null) {
            return ['tasa' => null, 'motivo' => 'sin_datos'];
        }

        $deducida = $this->deducirTasa($fila['saldo'], $fila['cuota'], $restantes);

        if ($deducida['tasa'] === null || ! str_starts_with($fila['modalidad'], 'Vivienda')) {
            return $deducida;
        }

        $segundas = (array) config('conversion_q.segunda_solicitud');
        $referencia = $this->prestamoService->tasaDetallada($tipos[$fila['modalidad']], (int) substr($fila['fecha'], 0, 4), $fila['total'], ! in_array($fila['concepto'], $segundas, true), $fila['fecha'])['tasa']
            ?? round((float) config('prestamos.tasa_vivienda.tope') * 100, 4);

        if (abs($deducida['tasa'] - $referencia) > self::VIVIENDA_DIFERENCIA_MAXIMA) {
            return ['tasa' => null, 'motivo' => 'vivienda_descartada'];
        }

        return $deducida;
    }

    /**
     * Suma, sobre los años, de la desviación media de las tasas respecto a su mediana: menor es más consistente.
     *
     * @param  array<string, list<float>>  $porAnio
     */
    private function dispersion(array $porAnio): float
    {
        $total = 0.0;

        foreach ($porAnio as $valores) {
            $mediana = $this->mediana($valores);
            $total += array_sum(array_map(fn (float $v): float => abs($v - $mediana), $valores));
        }

        return $total;
    }

    /**
     * @param  list<float>  $valores
     * @return array{conteo: int, minimo?: float, mediana?: float, maximo?: float}
     */
    private function resumirTasas(array $valores): array
    {
        if (count($valores) < self::MINIMO_PARA_RESUMIR) {
            return ['conteo' => count($valores)];
        }

        return ['conteo' => count($valores), 'minimo' => min($valores), 'mediana' => $this->mediana($valores), 'maximo' => max($valores)];
    }

    /**
     * @param  list<float>  $valores
     */
    private function mediana(array $valores): float
    {
        sort($valores);
        $n = count($valores);
        $medio = intdiv($n, 2);

        return $n % 2 === 1 ? $valores[$medio] : round(($valores[$medio - 1] + $valores[$medio]) / 2, 2);
    }

    // ───────── Utilidades ─────────

    private function cedula(string $texto): ?string
    {
        $texto = preg_replace('/\.0+$/', '', trim($texto)) ?? '';
        $digitos = preg_replace('/\D/', '', $texto) ?? '';

        return $digitos !== '' && strlen($digitos) <= 10 ? $digitos : null;
    }

    private function concepto(string $texto): ?int
    {
        return preg_match('/^\s*0*(\d+)/', $texto, $partes) === 1 ? (int) $partes[1] : null;
    }

    private function monto(string $texto): ?float
    {
        $normalizado = MontoColombiano::normalizar(trim($texto));

        return preg_match('/^-?\d+(\.\d+)?$/', $normalizado) === 1 ? round((float) $normalizado, 2) : null;
    }

    private function entero(string $texto): ?int
    {
        $normalizado = preg_replace('/\.0+$/', '', trim($texto)) ?? '';

        return preg_match('/^\d{1,6}$/', $normalizado) === 1 ? (int) $normalizado : null;
    }

    private function numero(float $valor): string
    {
        return rtrim(rtrim(number_format($valor, 2, '.', ''), '0'), '.');
    }

    /**
     * aaaammdd → aaaa-mm-dd; null si no es una fecha real.
     */
    private function fechaAaaammdd(string $texto): ?string
    {
        $texto = preg_replace('/\.0+$/', '', trim($texto)) ?? '';

        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $texto, $p) !== 1 || ! checkdate((int) $p[2], (int) $p[3], (int) $p[1]) || (int) $p[1] < 1900) {
            return null;
        }

        return "{$p[1]}-{$p[2]}-{$p[3]}";
    }

    /**
     * dd/mm/aaaa → aaaa-mm-dd; null si no es una fecha real.
     */
    private function fechaDdMmAaaa(string $texto): ?string
    {
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', trim($texto), $p) !== 1 || ! checkdate((int) $p[2], (int) $p[1], (int) $p[3]) || (int) $p[3] < 1900) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $p[3], $p[2], $p[1]);
    }

    private function mayusculas(string $texto): string
    {
        return Str::upper(trim((string) preg_replace('/\s+/', ' ', Str::ascii($texto))));
    }
}
