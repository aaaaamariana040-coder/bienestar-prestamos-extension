<?php

namespace App\Services;

use App\Models\PresupuestoAnual;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;

class PresupuestoService
{
    /**
     * Reparto del presupuesto de vivienda entre sus 5 submodalidades, según el
     * Acuerdo Directivo No. 19/2022 Art. 18: 70% compra+construcción (15% de eso
     * para construcción) y el 30% restante en partes iguales entre deshipoteca,
     * reparaciones/mejoras y cambio de vivienda.
     *
     * @var array<string, float>
     */
    private const PORCENTAJES_VIVIENDA = [
        'Vivienda — compra de inmueble' => 0.595,
        'Vivienda — construcción' => 0.105,
        'Vivienda — cancelación total de hipoteca (deshipoteca)' => 0.10,
        'Vivienda — reparaciones o mejoras' => 0.10,
        'Vivienda — cambio de vivienda' => 0.10,
    ];

    /**
     * @return array<string, float>
     */
    public function sugerenciaVivienda(float $montoTotalVivienda): array
    {
        return array_map(
            fn (float $porcentaje): float => round($montoTotalVivienda * $porcentaje, 2),
            self::PORCENTAJES_VIVIENDA,
        );
    }

    /**
     * Actualiza monto_asignado y/o monto_comprometido dejando constancia en
     * presupuesto_historial de cada campo que efectivamente haya cambiado.
     *
     * @param  array<string, mixed>  $datos
     */
    public function actualizar(PresupuestoAnual $presupuesto, array $datos, string $motivo, Usuario $actor): PresupuestoAnual
    {
        return DB::transaction(function () use ($presupuesto, $datos, $motivo, $actor) {
            foreach (['monto_asignado', 'monto_comprometido'] as $campo) {
                if (! array_key_exists($campo, $datos)) {
                    continue;
                }

                $montoAnterior = round((float) $presupuesto->{$campo}, 2);
                $montoNuevo = round((float) $datos[$campo], 2);

                if ($montoAnterior === $montoNuevo) {
                    continue;
                }

                $presupuesto->historial()->create([
                    'campo' => $campo,
                    'monto_anterior' => $montoAnterior,
                    'monto_nuevo' => $montoNuevo,
                    'motivo' => $motivo,
                    'cedula_usu' => $actor->cedula,
                    'fecha' => now(),
                ]);
            }

            $presupuesto->update($datos);

            return $presupuesto;
        });
    }
}
