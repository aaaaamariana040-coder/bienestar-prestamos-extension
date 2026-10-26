<?php

namespace App\Services;

use App\Enums\EstadoSolicitud;
use App\Models\Empleado;
use App\Models\Prestamo;
use App\Models\PresupuestoAnual;
use App\Models\SmmlvAnual;
use App\Models\SolicitudCredito;
use App\Models\Usuario;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class SolicitudCreditoService
{
    /**
     * Quien devenga el SMMLV o menos no puede tener descuentos por nómina; lo
     * rechaza el sistema, no se deja a criterio del operador.
     */
    public const MENSAJE_SALARIO_MINIMO = 'El salario del empleado es igual o menor al salario mínimo (SMMLV). A quien devenga el mínimo no se le puede descontar por nómina.';

    public function __construct(
        private readonly PrestamoService $prestamoService,
        private readonly CalculoFinancieroService $calculoFinanciero,
        private readonly CapacidadEndeudamientoService $capacidadService,
        private readonly AuditoriaService $auditoriaService,
    ) {
        //
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function crear(array $datos, Usuario $actor): SolicitudCredito
    {
        $this->bloquearSiDevengaElMinimo($datos);

        $solicitud = SolicitudCredito::create($datos);

        $this->auditoriaService->registrar(
            $actor,
            'Registro de solicitud de crédito',
            "Solicitud #{$solicitud->id_solicitud} — empleado {$solicitud->cedula_empleado}, monto {$solicitud->monto_solicitado}",
        );

        return $solicitud;
    }

    /**
     * ¿Devenga el salario mínimo o menos? Compara empleados.salario (salario
     * devengado total) con el SMMLV del año. Sin salario registrado (cero o nulo)
     * o sin SMMLV del año no se puede evaluar y devuelve false: de eso se ocupan
     * las otras verificaciones.
     */
    public function devengaElMinimo(Empleado $empleado, ?float $smmlv): bool
    {
        $salario = (float) $empleado->salario;

        return $smmlv !== null && $salario > 0 && round($salario, 2) <= round($smmlv, 2);
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function bloquearSiDevengaElMinimo(array $datos): void
    {
        $empleado = Empleado::find($datos['cedula_empleado'] ?? null);

        if ($empleado === null || ! isset($datos['fecha_solicitud'])) {
            return;
        }

        $smmlv = SmmlvAnual::valorParaAnio(Carbon::parse($datos['fecha_solicitud'])->year);

        if ($this->devengaElMinimo($empleado, $smmlv)) {
            throw ValidationException::withMessages(['cedula_empleado' => 'No se puede registrar: '.lcfirst(self::MENSAJE_SALARIO_MINIMO)]);
        }
    }

    /**
     * Verifica las condiciones del solicitante y la disponibilidad presupuestal
     * (RF-005). No bloquea el registro de la solicitud, solo su aprobación.
     *
     * @return array<int, string> incumplimientos encontrados; vacío = cumple todo
     */
    public function validar(SolicitudCredito $solicitud): array
    {
        return array_column($this->incumplimientos($solicitud), 'mensaje');
    }

    /**
     * Las mismas verificaciones de validar(), con un código estable por cada
     * incumplimiento. Funciona también con una solicitud sin guardar, que es
     * como el simulador pregunta si un empleado podría pedir un crédito.
     *
     * @return array<int, array{codigo: string, mensaje: string}>
     */
    public function incumplimientos(SolicitudCredito $solicitud): array
    {
        $problemas = [];

        $empleado = $solicitud->empleado;
        $tipoPrestamo = $solicitud->tipoPrestamo;
        $anio = $solicitud->fecha_solicitud->year;

        if ($empleado->estado !== 'Activo') {
            $problemas[] = ['codigo' => 'empleado_inactivo', 'mensaje' => 'El empleado no está activo.'];
        }

        if ($tipoPrestamo->exigeAnioDeServicio()) {
            $mensajeAntiguedad = $this->mensajeSiNoCumpleAntiguedad($empleado, $solicitud->fecha_solicitud);

            if ($mensajeAntiguedad !== null) {
                $problemas[] = ['codigo' => 'antiguedad_insuficiente', 'mensaje' => $mensajeAntiguedad];
            }
        }

        if ($solicitud->plazo_solicitado > $tipoPrestamo->plazo_maximo) {
            $problemas[] = ['codigo' => 'plazo_excedido', 'mensaje' => "El plazo solicitado ({$solicitud->plazo_solicitado} quincenas) supera el plazo máximo de la modalidad ({$tipoPrestamo->plazo_maximo_legible})."];
        }

        $smmlv = SmmlvAnual::valorParaAnio($anio);

        if ($smmlv === null) {
            $problemas[] = ['codigo' => 'sin_smmlv', 'mensaje' => "No se ha registrado el valor del SMMLV para el año {$anio}."];
        } elseif ($this->devengaElMinimo($empleado, $smmlv)) {
            $problemas[] = ['codigo' => 'salario_minimo', 'mensaje' => self::MENSAJE_SALARIO_MINIMO];
        }

        if ($smmlv !== null) {
            $salarioEnSmmlv = (float) $empleado->salario / $smmlv;

            $tramo = $tipoPrestamo->tramoCuantiaPara($salarioEnSmmlv);

            if ($tramo === null) {
                $problemas[] = ['codigo' => 'sin_cuantia', 'mensaje' => 'No hay una cuantía máxima configurada para el tramo salarial del empleado en esta modalidad.'];
            } elseif ((float) $solicitud->monto_solicitado > (float) $tramo->cuantia_maxima_smmlv * $smmlv) {
                $problemas[] = ['codigo' => 'cuantia_excedida', 'mensaje' => 'El monto solicitado supera la cuantía máxima permitida para el tramo salarial del empleado.'];
            }
        }

        $presupuesto = PresupuestoAnual::where('id_tipo', $solicitud->id_tipo)->where('anio', $anio)->first();

        if ($presupuesto === null || (float) $solicitud->monto_solicitado > (float) $presupuesto->monto_disponible) {
            $problemas[] = ['codigo' => 'sin_presupuesto', 'mensaje' => 'No hay disponibilidad presupuestal suficiente para esta modalidad en el año de la solicitud.'];
        }

        [, $tasaAnual] = $this->prestamoService->resolverTasa($solicitud, $tipoPrestamo, $anio);

        if ($tasaAnual === null) {
            $problemas[] = ['codigo' => 'sin_tasa', 'mensaje' => 'No hay una tasa de interés registrada para esta modalidad y año.'];
        }

        $capacidad = $this->capacidadService->paraEmpleado($empleado, $anio);

        if (! $capacidad['calculable']) {
            // Sin SMMLV del año ya consta «sin_smmlv»; aquí queda el caso de no tener salario.
            if ($smmlv !== null) {
                $problemas[] = ['codigo' => 'capacidad_no_calculable', 'mensaje' => $capacidad['motivo']];
            }
        } elseif ($tasaAnual !== null) {
            $tasaPeriodica = $this->calculoFinanciero->tasaPeriodicaQuincenal($tasaAnual);
            $monto = (float) $solicitud->monto_solicitado;
            $cuotaTentativa = $this->calculoFinanciero->cuotaFija($monto, $tasaPeriodica, $solicitud->plazo_solicitado);
            $cuota = $this->capacidadService->cuotaAComparar($monto, $cuotaTentativa, $anio, $tipoPrestamo->esVivienda());

            if (! $this->capacidadService->cabe($capacidad, $cuota)) {
                $problemas[] = ['codigo' => 'capacidad_excedida', 'mensaje' => $this->mensajeCapacidadExcedida($cuotaTentativa, $cuota, $capacidad)];
            }
        }

        return $problemas;
    }

    /**
     * Exige al menos un año completo de servicio desde la fecha de vinculación
     * hasta la fecha de la solicitud; exactamente un año cumple.
     */
    private function mensajeSiNoCumpleAntiguedad(Empleado $empleado, Carbon $fechaSolicitud): ?string
    {
        $prefijo = 'Para esta modalidad se exige al menos un año de servicio;';

        if ($empleado->fecha_vinculacion === null) {
            return "{$prefijo} el empleado no tiene fecha de vinculación registrada.";
        }

        if ($empleado->fecha_vinculacion->copy()->addYearNoOverflow()->startOfDay()->lte($fechaSolicitud->copy()->startOfDay())) {
            return null;
        }

        $meses = max(0, (int) floor($empleado->fecha_vinculacion->diffInMonths($fechaSolicitud)));
        $lleva = match (true) {
            $meses === 0 => 'menos de un mes',
            $meses === 1 => '1 mes',
            default => "{$meses} meses",
        };

        return "{$prefijo} el empleado lleva {$lleva}.";
    }

    /**
     * @param  array{cuota_mensual: float, cuota_sin_seguro_mensual: float, seguro_mensual: float, seguros: array<string, mixed>}  $cuota
     * @param  array{total: float, comprometida: float, disponible: float}  $capacidad
     */
    private function mensajeCapacidadExcedida(float $cuotaQuincenal, array $cuota, array $capacidad): string
    {
        $pesos = fn (float $valor): string => '$'.number_format($valor, 0, ',', '.');

        $detalleCapacidad = 'Capacidad total: '.$pesos($capacidad['total']).'; comprometida con préstamos vigentes: '.$pesos($capacidad['comprometida']).'; disponible: '.$pesos($capacidad['disponible']).'.';

        if ($cuota['seguro_mensual'] <= 0) {
            return 'La cuota solicitada ('.$pesos($cuotaQuincenal).' por quincena, '.$pesos($cuota['cuota_sin_seguro_mensual']).' al mes) supera la capacidad de endeudamiento disponible del empleado. '.$detalleCapacidad;
        }

        return 'La cuota solicitada más los seguros ('.$pesos($cuota['cuota_mensual']).' al mes) supera la capacidad de endeudamiento disponible del empleado. '
            .'Cuota: '.$pesos($cuota['cuota_sin_seguro_mensual']).' al mes ('.$pesos($cuotaQuincenal).' por quincena); seguros estimados: '.$pesos($cuota['seguro_mensual']).' al mes. '.$detalleCapacidad;
    }

    public function aprobar(SolicitudCredito $solicitud, Usuario $actor): Prestamo
    {
        $problemas = $this->validar($solicitud);

        if ($problemas !== []) {
            throw new RuntimeException('No se puede aprobar la solicitud: '.implode(' ', $problemas));
        }

        return DB::transaction(function () use ($solicitud, $actor) {
            $prestamo = $this->prestamoService->crearDesdeSolicitud($solicitud);

            $solicitud->update([
                'estado' => EstadoSolicitud::Aprobada,
                'fecha_decision' => now()->toDateString(),
            ]);

            $presupuesto = PresupuestoAnual::where('id_tipo', $solicitud->id_tipo)
                ->where('anio', $solicitud->fecha_solicitud->year)
                ->firstOrFail();

            app(PresupuestoService::class)->actualizar(
                $presupuesto,
                ['monto_comprometido' => (float) $presupuesto->monto_comprometido + (float) $solicitud->monto_solicitado],
                "Aprobación de la solicitud de crédito #{$solicitud->id_solicitud}",
                $actor,
            );

            $this->auditoriaService->registrar(
                $actor,
                'Aprobación de solicitud de crédito',
                "Solicitud #{$solicitud->id_solicitud} aprobada — préstamo #{$prestamo->id_prestamo} generado",
            );

            return $prestamo;
        });
    }

    public function rechazar(SolicitudCredito $solicitud, string $motivo, Usuario $actor): void
    {
        $solicitud->update([
            'estado' => EstadoSolicitud::Rechazada,
            'fecha_decision' => now()->toDateString(),
            'motivo_rechazo' => $motivo,
        ]);

        $this->auditoriaService->registrar(
            $actor,
            'Rechazo de solicitud de crédito',
            "Solicitud #{$solicitud->id_solicitud} rechazada — motivo: {$motivo}",
        );
    }
}
