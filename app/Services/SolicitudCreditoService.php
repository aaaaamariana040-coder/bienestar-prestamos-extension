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
use RuntimeException;

class SolicitudCreditoService
{
    public function __construct(
        private readonly PrestamoService $prestamoService,
        private readonly CalculoFinancieroService $calculoFinanciero,
        private readonly EmpleadoService $empleadoService,
        private readonly AuditoriaService $auditoriaService,
    ) {
        //
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function crear(array $datos, Usuario $actor): SolicitudCredito
    {
        $solicitud = SolicitudCredito::create($datos);

        $this->auditoriaService->registrar(
            $actor,
            'Registro de solicitud de crédito',
            "Solicitud #{$solicitud->id_solicitud} — empleado {$solicitud->cedula_empleado}, monto {$solicitud->monto_solicitado}",
        );

        return $solicitud;
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
        } else {
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
        } else {
            $tasaPeriodica = $this->calculoFinanciero->tasaPeriodicaQuincenal($tasaAnual);
            $cuotaTentativa = $this->calculoFinanciero->cuotaFija(
                (float) $solicitud->monto_solicitado,
                $tasaPeriodica,
                $solicitud->plazo_solicitado,
            );

            $capacidad = $this->empleadoService->capacidadEndeudamiento($empleado);

            if ($cuotaTentativa * 2 > $capacidad['disponible']) {
                $problemas[] = ['codigo' => 'capacidad_excedida', 'mensaje' => $this->mensajeCapacidadExcedida($cuotaTentativa, $capacidad)];
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
     * @param  array{total: float, comprometida: float, disponible: float}  $capacidad
     */
    private function mensajeCapacidadExcedida(float $cuotaQuincenal, array $capacidad): string
    {
        $pesos = fn (float $valor): string => '$'.number_format($valor, 0, ',', '.');

        return 'La cuota solicitada ('.$pesos($cuotaQuincenal).' por quincena, '.$pesos($cuotaQuincenal * 2).' al mes) supera la capacidad de endeudamiento disponible del empleado. '
            .'Capacidad total: '.$pesos($capacidad['total']).'; comprometida con préstamos vigentes: '.$pesos($capacidad['comprometida']).'; disponible: '.$pesos($capacidad['disponible']).'.';
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
