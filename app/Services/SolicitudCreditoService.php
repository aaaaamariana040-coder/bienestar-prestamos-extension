<?php

namespace App\Services;

use App\Enums\EstadoSolicitud;
use App\Models\Prestamo;
use App\Models\PresupuestoAnual;
use App\Models\SmmlvAnual;
use App\Models\SolicitudCredito;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SolicitudCreditoService
{
    public function __construct(
        private readonly PrestamoService $prestamoService,
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
        $problemas = [];

        $empleado = $solicitud->empleado;
        $tipoPrestamo = $solicitud->tipoPrestamo;
        $anio = $solicitud->fecha_solicitud->year;

        if ($empleado->estado !== 'Activo') {
            $problemas[] = 'El empleado no está activo.';
        }

        if ($solicitud->plazo_solicitado > $tipoPrestamo->plazo_maximo) {
            $problemas[] = "El plazo solicitado supera el plazo máximo de la modalidad ({$tipoPrestamo->plazo_maximo_legible}).";
        }

        $smmlv = SmmlvAnual::valorParaAnio($anio);

        if ($smmlv === null) {
            $problemas[] = "No se ha registrado el valor del SMMLV para el año {$anio}.";
        } else {
            $salarioEnSmmlv = (float) $empleado->salario / $smmlv;

            $tramo = $tipoPrestamo->cuantias()
                ->where('tramo_desde_smmlv', '<=', $salarioEnSmmlv)
                ->where(function ($query) use ($salarioEnSmmlv) {
                    $query->whereNull('tramo_hasta_smmlv')->orWhere('tramo_hasta_smmlv', '>=', $salarioEnSmmlv);
                })
                ->first();

            if ($tramo === null) {
                $problemas[] = 'No hay una cuantía máxima configurada para el tramo salarial del empleado en esta modalidad.';
            } elseif ((float) $solicitud->monto_solicitado > (float) $tramo->cuantia_maxima_smmlv * $smmlv) {
                $problemas[] = 'El monto solicitado supera la cuantía máxima permitida para el tramo salarial del empleado.';
            }
        }

        $presupuesto = PresupuestoAnual::where('id_tipo', $solicitud->id_tipo)->where('anio', $anio)->first();

        if ($presupuesto === null || (float) $solicitud->monto_solicitado > (float) $presupuesto->monto_disponible) {
            $problemas[] = 'No hay disponibilidad presupuestal suficiente para esta modalidad en el año de la solicitud.';
        }

        [, $tasaAnual] = $this->prestamoService->resolverTasa($solicitud, $tipoPrestamo, $anio);

        if ($tasaAnual === null) {
            $problemas[] = 'No hay una tasa de interés registrada para esta modalidad y año.';
        } else {
            $tasaPeriodica = $this->prestamoService->tasaPeriodica($tasaAnual);
            $cuotaTentativa = $this->prestamoService->calcularCuotaFrancesa(
                (float) $solicitud->monto_solicitado,
                $tasaPeriodica,
                $solicitud->plazo_solicitado,
            );

            if ($cuotaTentativa * 2 > (float) $empleado->capacidad_endeudamiento) {
                $problemas[] = 'La cuota resultante supera la capacidad de endeudamiento del empleado.';
            }
        }

        return $problemas;
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
