<?php

namespace App\Services;

use App\Enums\EstadoCuota;
use App\Enums\EstadoDesembolso;
use App\Enums\EstadoPrestamo;
use App\Models\Empleado;
use App\Models\Prestamo;
use App\Models\SmmlvAnual;
use App\Models\SolicitudCredito;
use App\Models\TipoPrestamo;
use Illuminate\Database\Eloquent\Collection;

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
        private readonly EmpleadoService $empleadoService,
        private readonly SolicitudCreditoService $solicitudCreditoService,
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
            'capacidad' => $this->capacidad($empleado),
            'modalidades' => TipoPrestamo::where('habilitada', true)->orderBy('nombre')->get(['id_tipo', 'nombre'])->all(),
        ];
    }

    /**
     * Capacidad de endeudamiento: el total es el guardado en el empleado (la
     * misma cifra que usa SolicitudCreditoService); lo comprometido son las
     * cuotas vigentes, dos quincenas por mes, igual que esa validación.
     *
     * @return array{total: float, comprometida: float, disponible: float}
     */
    public function capacidad(Empleado $empleado): array
    {
        $total = (float) $empleado->capacidad_endeudamiento;
        $cuotasVigentes = (float) $this->prestamosActivos($empleado)->sum('cuota_periodica');
        $comprometida = round($cuotasVigentes * 2, 2);

        return [
            'total' => $total,
            'comprometida' => $comprometida,
            'disponible' => round($total - $comprometida, 2),
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

        [, $tasaAnual] = $this->prestamoService->resolverTasa($solicitud, $modalidad, $anio);
        $tasaPeriodica = $this->calculoFinanciero->tasaPeriodicaQuincenal($tasaAnual);
        $capacidad = $this->capacidad($empleado);

        if ($capacidad['disponible'] <= 0) {
            return $this->noPuede('capacidad_agotada', 'La capacidad de endeudamiento está agotada: las cuotas vigentes ya la comprometen por completo.');
        }

        $montoPorCapacidad = floor($this->calculoFinanciero->montoMaximoParaCuota($capacidad['disponible'] / 2, $tasaPeriodica, $plazo));

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
            'cuota_quincenal' => $this->calculoFinanciero->cuotaFija($montoMaximo, $tasaPeriodica, $plazo),
        ];
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
        $prioridad = ['empleado_inactivo', 'sin_smmlv', 'sin_tasa', 'sin_cuantia', 'plazo_excedido', 'sin_presupuesto', 'cuantia_excedida', 'capacidad_excedida'];

        usort($incumplimientos, fn (array $a, array $b): int => array_search($a['codigo'], $prioridad, true) <=> array_search($b['codigo'], $prioridad, true));

        return $this->noPuede($incumplimientos[0]['codigo'], $incumplimientos[0]['mensaje'], array_column($incumplimientos, 'mensaje'));
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
            'cuota_quincenal' => null,
        ];
    }

    /**
     * @return Collection<int, Prestamo>
     */
    private function prestamosActivos(Empleado $empleado): Collection
    {
        return $empleado->prestamos()
            ->whereIn('estado', [EstadoPrestamo::Vigente, EstadoPrestamo::EnMora])
            ->with('tipoPrestamo')
            ->get();
    }

    /**
     * @return list<array{modalidad: string, monto_desembolsado: float, saldo_pendiente: float, cuota_quincenal: float}>
     */
    private function prestamosVigentes(Empleado $empleado): array
    {
        return $this->prestamosActivos($empleado)->map(fn (Prestamo $prestamo): array => [
            'modalidad' => $prestamo->tipoPrestamo->nombre,
            'monto_desembolsado' => (float) $prestamo->desembolsos()->where('estado', EstadoDesembolso::Realizado)->sum('valor'),
            'saldo_pendiente' => (float) $prestamo->cuotas()->where('estado', '!=', EstadoCuota::Pagada)->sum('valor_capital'),
            'cuota_quincenal' => (float) $prestamo->cuota_periodica,
        ])->all();
    }

    /**
     * Si hay errores de validación, solo viene `errores` con datos; el resto
     * de las claves quedan en null (o vacías, en la tabla).
     *
     * @return array{
     *     errores: list<string>,
     *     tasa_efectiva_anual: ?float,
     *     tasa_nominal_anual: ?float,
     *     cuota_quincenal: ?float,
     *     total_intereses: ?float,
     *     total_a_pagar: ?float,
     *     tabla_amortizacion: list<array{numero: int, interes: float, capital: float, cuota: float, saldo: float}>,
     *     capacidad_endeudamiento: ?float,
     *     margen_capacidad: ?float,
     * }
     */
    public function simular(
        TipoPrestamo $modalidad,
        float $monto,
        int $plazoQuincenas,
        float $salario,
        int $anioSolicitud,
        bool $esPrimeraSolicitud = true,
    ): array {
        $errores = $this->validarEntradas($modalidad, $monto, $plazoQuincenas, $salario, $anioSolicitud);

        [, $tasaEfectivaAnual] = $this->prestamoService->resolverTasaPara($modalidad, $anioSolicitud, $plazoQuincenas, $esPrimeraSolicitud);

        if ($tasaEfectivaAnual === null) {
            $errores[] = "No hay una tasa de interés registrada para {$modalidad->nombre} en {$anioSolicitud}.";
        }

        if ($errores !== []) {
            return $this->resultadoConErrores($errores);
        }

        $tasaPeriodica = $this->calculoFinanciero->tasaPeriodicaQuincenal($tasaEfectivaAnual);
        $tabla = $this->calculoFinanciero->tablaAmortizacion($monto, $tasaPeriodica, $plazoQuincenas);
        $cuota = $this->calculoFinanciero->cuotaFija($monto, $tasaPeriodica, $plazoQuincenas);
        $capacidad = $this->empleadoService->capacidadEndeudamientoSugerida($salario);

        return [
            'errores' => [],
            'tasa_efectiva_anual' => $tasaEfectivaAnual,
            'tasa_nominal_anual' => $this->calculoFinanciero->tasaNominalDesdePeriodica($tasaPeriodica),
            'cuota_quincenal' => $cuota,
            'total_intereses' => round(array_sum(array_column($tabla, 'interes')), 2),
            'total_a_pagar' => round(array_sum(array_column($tabla, 'cuota')), 2),
            'tabla_amortizacion' => $tabla,
            'capacidad_endeudamiento' => $capacidad,
            'margen_capacidad' => round($capacidad - $cuota * 2, 2),
        ];
    }

    /**
     * @return list<string>
     */
    private function validarEntradas(TipoPrestamo $modalidad, float $monto, int $plazoQuincenas, float $salario, int $anioSolicitud): array
    {
        $errores = [];

        if ($monto <= 0) {
            $errores[] = 'El monto debe ser mayor que cero.';
        }

        if ($salario <= 0) {
            $errores[] = 'El salario debe ser mayor que cero.';
        }

        if ($plazoQuincenas < 1) {
            $errores[] = 'El plazo debe ser de al menos 1 quincena.';
        } elseif ($plazoQuincenas > $modalidad->plazo_maximo) {
            $errores[] = "El plazo ({$plazoQuincenas} quincenas) supera el máximo de la modalidad {$modalidad->nombre}: {$modalidad->plazo_maximo} quincenas.";
        }

        if ($monto > 0 && $salario > 0) {
            $errores = [...$errores, ...$this->validarCuantia($modalidad, $monto, $salario, $anioSolicitud)];
        }

        return $errores;
    }

    /**
     * @return list<string>
     */
    private function validarCuantia(TipoPrestamo $modalidad, float $monto, float $salario, int $anioSolicitud): array
    {
        $smmlv = SmmlvAnual::valorParaAnio($anioSolicitud);

        if ($smmlv === null) {
            return ["No se ha registrado el valor del SMMLV para el año {$anioSolicitud}."];
        }

        $tramo = $modalidad->tramoCuantiaPara($salario / $smmlv);

        if ($tramo === null) {
            return ['No hay una cuantía máxima configurada para el tramo salarial del empleado en esta modalidad.'];
        }

        $cuantiaMaxima = (float) $tramo->cuantia_maxima_smmlv * $smmlv;

        if ($monto > $cuantiaMaxima) {
            return ['El monto solicitado ('.number_format($monto, 0, ',', '.').') supera la cuantía máxima permitida para el salario indicado ('.number_format($cuantiaMaxima, 0, ',', '.').').'];
        }

        return [];
    }

    /**
     * @param  list<string>  $errores
     * @return array{errores: list<string>, tasa_efectiva_anual: null, tasa_nominal_anual: null, cuota_quincenal: null, total_intereses: null, total_a_pagar: null, tabla_amortizacion: array{}, capacidad_endeudamiento: null, margen_capacidad: null}
     */
    private function resultadoConErrores(array $errores): array
    {
        return [
            'errores' => $errores,
            'tasa_efectiva_anual' => null,
            'tasa_nominal_anual' => null,
            'cuota_quincenal' => null,
            'total_intereses' => null,
            'total_a_pagar' => null,
            'tabla_amortizacion' => [],
            'capacidad_endeudamiento' => null,
            'margen_capacidad' => null,
        ];
    }
}
