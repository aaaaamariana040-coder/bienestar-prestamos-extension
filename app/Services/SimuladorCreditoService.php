<?php

namespace App\Services;

use App\Enums\EstadoCuota;
use App\Enums\EstadoDesembolso;
use App\Enums\TipoVinculacion;
use App\Models\Empleado;
use App\Models\Prestamo;
use App\Models\SmmlvAnual;
use App\Models\SolicitudCredito;
use App\Models\TipoPrestamo;
use DateTimeInterface;

/**
 * Simulador de crédito: calcula sin guardar nada. Reutiliza la resolución de
 * tasa de PrestamoService (incluida la de vehículo) y las fórmulas de
 * CalculoFinancieroService, de modo que la cuota simulada es la misma que
 * tendría el préstamo real.
 */
class SimuladorCreditoService
{
    /**
     * Monto mínimo de una solicitud: el mismo `min` de StoreSolicitudCreditoRequest.
     */
    public const MONTO_MINIMO_SOLICITUD = 1000;

    public function __construct(
        private readonly PrestamoService $prestamoService,
        private readonly CalculoFinancieroService $calculoFinanciero,
        private readonly SolicitudCreditoService $solicitudCreditoService,
        private readonly TasaVehiculoService $tasaVehiculoService,
        private readonly PrimaSeguroService $primaSeguroService,
        private readonly CapacidadEndeudamientoService $capacidadService,
        private readonly CalendarioQuincenalService $calendario,
    ) {
        //
    }

    /**
     * Datos del empleado para la pantalla del simulador: estado, préstamos
     * vigentes, capacidad de endeudamiento y modalidades habilitadas.
     *
     * @return array<string, mixed>
     */
    public function consultarEmpleado(string $cedula): array
    {
        $empleado = Empleado::find($cedula);

        if ($empleado === null) {
            return ['encontrado' => false, 'mensaje' => "No existe un empleado con la cédula {$cedula}."];
        }

        $datos = [
            'encontrado' => true,
            'cedula' => $empleado->cedula,
            'nombre_completo' => $empleado->nombre_completo,
            'salario' => (float) $empleado->salario,
            'estado' => $empleado->estado,
            'elegible' => $empleado->estado === 'Activo',
        ];

        if (! $datos['elegible']) {
            return $datos + [
                'mensaje' => 'El empleado está retirado y no puede solicitar un crédito.',
                'prestamos' => [],
                'capacidad' => null,
                'modalidades' => [],
            ];
        }

        return $datos + [
            'mensaje' => null,
            'prestamos' => $this->prestamosVigentes($empleado),
            'capacidad' => $this->capacidadService->paraEmpleado($empleado),
            'modalidades' => TipoPrestamo::where('habilitada', true)->orderBy('nombre')->get(['id_tipo', 'nombre'])->all(),
        ];
    }

    /**
     * ¿Puede el empleado pedir un crédito de esta modalidad? Si puede, devuelve
     * el monto máximo, y si no, el motivo. Reutiliza las verificaciones de
     * SolicitudCreditoService::incumplimientos() con una solicitud sin guardar.
     *
     * @return array<string, mixed>
     */
    public function evaluarModalidad(Empleado $empleado, TipoPrestamo $modalidad): array
    {
        if (! $modalidad->habilitada) {
            return $this->noPuede('modalidad_deshabilitada', 'La modalidad está deshabilitada.');
        }

        $anio = now()->year;
        $plazo = (int) $modalidad->plazo_maximo;
        $solicitud = $this->solicitudHipotetica($empleado, $modalidad, 1, $plazo);

        // Con un monto simbólico solo quedan los faltantes de configuración; la capacidad se evalúa aparte.
        $estructurales = array_values(array_filter(
            $this->solicitudCreditoService->incumplimientos($solicitud),
            fn (array $incumplimiento): bool => $incumplimiento['codigo'] !== 'capacidad_excedida',
        ));

        if ($estructurales !== []) {
            return $this->noPuedeDesde($estructurales);
        }

        $detalleTasa = $this->prestamoService->resolverTasaDetallada($solicitud, $modalidad, $anio);
        $tasaAnual = $detalleTasa['tasa'];
        $tasaPeriodica = $this->calculoFinanciero->tasaPeriodicaQuincenal($tasaAnual);
        $capacidad = $this->capacidadService->paraEmpleado($empleado, $anio);
        $esVivienda = $modalidad->esVivienda();

        if ($capacidad['disponible'] <= 0) {
            return $this->noPuede('capacidad_agotada', 'La capacidad de endeudamiento está agotada: las cuotas vigentes ya la comprometen por completo.');
        }

        $montoPorCapacidad = $this->montoMaximoPorCapacidad($capacidad['disponible'], $tasaPeriodica, $plazo, $anio, $esVivienda);

        if ($montoPorCapacidad < self::MONTO_MINIMO_SOLICITUD) {
            return $this->noPuede('capacidad_insuficiente', 'La capacidad de endeudamiento disponible ($'.number_format($capacidad['disponible'], 0, ',', '.').') no alcanza para el monto mínimo de una solicitud ($'.number_format(self::MONTO_MINIMO_SOLICITUD, 0, ',', '.').').');
        }

        $smmlv = SmmlvAnual::valorParaAnio($anio);
        $tramo = $modalidad->tramoCuantiaPara((float) $empleado->salario / $smmlv);
        $cuantiaMaxima = floor((float) $tramo->cuantia_maxima_smmlv * $smmlv);
        $montoMaximo = min($cuantiaMaxima, $montoPorCapacidad);

        $finales = $this->solicitudCreditoService->incumplimientos(
            $this->solicitudHipotetica($empleado, $modalidad, $montoMaximo, $plazo),
        );

        if ($finales !== []) {
            return $this->noPuedeDesde($finales);
        }

        return [
            'puede' => true,
            'motivo' => null,
            'mensaje' => null,
            'mensajes' => [],
            'monto_maximo' => $montoMaximo,
            'limitado_por' => $montoPorCapacidad < $cuantiaMaxima ? 'capacidad' : 'cuantia',
            'plazo_quincenas' => $plazo,
            'plazo_legible' => $modalidad->plazo_maximo_legible,
            'tasa_efectiva_anual' => $tasaAnual,
            'tasa_registrada' => $detalleTasa['registrada'],
            'tasa_limitada' => $detalleTasa['limitada'],
            'nota_tasa' => $detalleTasa['limitada'] ? $this->prestamoService->notaTopeVivienda($detalleTasa['tope'], $detalleTasa['registrada']) : null,
            'cuota_quincenal' => $this->calculoFinanciero->cuotaFija($montoMaximo, $tasaPeriodica, $plazo),
        ];
    }

    /**
     * Monto más alto cuya cuota (más los seguros, en vivienda) cabe en la
     * capacidad disponible, con la misma comparación de la aprobación.
     */
    private function montoMaximoPorCapacidad(float $disponible, float $tasaPeriodica, int $plazo, int $anio, bool $esVivienda): float
    {
        $estimacion = $this->primaSeguroService->estimar(1.0, $anio, $esVivienda);
        $tasaSeguro = $esVivienda ? $estimacion['tasa_vida'] + $estimacion['tasa_incendio'] : 0.0;
        $montoPorUnidadDeCuota = $this->calculoFinanciero->montoMaximoParaCuota(1.0, $tasaPeriodica, $plazo);
        $cuotaDisponible = ($disponible / 2) / (1 + $tasaSeguro * $montoPorUnidadDeCuota);

        $monto = floor($this->calculoFinanciero->montoMaximoParaCuota($cuotaDisponible, $tasaPeriodica, $plazo));

        // La cuota y los seguros se redondean a centavos: se baja el monto hasta que quepa.
        while ($monto > 0 && $this->capacidadService->cuotaAComparar($monto, $this->calculoFinanciero->cuotaFija($monto, $tasaPeriodica, $plazo), $anio, $esVivienda)['cuota_mensual'] > $disponible) {
            $monto--;
        }

        return $monto;
    }

    private function solicitudHipotetica(Empleado $empleado, TipoPrestamo $modalidad, float $monto, int $plazo): SolicitudCredito
    {
        $solicitud = new SolicitudCredito([
            'cedula_empleado' => $empleado->cedula,
            'id_tipo' => $modalidad->id_tipo,
            'monto_solicitado' => $monto,
            'plazo_solicitado' => $plazo,
            'fecha_solicitud' => now()->toDateString(),
        ]);

        return $solicitud->setRelation('empleado', $empleado)->setRelation('tipoPrestamo', $modalidad);
    }

    /**
     * El motivo principal sale del primer incumplimiento según este orden.
     *
     * @param  array<int, array{codigo: string, mensaje: string}>  $incumplimientos
     * @return array<string, mixed>
     */
    private function noPuedeDesde(array $incumplimientos): array
    {
        $prioridad = ['empleado_inactivo', 'tipo_vinculacion', 'antiguedad_insuficiente', 'sin_smmlv', 'salario_minimo', 'sin_tasa', 'sin_cuantia', 'plazo_excedido', 'sin_presupuesto', 'cuantia_excedida', 'capacidad_no_calculable', 'capacidad_excedida'];

        usort($incumplimientos, fn (array $a, array $b): int => array_search($a['codigo'], $prioridad, true) <=> array_search($b['codigo'], $prioridad, true));

        $mensajes = array_column($incumplimientos, 'mensaje');

        if ($incumplimientos[0]['codigo'] === 'antiguedad_insuficiente') {
            $mensajes[0] = 'No cumple el tiempo de servicio exigido. '.$mensajes[0];
        }

        return $this->noPuede($incumplimientos[0]['codigo'], $mensajes[0], $mensajes);
    }

    /**
     * @param  list<string>  $mensajes
     * @return array<string, mixed>
     */
    private function noPuede(string $motivo, string $mensaje, array $mensajes = []): array
    {
        return [
            'puede' => false,
            'motivo' => $motivo,
            'mensaje' => $mensaje,
            'mensajes' => $mensajes === [] ? [$mensaje] : $mensajes,
            'monto_maximo' => null,
            'limitado_por' => null,
            'plazo_quincenas' => null,
            'plazo_legible' => null,
            'tasa_efectiva_anual' => null,
            'tasa_registrada' => null,
            'tasa_limitada' => false,
            'nota_tasa' => null,
            'cuota_quincenal' => null,
        ];
    }

    /**
     * 5 → «5»; 6,5 → «6,5»; 4,8512 → «4,8512», sin ceros de más.
     */
    private function porcentajeSinCeros(float $valor): string
    {
        return str_replace('.', ',', rtrim(rtrim(number_format($valor, 4, '.', ''), '0'), '.'));
    }

    /**
     * @return list<array{modalidad: string, monto_desembolsado: float, saldo_pendiente: float, cuota_quincenal: float, progreso_cuotas: string}>
     */
    private function prestamosVigentes(Empleado $empleado): array
    {
        return $empleado->prestamosActivos()->with('tipoPrestamo')->get()->map(fn (Prestamo $prestamo): array => [
            'modalidad' => $prestamo->tipoPrestamo->nombre,
            'monto_desembolsado' => (float) $prestamo->desembolsos()->where('estado', EstadoDesembolso::Realizado)->sum('valor'),
            'saldo_pendiente' => (float) $prestamo->cuotas()->where('estado', '!=', EstadoCuota::Pagada)->sum('valor_capital'),
            'cuota_quincenal' => (float) $prestamo->cuota_periodica,
            'progreso_cuotas' => $prestamo->progreso_cuotas,
        ])->all();
    }

    /**
     * Tasa oficial que tendría una solicitud de este empleado: base (tasas_int
     * del año) y puntos adicionales (solo vehículo, según el plazo en años
     * completos y si es su primera solicitud de la modalidad). Es lo que
     * prellena el simulador; el operador puede cambiarla.
     *
     * En vivienda la base ya viene limitada al tope del Acuerdo 11 de 2023: `registrada`
     * es el IPC tal cual está en tasas_int, `limitada` indica si se recortó y `nota`
     * es el texto que lo explica.
     *
     * @return array{base: ?float, puntos: float, primera_solicitud: bool, avisos: list<string>, registrada: ?float, limitada: bool, tope: ?float, nota: ?string, nota_tramo: ?string}
     */
    public function tasaOficial(Empleado $empleado, TipoPrestamo $modalidad, int $anio, int $plazoQuincenas, DateTimeInterface|string|null $fechaSolicitud = null): array
    {
        $esPrimeraSolicitud = ! Prestamo::where('cedula_empleado', $empleado->cedula)
            ->where('id_tipo', $modalidad->id_tipo)
            ->exists();
        $avisos = [];

        if (! $modalidad->esVehiculo()) {
            $detalle = $this->prestamoService->tasaDetallada($modalidad, $anio, $plazoQuincenas, $esPrimeraSolicitud, $fechaSolicitud ?? now());

            return [
                'base' => $detalle['tasa'],
                'puntos' => 0.0,
                'primera_solicitud' => $esPrimeraSolicitud,
                'avisos' => [],
                'registrada' => $detalle['registrada'],
                'limitada' => $detalle['limitada'],
                'tope' => $detalle['tope'],
                'nota' => $detalle['limitada'] ? $this->prestamoService->notaTopeVivienda($detalle['tope'], $detalle['registrada']) : null,
                'nota_tramo' => null,
            ];
        }

        $plazo = max($plazoQuincenas, 0);
        $componentes = $this->tasaVehiculoService->componentes($anio, $plazo, $esPrimeraSolicitud);
        $tramo = $componentes['tramo'] ?? null;
        $tipoSolicitud = $esPrimeraSolicitud ? 'primera solicitud' : 'segunda solicitud o posterior';

        if ($plazo > TasaVehiculoService::PLAZO_MAXIMO_QUINCENAS) {
            $avisos[] = $this->tasaVehiculoService->mensajePlazoExcedido();
        } elseif ($componentes !== null && $tramo === null) {
            $avisos[] = "No hay puntos adicionales registrados para vehículo ({$tipoSolicitud}); se muestran 0 puntos. Si corresponden, digítalos a mano.";
        }

        return [
            'base' => $componentes['base'] ?? null,
            'puntos' => (float) ($componentes['puntos'] ?? 0),
            'primera_solicitud' => $esPrimeraSolicitud,
            'avisos' => $avisos,
            'registrada' => $componentes['base'] ?? null,
            'limitada' => false,
            'tope' => null,
            'nota' => null,
            'nota_tramo' => $tramo === null ? null : $this->tasaVehiculoService->notaDelTramo($tramo, $plazo),
        ];
    }

    /**
     * Plazo más alto que se puede simular: el máximo de la modalidad, sin pasar
     * del límite técnico del simulador.
     */
    public function plazoMaximoSimulable(TipoPrestamo $modalidad): int
    {
        return min((int) $modalidad->plazo_maximo, (int) config('prestamos.simulador.plazo_tecnico_maximo'));
    }

    /**
     * Simula un crédito sin guardar nada. La tasa y los puntos que llegan nulos
     * se toman de la configuración oficial; si llegan, mandan (el operador los
     * editó). Con errores solo vienen `errores` y `avisos`.
     *
     * @return array<string, mixed>
     */
    public function simular(
        Empleado $empleado,
        TipoPrestamo $modalidad,
        float $monto,
        int $plazoQuincenas,
        ?float $tasaEfectivaBase = null,
        ?float $puntos = null,
        float $retencion = 0.0,
        ?int $anio = null,
        ?string $fechaDesembolso = null,
    ): array {
        $anio ??= now()->year;
        $salario = (float) $empleado->salario;
        $smmlv = SmmlvAnual::valorParaAnio($anio);
        $plazoMaximo = $this->plazoMaximoSimulable($modalidad);
        $oficial = $this->tasaOficial($empleado, $modalidad, $anio, $plazoQuincenas);

        $errores = $this->validarEntradas($empleado, $modalidad, $monto, $plazoQuincenas, $salario, $smmlv, $anio, $plazoMaximo);

        $base = $tasaEfectivaBase ?? $oficial['base'];

        if ($base === null && $errores === []) {
            $errores[] = "No hay una tasa de interés registrada para {$modalidad->nombre} en {$anio}. Digita la tasa para simular.";
        }

        $puntosUsados = $puntos ?? $oficial['puntos'];
        $editada = $base !== null && ($oficial['base'] === null
            || abs($base - $oficial['base']) > 1e-9
            || abs($puntosUsados - $oficial['puntos']) > 1e-9);

        // La tasa oficial ya viene limitada: solo una corrección manual puede pasar el tope.
        $tope = $this->prestamoService->topeTasaVivienda($modalidad, now());

        if ($editada && $tope !== null && round($base + $puntosUsados, 4) > $tope + 1e-9) {
            $errores[] = 'En vivienda la tasa no puede superar el '.$this->porcentajeSinCeros($tope).' % (Acuerdo 11 de 2023): escribiste '.$this->porcentajeSinCeros($base + $puntosUsados).' % entre la tasa y los puntos. Corrígela o usa la tasa oficial.';
        }

        if ($errores !== []) {
            return ['errores' => $errores, 'avisos' => []];
        }

        $tasaTotal = round($base + $puntosUsados, 4);

        $tasaPeriodica = $this->calculoFinanciero->tasaPeriodicaQuincenal($tasaTotal);
        $tabla = $this->calculoFinanciero->tablaAmortizacion($monto, $tasaPeriodica, $plazoQuincenas);
        $cuota = $this->calculoFinanciero->cuotaFija($monto, $tasaPeriodica, $plazoQuincenas);
        $estimacionSeguro = $this->primaSeguroService->estimar($monto, $anio, $modalidad->esVivienda());
        $seguro = $estimacionSeguro['total'];

        $tablaConFechas = $this->conFechasEstimadas($tabla, $plazoQuincenas, $fechaDesembolso);
        $capacidad = $this->capacidad($empleado, $monto, $cuota, $anio, $modalidad->esVivienda(), $retencion);
        $tramo = $modalidad->tramoCuantiaPara($salario / $smmlv);
        $cuantiaMaxima = $tramo !== null ? floor((float) $tramo->cuantia_maxima_smmlv * $smmlv) : null;

        return [
            'errores' => [],
            'avisos' => [...$oficial['avisos'], ...$this->avisosInformativos($empleado, $modalidad, $monto, $plazoQuincenas, $oficial['base'] === null)],
            'encabezado' => [
                'modalidad' => $modalidad->nombre,
                'monto' => $monto,
                'tasa_efectiva_anual' => $base,
                'puntos' => $puntosUsados,
                'tasa_total' => $tasaTotal,
                'tasa_nominal_anual' => $this->calculoFinanciero->tasaNominalDesdePeriodica($tasaPeriodica),
                'tasa_periodica_quincenal' => $tasaPeriodica * 100,
                'periodos' => $plazoQuincenas,
                'plazo_legible' => TipoPrestamo::plazoEnQuincenas($plazoQuincenas),
                'plazo_maximo_simulable' => $plazoMaximo,
            ],
            'tasa' => [
                'editada' => $editada,
                'oficial_base' => $oficial['base'],
                'oficial_puntos' => $oficial['puntos'],
                'oficial_total' => $oficial['base'] === null ? null : round($oficial['base'] + $oficial['puntos'], 4),
                'registrada' => $oficial['registrada'],
                'limitada' => $oficial['limitada'] && ! $editada,
                'nota' => $editada ? null : $oficial['nota'],
                'nota_tramo' => $editada ? null : $oficial['nota_tramo'],
            ],
            'cuota_quincenal' => $cuota,
            'seguro' => $seguro,
            'seguro_detalle' => [...$estimacionSeguro, 'es_vivienda' => $modalidad->esVivienda()],
            'cuota_mas_seguro' => round($cuota + $seguro, 2),
            'total_intereses' => round(array_sum(array_column($tabla, 'interes')), 2),
            'total_a_pagar' => round(array_sum(array_column($tabla, 'cuota')), 2),
            'tabla_amortizacion' => $tablaConFechas,
            'primera_cuota' => [
                'fecha_desembolso' => $fechaDesembolso,
                'fecha' => $tablaConFechas[0]['fecha'] ?? null,
            ],
            'capacidad' => $capacidad,
            'sugerencias' => $this->sugerencias($capacidad['disponible'], $monto, $tasaPeriodica, $plazoQuincenas, $plazoMaximo, $cuantiaMaxima),
        ];
    }

    /**
     * Las dos capacidades —la calculada con descuentos de ley (clave `estimada`) y
     * la guardada del empleado— y el veredicto de la cuota mensual contra cada una.
     * Decide la que fija capacidad.fuente, igual que la aprobación; a las dos se
     * les resta lo comprometido en préstamos vigentes. En vivienda la cuota
     * incluye los seguros estimados.
     *
     * @return array<string, mixed>
     */
    private function capacidad(Empleado $empleado, float $monto, float $cuotaQuincenal, int $anio, bool $esVivienda, float $retencion): array
    {
        $base = $this->capacidadService->paraEmpleado($empleado, $anio, $retencion);
        $cuota = $this->capacidadService->cuotaAComparar($monto, $cuotaQuincenal, $anio, $esVivienda);
        $cuotaMensual = $cuota['cuota_mensual'];

        $estimada = $base['calculada'];
        $estimada['cabe'] = $cuotaMensual <= $estimada['disponible'];

        $guardada = $base['guardada'];
        $guardada['cabe'] = $cuotaMensual <= $guardada['disponible'];

        $usaEstimada = $base['fuente'] === CapacidadEndeudamientoService::FUENTE_CALCULADA;

        return [
            'restar_descuentos_ley' => $usaEstimada,
            'usada' => $usaEstimada ? 'estimada' : 'guardada',
            'comprometida' => $base['comprometida'],
            'estimada' => $estimada,
            'guardada' => $guardada,
            'cuota_mensual' => $cuotaMensual,
            'cuota_sin_seguro_mensual' => $cuota['cuota_sin_seguro_mensual'],
            'seguro_mensual' => $cuota['seguro_mensual'],
            'disponible' => $base['disponible'],
            'cabe' => $usaEstimada ? $estimada['cabe'] : $guardada['cabe'],
            'margen' => round($base['disponible'] - $cuotaMensual, 2),
            'veredictos_distintos' => $estimada['cabe'] !== $guardada['cabe'],
        ];
    }

    /**
     * Cuantía máxima del tramo, antigüedad y presupuesto, con las verificaciones
     * de la solicitud real salvo la capacidad (que se evalúa aparte), el plazo
     * (ya validado) y lo que el simulador ya reporta como error o aviso propio.
     *
     * @return list<string>
     */
    private function avisosInformativos(Empleado $empleado, TipoPrestamo $modalidad, float $monto, int $plazoQuincenas, bool $sinTasaOficial): array
    {
        $omitidos = ['capacidad_excedida', 'plazo_excedido', 'sin_smmlv', 'empleado_inactivo'];
        $avisos = [];

        foreach ($this->solicitudCreditoService->incumplimientos($this->solicitudHipotetica($empleado, $modalidad, $monto, $plazoQuincenas)) as $incumplimiento) {
            if (in_array($incumplimiento['codigo'], $omitidos, true)) {
                continue;
            }

            if ($incumplimiento['codigo'] === 'sin_tasa') {
                if ($sinTasaOficial) {
                    $avisos[] = 'No hay tasa oficial registrada para esta modalidad y año: la solicitud real no podrá aprobarse hasta registrarla.';
                }

                continue;
            }

            $avisos[] = $incumplimiento['mensaje'];
        }

        return $avisos;
    }

    /**
     * Sugerencias con la capacidad disponible que decidió el veredicto: el
     * plazo mínimo para que quepa el monto pedido y el monto máximo que cabe
     * con el plazo elegido. Ambas respetan el plazo máximo simulable.
     *
     * @return array<string, mixed>
     */
    private function sugerencias(float $disponible, float $monto, float $tasaPeriodica, int $plazoQuincenas, int $plazoMaximo, ?float $cuantiaMaxima): array
    {
        $cuotaMaxima = round($disponible / 2, 2);

        return [
            'cuota_maxima_quincenal' => max($cuotaMaxima, 0.0),
            'plazo_minimo' => $this->plazoMinimo($disponible, $cuotaMaxima, $monto, $tasaPeriodica, $plazoMaximo),
            'monto_maximo' => $this->montoMaximo($disponible, $cuotaMaxima, $tasaPeriodica, $plazoQuincenas, $cuantiaMaxima),
        ];
    }

    /**
     * @return array{posible: bool, quincenas: ?int, cuota_quincenal: ?float, motivo: ?string}
     */
    private function plazoMinimo(float $disponible, float $cuotaMaxima, float $monto, float $tasaPeriodica, int $plazoMaximo): array
    {
        $sinPlazo = fn (string $motivo, ?int $quincenas = null): array => ['posible' => false, 'quincenas' => $quincenas, 'cuota_quincenal' => null, 'motivo' => $motivo];

        if ($cuotaMaxima <= 0) {
            return $sinPlazo('No hay capacidad de endeudamiento disponible: ningún plazo sirve.');
        }

        $interes = round($monto * $tasaPeriodica, 2);

        if ($cuotaMaxima <= $interes) {
            return $sinPlazo('La cuota máxima que cabe ($'.$this->pesos($cuotaMaxima).' por quincena) no cubre ni el interés de la primera quincena ($'.$this->pesos($interes).'): ningún plazo sirve para este monto.');
        }

        $periodos = $this->calculoFinanciero->numPeriodosParaCuota($monto, $tasaPeriodica, $cuotaMaxima);

        // La cuota se redondea a centavos: se sube el plazo hasta que quepa de verdad.
        for ($intentos = 0; $intentos < 50 && round($this->calculoFinanciero->cuotaFija($monto, $tasaPeriodica, $periodos) * 2, 2) > $disponible; $intentos++) {
            $periodos++;
        }

        if ($periodos > $plazoMaximo) {
            return $sinPlazo("Aun con el plazo máximo ({$plazoMaximo} quincenas) la cuota no cabe: se necesitarían {$periodos} quincenas.", $periodos);
        }

        return [
            'posible' => true,
            'quincenas' => $periodos,
            'cuota_quincenal' => $this->calculoFinanciero->cuotaFija($monto, $tasaPeriodica, $periodos),
            'motivo' => null,
        ];
    }

    /**
     * @return array{posible: bool, monto: ?float, cuota_quincenal: ?float, plazo_quincenas: int, cuantia_maxima: ?float, motivo: ?string}
     */
    private function montoMaximo(float $disponible, float $cuotaMaxima, float $tasaPeriodica, int $plazoQuincenas, ?float $cuantiaMaxima): array
    {
        $resultado = ['posible' => false, 'monto' => null, 'cuota_quincenal' => null, 'plazo_quincenas' => $plazoQuincenas, 'cuantia_maxima' => $cuantiaMaxima, 'motivo' => null];

        if ($cuotaMaxima <= 0) {
            return [...$resultado, 'motivo' => 'No hay capacidad de endeudamiento disponible.'];
        }

        $monto = floor($this->calculoFinanciero->montoMaximoParaCuota($cuotaMaxima, $tasaPeriodica, $plazoQuincenas));

        for ($intentos = 0; $intentos < 1000 && $monto > 0 && round($this->calculoFinanciero->cuotaFija($monto, $tasaPeriodica, $plazoQuincenas) * 2, 2) > $disponible; $intentos++) {
            $monto--;
        }

        if ($monto < self::MONTO_MINIMO_SOLICITUD) {
            return [...$resultado, 'motivo' => 'La capacidad disponible no alcanza para el monto mínimo de una solicitud ($'.$this->pesos(self::MONTO_MINIMO_SOLICITUD).') con este plazo.'];
        }

        return [...$resultado, 'posible' => true, 'monto' => $monto, 'cuota_quincenal' => $this->calculoFinanciero->cuotaFija($monto, $tasaPeriodica, $plazoQuincenas)];
    }

    /**
     * Agrega la fecha estimada de cada cuota con el mismo generador de fechas
     * que usa PrestamoService al crear las cuotas reales, como si el préstamo
     * se autorizara hoy.
     *
     * @param  list<array{numero: int, interes: float, capital: float, cuota: float, saldo: float}>  $tabla
     * @return list<array{numero: int, fecha: string, interes: float, capital: float, cuota: float, saldo: float}>
     */
    private function conFechasEstimadas(array $tabla, int $plazoQuincenas, ?string $fechaDesembolso = null): array
    {
        // Con fecha de desembolso: la misma regla del desembolso real (vencimiento posterior y cierre de novedades).
        $primera = $fechaDesembolso !== null
            ? $this->calendario->primeraCuotaConCierre($fechaDesembolso)
            : $this->prestamoService->fechaPrimeraCuota(now()->toDateString());

        $fechas = $this->prestamoService->fechasDeVencimiento($primera, $plazoQuincenas);

        return array_map(fn (array $fila, string $fecha): array => ['numero' => $fila['numero'], 'fecha' => $fecha] + $fila, $tabla, $fechas);
    }

    /**
     * @return list<string>
     */
    private function validarEntradas(Empleado $empleado, TipoPrestamo $modalidad, float $monto, int $plazoQuincenas, float $salario, ?float $smmlv, int $anio, int $plazoMaximo): array
    {
        $errores = [];

        if ($empleado->estado !== 'Activo') {
            $errores[] = 'El empleado está retirado y no puede solicitar un crédito.';
        }

        if ($empleado->tipo_vinculacion === TipoVinculacion::DocenteOcasional) {
            $errores[] = SolicitudCreditoService::MENSAJE_DOCENTE_OCASIONAL;
        }

        if (! $modalidad->habilitada) {
            $errores[] = 'La modalidad está deshabilitada.';
        }

        if ($monto < self::MONTO_MINIMO_SOLICITUD) {
            $errores[] = 'El monto debe ser de al menos $'.$this->pesos(self::MONTO_MINIMO_SOLICITUD).'.';
        }

        if ($salario <= 0) {
            $errores[] = 'El salario del empleado debe ser mayor que cero.';
        }

        if ($plazoQuincenas < 1) {
            $errores[] = 'El plazo debe ser de al menos 1 quincena.';
        } elseif ($plazoQuincenas > (int) $modalidad->plazo_maximo) {
            $errores[] = "El plazo ({$plazoQuincenas} quincenas) supera el máximo de la modalidad {$modalidad->nombre}: {$modalidad->plazo_maximo} quincenas.";
        } elseif ($plazoQuincenas > $plazoMaximo) {
            $errores[] = "El plazo ({$plazoQuincenas} quincenas) supera el límite técnico del simulador: {$plazoMaximo} quincenas.";
        }

        if ($smmlv === null) {
            $errores[] = "No se ha registrado el valor del SMMLV para el año {$anio}.";
        } elseif ($this->solicitudCreditoService->devengaElMinimo($empleado, $smmlv)) {
            $errores[] = SolicitudCreditoService::MENSAJE_SALARIO_MINIMO;
        }

        return $errores;
    }

    private function pesos(float $valor): string
    {
        return number_format($valor, 0, ',', '.');
    }
}
