<?php

namespace App\Services;

use App\Enums\EstadoCuota;
use App\Enums\EstadoDesembolso;
use App\Enums\EstadoPrestamo;
use App\Enums\TipoVinculacion;
use App\Http\Requests\Concerns\NormalizaTasas;
use App\Models\Cuota;
use App\Models\Desembolso;
use App\Models\Empleado;
use App\Models\Prestamo;
use App\Models\SmmlvAnual;
use App\Models\TasaInt;
use App\Models\TipoPrestamo;
use App\Models\Usuario;
use App\Rules\MontoColombiano;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Carga por archivo (CSV) de la cartera de préstamos que ya están vigentes, para operar el
 * aplicativo sin importar nada del AS/400. Son préstamos históricos: no se aplican las reglas
 * de capacidad, antigüedad, salario mínimo ni presupuesto, y la carga nunca consume ni
 * modifica el presupuesto. Los pagos históricos no se reconstruyen: las primeras N cuotas
 * quedan en estado Pagada, sin filas de pago ni abonos.
 *
 * analizar() valida cada fila sin guardar nada (vista previa); cargar() repite el análisis y
 * crea todo en una sola transacción, o nada.
 */
class CargaPrestamosVigentesService
{
    use NormalizaTasas;

    public const COLUMNAS = ['cedula', 'modalidad', 'monto_inicial', 'fecha_desembolso', 'plazo_quincenas', 'cuotas_pagadas', 'tasa_anual', 'puntos_adicionales', 'saldo_actual'];

    private const COLUMNAS_OBLIGATORIAS = ['cedula', 'modalidad', 'monto_inicial', 'fecha_desembolso', 'plazo_quincenas', 'cuotas_pagadas'];

    public const MAXIMO_FILAS = 1000;

    public const TOLERANCIA_SALDO = 1.0;

    public const MENSAJE_DOCENTE_OCASIONAL = 'Los docentes ocasionales no son beneficiarios de crédito del Fondo.';

    public function __construct(
        private readonly PrestamoService $prestamoService,
        private readonly CalculoFinancieroService $calculoFinanciero,
        private readonly CalendarioQuincenalService $calendario,
        private readonly AuditoriaService $auditoriaService,
    ) {
        //
    }

    /**
     * Plantilla descargable: UTF-8 con BOM, separador punto y coma, con tres filas de ejemplo
     * de empleados de demostración (cédulas ficticias 1000000xxx).
     */
    public function plantillaCsv(): string
    {
        $inicio = CarbonImmutable::now(CalendarioQuincenalService::ZONA)->startOfMonth();

        $filas = [
            self::COLUMNAS,
            ['1000000002', 'Educación', '3.000.000', $inicio->toDateString(), '24', '1', '', '', ''],
            ['1000000004', 'Calamidad doméstica y necesidades especiales', '2.000.000', $inicio->addDays(4)->toDateString(), '12', '0', '', '', ''],
            ['1000000005', 'Educación', '4.500.000,50', $inicio->addDays(9)->toDateString(), '36', '1', '', '', ''],
        ];

        $lineas = array_map(fn (array $fila): string => implode(';', $fila), $filas);

        return "\xEF\xBB\xBF".implode("\r\n", $lineas)."\r\n";
    }

    /**
     * Valida el archivo completo sin guardar nada.
     *
     * @return array{errores_archivo: list<string>, filas: list<array<string, mixed>>, resumen: array{total: int, correctas: int, advertencias: int, errores: int}}
     */
    public function analizar(string $contenido): array
    {
        $lectura = $this->leer($contenido);

        if ($lectura['errores'] !== []) {
            return ['errores_archivo' => $lectura['errores'], 'filas' => [], 'resumen' => $this->resumir([])];
        }

        $empleados = Empleado::whereIn('cedula', collect($lectura['filas'])->pluck('datos.cedula')->map(fn ($c) => trim((string) $c))->filter()->unique()->all())->get()->keyBy('cedula');
        $modalidades = TipoPrestamo::all()->keyBy(fn (TipoPrestamo $tipo): string => $this->llave($tipo->nombre));
        $vistas = [];
        $filas = [];

        foreach ($lectura['filas'] as $fila) {
            $filas[] = $this->analizarFila($fila['linea'], $fila['datos'], $empleados, $modalidades, $vistas);
        }

        return ['errores_archivo' => [], 'filas' => $filas, 'resumen' => $this->resumir($filas)];
    }

    /**
     * Crea los préstamos del archivo en una sola transacción: todo o nada.
     *
     * @return array{lote: string, prestamos: Collection<int, Prestamo>}
     */
    public function cargar(string $contenido, string $nombreArchivo, Usuario $actor): array
    {
        $analisis = $this->analizar($contenido);

        if ($analisis['errores_archivo'] !== [] || $analisis['filas'] === []) {
            throw new RuntimeException('El archivo no se puede cargar: '.($analisis['errores_archivo'][0] ?? 'no tiene filas.'));
        }

        if ($analisis['resumen']['errores'] > 0) {
            throw new RuntimeException('El archivo tiene filas con error; corríjalas y vuelva a subirlo. No se cargó nada.');
        }

        return DB::transaction(function () use ($analisis, $nombreArchivo, $actor): array {
            $lote = $this->nuevoLote();
            $prestamos = collect();

            foreach ($analisis['filas'] as $fila) {
                $prestamos->push($this->crearPrestamo($fila['calculo'], $lote));
            }

            $this->auditoriaService->registrar(
                $actor,
                'Carga de préstamos vigentes',
                "Archivo «{$nombreArchivo}» — lote {$lote} — {$prestamos->count()} préstamo(s) creado(s)",
            );

            return ['lote' => $lote, 'prestamos' => $prestamos];
        });
    }

    /**
     * Motivos por los que un lote no se puede revertir; vacío si se puede. Solo se revierte
     * si ningún préstamo del lote tiene pagos, abonos, reliquidaciones, desembolsos adicionales
     * o cancelaciones registrados después de la carga.
     *
     * @return list<string>
     */
    public function motivosParaNoRevertir(string $lote): array
    {
        $prestamos = Prestamo::where('lote_carga', $lote)->where('origen', Prestamo::ORIGEN_CARGA_INICIAL)->withCount(['pagos', 'abonosCesantias', 'reliquidaciones', 'desembolsos'])->get();

        if ($prestamos->isEmpty()) {
            return ['El lote no existe o ya fue revertido.'];
        }

        $motivos = [];

        foreach ($prestamos as $prestamo) {
            $problemas = [];

            if ($prestamo->pagos_count > 0) {
                $problemas[] = "{$prestamo->pagos_count} pago(s) registrado(s)";
            }

            if ($prestamo->abonos_cesantias_count > 0) {
                $problemas[] = "{$prestamo->abonos_cesantias_count} abono(s) de cesantías";
            }

            if ($prestamo->reliquidaciones_count > 0) {
                $problemas[] = "{$prestamo->reliquidaciones_count} reliquidación(es)";
            }

            if ($prestamo->desembolsos_count > 1) {
                $problemas[] = 'desembolsos adicionales al de la carga';
            }

            if ($prestamo->estado !== EstadoPrestamo::Vigente) {
                $problemas[] = "estado {$prestamo->estado->value} (cancelación)";
            }

            if ($problemas !== []) {
                $motivos[] = "El préstamo #{$prestamo->id_prestamo} tiene ".implode(', ', $problemas).' después de la carga.';
            }
        }

        return $motivos;
    }

    /**
     * Elimina los préstamos del lote con sus cuotas y desembolsos.
     */
    public function revertir(string $lote, Usuario $actor): int
    {
        $motivos = $this->motivosParaNoRevertir($lote);

        if ($motivos !== []) {
            throw new RuntimeException('No se puede revertir el lote '.$lote.': '.implode(' ', $motivos));
        }

        return DB::transaction(function () use ($lote, $actor): int {
            $ids = Prestamo::where('lote_carga', $lote)->where('origen', Prestamo::ORIGEN_CARGA_INICIAL)->pluck('id_prestamo')->all();

            Desembolso::whereIn('id_prestamo', $ids)->delete();
            Cuota::whereIn('id_prestamo', $ids)->delete();
            Prestamo::whereIn('id_prestamo', $ids)->delete();

            $this->auditoriaService->registrar($actor, 'Reversión de carga de préstamos vigentes', "Lote {$lote} — ".count($ids).' préstamo(s) eliminado(s)');

            return count($ids);
        });
    }

    /**
     * Lotes cargados, del más reciente al más antiguo, con su cantidad y si se pueden revertir.
     *
     * @return Collection<int, array{lote: string, cantidad: int, monto: float, fecha: string, reversible: bool, motivos: list<string>}>
     */
    public function lotes(): Collection
    {
        return Prestamo::where('origen', Prestamo::ORIGEN_CARGA_INICIAL)
            ->whereNotNull('lote_carga')
            ->selectRaw('lote_carga, count(*) as cantidad, sum(monto_aprobado) as monto, min(created_at) as fecha')
            ->groupBy('lote_carga')
            ->orderByDesc('fecha')
            ->orderByDesc('lote_carga')
            ->get()
            ->map(function (Prestamo $fila): array {
                $motivos = $this->motivosParaNoRevertir($fila->lote_carga);

                return [
                    'lote' => $fila->lote_carga,
                    'cantidad' => (int) $fila->cantidad,
                    'monto' => (float) $fila->monto,
                    'fecha' => (string) $fila->fecha,
                    'reversible' => $motivos === [],
                    'motivos' => $motivos,
                ];
            });
    }

    /**
     * @return array{errores: list<string>, filas: list<array{linea: int, datos: array<string, string>}>}
     */
    private function leer(string $contenido): array
    {
        $contenido = preg_replace('/^\xEF\xBB\xBF/', '', $contenido) ?? $contenido;

        if (! mb_check_encoding($contenido, 'UTF-8')) {
            $contenido = mb_convert_encoding($contenido, 'UTF-8', 'ISO-8859-1');
        }

        $lineas = preg_split('/\r\n|\n|\r/', $contenido) ?: [];

        while ($lineas !== [] && trim((string) end($lineas)) === '') {
            array_pop($lineas);
        }

        if ($lineas === [] || trim($lineas[0]) === '') {
            return ['errores' => ['El archivo está vacío.'], 'filas' => []];
        }

        $separador = substr_count($lineas[0], ';') >= substr_count($lineas[0], ',') ? ';' : ',';
        $encabezado = array_map(fn (?string $c): string => Str::lower(trim((string) $c)), str_getcsv($lineas[0], $separador, '"', ''));
        $faltantes = array_values(array_diff(self::COLUMNAS_OBLIGATORIAS, $encabezado));

        if ($faltantes !== []) {
            return ['errores' => ['Faltan columnas obligatorias en el encabezado: '.implode(', ', $faltantes).'. Descargue la plantilla y úsela tal cual.'], 'filas' => []];
        }

        $filas = [];

        foreach (array_slice($lineas, 1, null, true) as $indice => $linea) {
            if (trim($linea, " \t;,") === '') {
                continue;
            }

            $celdas = str_getcsv($linea, $separador, '"', '');
            $datos = [];

            foreach (self::COLUMNAS as $columna) {
                $posicion = array_search($columna, $encabezado, true);
                $datos[$columna] = $posicion === false ? '' : trim((string) ($celdas[$posicion] ?? ''));
            }

            $filas[] = ['linea' => $indice + 1, 'datos' => $datos];
        }

        if ($filas === []) {
            return ['errores' => ['El archivo no tiene filas de préstamos debajo del encabezado.'], 'filas' => []];
        }

        if (count($filas) > self::MAXIMO_FILAS) {
            return ['errores' => ['El archivo tiene '.count($filas).' filas; el máximo por carga es '.self::MAXIMO_FILAS.'. Divídalo en varios archivos.'], 'filas' => []];
        }

        return ['errores' => [], 'filas' => $filas];
    }

    /**
     * @param  array<string, string>  $datos
     * @param  Collection<string, Empleado>  $empleados
     * @param  Collection<string, TipoPrestamo>  $modalidades
     * @param  array<string, int>  $vistas
     * @return array<string, mixed>
     */
    private function analizarFila(int $linea, array $datos, Collection $empleados, Collection $modalidades, array &$vistas): array
    {
        $errores = [];
        $advertencias = [];

        $empleado = $empleados->get($datos['cedula']);

        if ($datos['cedula'] === '') {
            $errores[] = 'La cédula está vacía.';
        } elseif ($empleado === null) {
            $errores[] = "El empleado con cédula {$datos['cedula']} no existe.";
        } elseif ($empleado->tipo_vinculacion === TipoVinculacion::DocenteOcasional) {
            $errores[] = self::MENSAJE_DOCENTE_OCASIONAL;
        }

        $tipo = $modalidades->get($this->llave($datos['modalidad']));

        if ($tipo === null) {
            $errores[] = $datos['modalidad'] === '' ? 'La modalidad está vacía.' : "La modalidad «{$datos['modalidad']}» no existe. Use el nombre completo, como aparece en Modalidades.";
        }

        $monto = $this->leerMonto($datos['monto_inicial']);

        if ($monto === null || $monto <= 0) {
            $errores[] = 'El monto inicial no es válido: debe ser un valor mayor que cero con máximo dos decimales (ej. 3.000.000 o 3.000.000,50).';
            $monto = null;
        }

        $plazo = $this->leerEntero($datos['plazo_quincenas']);

        if ($plazo === null || $plazo < 1) {
            $errores[] = 'El plazo no es válido: debe ser un número entero de quincenas mayor que cero.';
            $plazo = null;
        } elseif ($tipo !== null && $plazo > (int) $tipo->plazo_maximo) {
            $errores[] = "El plazo ({$plazo} quincenas) es mayor que el máximo de la modalidad ({$tipo->plazo_maximo} quincenas).";
        }

        $fecha = $this->leerFecha($datos['fecha_desembolso']);

        if ($fecha === null) {
            $errores[] = 'La fecha de desembolso no es válida: use el formato AAAA-MM-DD (ej. 2026-03-15).';
        }

        $pagadas = $this->leerEntero($datos['cuotas_pagadas']);

        if ($pagadas === null || $pagadas < 0) {
            $errores[] = 'Las cuotas pagadas no son válidas: debe ser un número entero, cero o mayor.';
            $pagadas = null;
        } elseif ($plazo !== null && $pagadas > $plazo) {
            $errores[] = "Las cuotas pagadas ({$pagadas}) son más que el plazo ({$plazo} quincenas).";
        } elseif ($plazo !== null && $pagadas === $plazo) {
            $advertencias[] = 'Todas las cuotas están pagadas: el préstamo quedará Vigente con saldo cero.';
        }

        $tasaDada = $this->leerTasa($datos['tasa_anual'], 'La tasa anual', $errores);
        $puntos = $this->leerTasa($datos['puntos_adicionales'], 'Los puntos adicionales', $errores);

        $saldoDado = null;

        if ($datos['saldo_actual'] !== '') {
            $saldoDado = $this->leerMonto($datos['saldo_actual']);

            if ($saldoDado === null) {
                $errores[] = 'El saldo actual no es válido: debe ser un valor con máximo dos decimales (ej. 1.250.000,75).';
            }
        }

        if ($empleado !== null && $tipo !== null && $monto !== null && $fecha !== null) {
            $llave = $empleado->cedula.'|'.$tipo->id_tipo.'|'.number_format($monto, 2, '.', '').'|'.$fecha;

            if (isset($vistas[$llave])) {
                $errores[] = "Fila duplicada: es igual a la fila {$vistas[$llave]} del archivo (misma cédula, modalidad, monto y fecha).";
            } else {
                $vistas[$llave] = $linea;
            }

            if ($this->yaExiste($empleado->cedula, $tipo->id_tipo, $monto, $fecha)) {
                $errores[] = 'Ya existe un préstamo igual (misma cédula, modalidad, monto y fecha de desembolso).';
            }
        }

        if ($empleado !== null && $tipo !== null && $monto !== null && $fecha !== null) {
            $advertencias = [...$advertencias, ...$this->advertenciasDeCuantia($empleado, $tipo, $monto, $fecha)];

            if ($this->seDesembolsaPorTramos($tipo)) {
                $advertencias[] = 'Esta modalidad se desembolsa en dos tramos: se registra un solo desembolso por el 100 % y el detalle del préstamo seguirá ofreciendo registrar el tramo 2.';
            }
        }

        $calculo = null;

        if ($empleado !== null && $tipo !== null && $monto !== null && $plazo !== null && $fecha !== null && $pagadas !== null && $errores === []) {
            $tasa = $this->resolverTasa($empleado, $tipo, $plazo, $fecha, $tasaDada, $puntos, $errores, $advertencias);

            if ($tasa !== null) {
                $calculo = $this->calcular($empleado, $tipo, $monto, $plazo, $fecha, $pagadas, $tasa);

                if ($saldoDado !== null && abs($saldoDado - $calculo['saldo']) > self::TOLERANCIA_SALDO) {
                    $advertencias[] = 'El saldo actual del archivo ($'.$this->pesos($saldoDado).') difiere en más de 1 peso del calculado ($'.$this->pesos($calculo['saldo']).').';
                }
            }
        }

        if ($errores !== []) {
            $calculo = null;
        }

        return [
            'linea' => $linea,
            'datos' => $datos,
            'estado' => $errores !== [] ? 'error' : ($advertencias !== [] ? 'advertencia' : 'correcta'),
            'errores' => $errores,
            'advertencias' => $advertencias,
            'calculo' => $calculo,
        ];
    }

    /**
     * Tasa de la fila: la dada en el archivo o la del año del desembolso para la modalidad,
     * más los puntos adicionales. Con una tasa dada los puntos no se suman.
     *
     * @param  list<string>  $errores
     * @param  list<string>  $advertencias
     * @return ?array{tasa: float, id_tasa: ?int}
     */
    private function resolverTasa(Empleado $empleado, TipoPrestamo $tipo, int $plazo, string $fecha, ?float $tasaDada, ?float $puntos, array &$errores, array &$advertencias): ?array
    {
        $anio = CarbonImmutable::parse($fecha)->year;
        $esPrimera = ! Prestamo::where('cedula_empleado', $empleado->cedula)->where('id_tipo', $tipo->id_tipo)->exists();
        $detalle = $this->prestamoService->tasaDetallada($tipo, $anio, $plazo, $esPrimera, $fecha);

        $registro = TasaInt::where('id_tipo', $tipo->id_tipo)->where('anio', $anio)->first();

        if ($tasaDada !== null) {
            if ($puntos !== null && $puntos > 0) {
                $advertencias[] = 'Se indicó la tasa anual: se usa tal cual y los puntos adicionales no se suman.';
            }

            return ['tasa' => round($tasaDada, 4), 'id_tasa' => $detalle['id_tasa']];
        }

        if ($detalle['tasa'] === null) {
            $errores[] = "No hay una tasa de interés registrada para «{$tipo->nombre}» en el año {$anio} (año del desembolso). Regístrela en Modalidades o indique la tasa anual en el archivo.";

            return null;
        }

        if ($registro !== null && $registro->created_at !== null && CarbonImmutable::parse($fecha)->lt($registro->created_at->copy()->startOfDay())) {
            $advertencias[] = "La fecha de desembolso es anterior al registro de la tasa de {$anio} ({$registro->created_at->format('Y-m-d')}); se usa la tasa del año de desembolso.";
        }

        return ['tasa' => round($detalle['tasa'] + ($puntos ?? 0.0), 4), 'id_tasa' => $detalle['id_tasa']];
    }

    /**
     * @param  array{tasa: float, id_tasa: ?int}  $tasa
     * @return array<string, mixed>
     */
    private function calcular(Empleado $empleado, TipoPrestamo $tipo, float $monto, int $plazo, string $fecha, int $pagadas, array $tasa): array
    {
        $tasaPeriodica = $this->prestamoService->tasaPeriodica($tasa['tasa']);
        $cuota = $this->prestamoService->calcularCuotaFrancesa($monto, $tasaPeriodica, $plazo);
        $tabla = $this->calculoFinanciero->tablaAmortizacion($monto, $tasaPeriodica, $plazo, $cuota);

        return [
            'empleado' => $empleado,
            'tipo' => $tipo,
            'monto' => $monto,
            'plazo' => $plazo,
            'fecha' => $fecha,
            'pagadas' => $pagadas,
            'tasa' => $tasa['tasa'],
            'id_tasa' => $tasa['id_tasa'],
            'tasa_periodica' => $tasaPeriodica,
            'cuota' => $cuota,
            'saldo' => $pagadas === 0 ? round($monto, 2) : $tabla[$pagadas - 1]['saldo'],
            'primera_cuota' => $this->calendario->primeraCuotaConCierre($fecha),
        ];
    }

    /**
     * @param  array<string, mixed>  $calculo
     */
    private function crearPrestamo(array $calculo, string $lote): Prestamo
    {
        $prestamo = Prestamo::create([
            'id_solicitud' => null,
            'cedula_empleado' => $calculo['empleado']->cedula,
            'id_tipo' => $calculo['tipo']->id_tipo,
            'id_tasa' => $calculo['id_tasa'],
            'tasa_efectiva_anual' => $calculo['tasa'],
            'monto_aprobado' => $calculo['monto'],
            'num_cuotas' => $calculo['plazo'],
            'cuota_periodica' => $calculo['cuota'],
            'fecha_autorizacion' => $calculo['fecha'],
            'estado' => EstadoPrestamo::Vigente,
            'pendiente_desembolso' => false,
            'inicio_primera_cuota' => $calculo['primera_cuota'],
            'motivo_primera_cuota' => null,
            'origen' => Prestamo::ORIGEN_CARGA_INICIAL,
            'lote_carga' => $lote,
        ]);

        $prestamo->desembolsos()->create([
            'id_proveedor' => null,
            'numero_tramo' => 1,
            'porcentaje' => 100,
            'fecha' => $calculo['fecha'],
            'valor' => $calculo['monto'],
            'requiere_inspeccion' => false,
            'inspeccion_aprobada' => null,
            'estado' => EstadoDesembolso::Realizado,
        ]);

        $this->prestamoService->generarCuotasDesde($prestamo, $calculo['monto'], $calculo['tasa_periodica'], $calculo['cuota'], $calculo['plazo'], 1, $calculo['primera_cuota']);

        if ($calculo['pagadas'] > 0) {
            $prestamo->cuotas()->where('numero_cuota', '<=', $calculo['pagadas'])->update(['estado' => EstadoCuota::Pagada]);
        }

        return $prestamo;
    }

    /**
     * @return list<string>
     */
    private function advertenciasDeCuantia(Empleado $empleado, TipoPrestamo $tipo, float $monto, string $fecha): array
    {
        $smmlv = SmmlvAnual::valorParaAnio(CarbonImmutable::parse($fecha)->year);
        $salario = (float) $empleado->salario;

        if ($smmlv === null || $salario <= 0) {
            return [];
        }

        $tramo = $tipo->tramoCuantiaPara($salario / $smmlv);

        if ($tramo === null) {
            return [];
        }

        $maximo = round((float) $tramo->cuantia_maxima_smmlv * $smmlv, 2);

        return $monto > $maximo ? ['El monto es mayor que la cuantía máxima de la modalidad para el tramo salarial del empleado ($'.$this->pesos($maximo).').'] : [];
    }

    private function yaExiste(string $cedula, int $idTipo, float $monto, string $fecha): bool
    {
        return Prestamo::where('cedula_empleado', $cedula)
            ->where('id_tipo', $idTipo)
            ->where('monto_aprobado', number_format($monto, 2, '.', ''))
            ->where(function ($consulta) use ($fecha): void {
                $consulta->whereDate('fecha_autorizacion', $fecha)->orWhereHas('desembolsos', fn ($d) => $d->whereDate('fecha', $fecha));
            })
            ->exists();
    }

    private function seDesembolsaPorTramos(TipoPrestamo $tipo): bool
    {
        return in_array($tipo->nombre, ['Vivienda — construcción', 'Vivienda — reparaciones o mejoras'], true);
    }

    private function nuevoLote(): string
    {
        do {
            $lote = 'CARGA-'.now()->format('Ymd-His').'-'.Str::upper(Str::random(4));
        } while (Prestamo::where('lote_carga', $lote)->exists());

        return $lote;
    }

    private function llave(string $texto): string
    {
        return Str::lower(preg_replace('/\s+/', ' ', Str::ascii(trim($texto))) ?? '');
    }

    private function leerMonto(string $texto): ?float
    {
        $normalizado = MontoColombiano::normalizar(trim($texto));

        return preg_match('/^\d+(\.\d{1,2})?$/', $normalizado) === 1 ? (float) $normalizado : null;
    }

    private function leerEntero(string $texto): ?int
    {
        return preg_match('/^\d{1,6}$/', trim($texto)) === 1 ? (int) $texto : null;
    }

    private function leerFecha(string $texto): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $texto) !== 1) {
            return null;
        }

        $fecha = CarbonImmutable::createFromFormat('!Y-m-d', $texto);

        return $fecha !== false && $fecha->format('Y-m-d') === $texto && $fecha->year >= 1990 ? $texto : null;
    }

    /**
     * Tasa o puntos en porcentaje; acepta la coma decimal y el signo «%». Vacío es «no indicado».
     *
     * @param  list<string>  $errores
     */
    private function leerTasa(string $texto, string $nombre, array &$errores): ?float
    {
        $texto = trim(str_replace(['%', ' '], '', $texto));

        if ($texto === '') {
            return null;
        }

        $normalizado = self::normalizarTasaTexto($texto);

        if (preg_match('/^\d{1,3}(\.\d{1,4})?$/', $normalizado) !== 1 || (float) $normalizado > 100) {
            $errores[] = "{$nombre} no es válida: debe ser un porcentaje entre 0 y 100 con hasta 4 decimales (ej. 10,5).";

            return null;
        }

        return (float) $normalizado;
    }

    private function pesos(float $valor): string
    {
        return number_format($valor, 2, ',', '.');
    }

    /**
     * @param  list<array<string, mixed>>  $filas
     * @return array{total: int, correctas: int, advertencias: int, errores: int}
     */
    private function resumir(array $filas): array
    {
        $estados = array_count_values(array_column($filas, 'estado'));

        return [
            'total' => count($filas),
            'correctas' => $estados['correcta'] ?? 0,
            'advertencias' => $estados['advertencia'] ?? 0,
            'errores' => $estados['error'] ?? 0,
        ];
    }
}
