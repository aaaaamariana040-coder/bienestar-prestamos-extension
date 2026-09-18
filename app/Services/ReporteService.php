<?php

namespace App\Services;

use App\Enums\EstadoCuota;
use App\Models\Cuota;
use App\Models\PresupuestoAnual;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReporteService
{
    /**
     * RF-009: total y monto de préstamos agrupados por modalidad y estado,
     * dentro del rango de fecha de autorización indicado.
     */
    public function prestamosPorModalidad(?int $idTipo, ?string $estado, ?string $desde, ?string $hasta): Collection
    {
        return DB::table('prestamos')
            ->join('tipos_prestamo', 'tipos_prestamo.id_tipo', '=', 'prestamos.id_tipo')
            ->when($idTipo, fn ($query) => $query->where('prestamos.id_tipo', $idTipo))
            ->when($estado, fn ($query) => $query->where('prestamos.estado', $estado))
            ->when($desde, fn ($query) => $query->whereDate('prestamos.fecha_autorizacion', '>=', $desde))
            ->when($hasta, fn ($query) => $query->whereDate('prestamos.fecha_autorizacion', '<=', $hasta))
            ->selectRaw('tipos_prestamo.nombre as modalidad, prestamos.estado as estado, count(*) as total_prestamos, sum(prestamos.monto_aprobado) as monto_total')
            ->groupBy('tipos_prestamo.nombre', 'prestamos.estado')
            ->orderBy('tipos_prestamo.nombre')
            ->get();
    }

    /**
     * RF-009: total pagado (y su reparto en interés/capital) agrupado por
     * modalidad, dentro del rango de fecha de pago indicado.
     */
    public function pagosPorPeriodo(?string $desde, ?string $hasta): Collection
    {
        return DB::table('pagos')
            ->join('prestamos', 'prestamos.id_prestamo', '=', 'pagos.id_prestamo')
            ->join('tipos_prestamo', 'tipos_prestamo.id_tipo', '=', 'prestamos.id_tipo')
            ->when($desde, fn ($query) => $query->whereDate('pagos.fecha_pago', '>=', $desde))
            ->when($hasta, fn ($query) => $query->whereDate('pagos.fecha_pago', '<=', $hasta))
            ->selectRaw('tipos_prestamo.nombre as modalidad, count(*) as total_pagos, sum(pagos.monto) as monto_total, sum(pagos.monto_aplicado_interes) as monto_interes, sum(pagos.monto_aplicado_capital) as monto_capital')
            ->groupBy('tipos_prestamo.nombre')
            ->orderBy('tipos_prestamo.nombre')
            ->get();
    }

    /**
     * RF-009: cartera en mora, calculada al vuelo a partir de las cuotas
     * pendientes con fecha de vencimiento pasada (no hay job programado que
     * marque cuotas como vencidas).
     */
    public function carteraVencida(): Collection
    {
        $hoy = Carbon::today();

        return Cuota::with(['prestamo.empleado', 'prestamo.tipoPrestamo'])
            ->where('estado', EstadoCuota::Pendiente)
            ->where('fecha_vencimiento', '<', $hoy->toDateString())
            ->orderBy('fecha_vencimiento')
            ->get()
            ->map(fn (Cuota $cuota) => (object) [
                'prestamo' => $cuota->prestamo,
                'numero_cuota' => $cuota->numero_cuota,
                'fecha_vencimiento' => $cuota->fecha_vencimiento,
                'valor_total' => $cuota->valor_total,
                'dias_mora' => (int) $cuota->fecha_vencimiento->diffInDays($hoy),
            ]);
    }

    /**
     * RF-009: ejecución del presupuesto (asignado/comprometido/disponible)
     * por modalidad para un año dado.
     *
     * @return Collection<int, PresupuestoAnual>
     */
    public function ejecucionPresupuesto(int $anio): Collection
    {
        return PresupuestoAnual::where('anio', $anio)
            ->with('tipoPrestamo')
            ->get();
    }
}
