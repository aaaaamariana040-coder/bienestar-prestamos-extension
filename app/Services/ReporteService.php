<?php

namespace App\Services;

use App\Enums\EstadoCuota;
use App\Models\Cuota;
use App\Models\Prestamo;
use App\Models\PresupuestoAnual;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
            ->selectRaw('tipos_prestamo.id_tipo as id_tipo, tipos_prestamo.nombre as modalidad, count(*) as total_pagos, sum(pagos.monto) as monto_total, sum(pagos.monto_aplicado_interes) as monto_interes, sum(pagos.monto_aplicado_capital) as monto_capital')
            ->groupBy('tipos_prestamo.id_tipo', 'tipos_prestamo.nombre')
            ->orderBy('tipos_prestamo.nombre')
            ->get();
    }

    /**
     * Detalle de una modalidad del resumen de pagos: los préstamos de esa modalidad con al menos
     * un pago en el mismo rango de fechas (los mismos pagos que suma pagosPorPeriodo), con lo
     * que pidieron, el plazo, la cuota y lo pagado en el periodo. Ordenado por nombre.
     *
     * @return Collection<int, object{prestamo: Prestamo, empleado: string, cedula: string, monto_solicitado: float, total_pagado: float, total_pagos: int}>
     */
    public function detallePagosDeModalidad(int $idTipo, ?string $desde, ?string $hasta): Collection
    {
        $pagado = DB::table('pagos')
            ->join('prestamos', 'prestamos.id_prestamo', '=', 'pagos.id_prestamo')
            ->where('prestamos.id_tipo', $idTipo)
            ->when($desde, fn ($query) => $query->whereDate('pagos.fecha_pago', '>=', $desde))
            ->when($hasta, fn ($query) => $query->whereDate('pagos.fecha_pago', '<=', $hasta))
            ->selectRaw('pagos.id_prestamo as id_prestamo, count(*) as total_pagos, sum(pagos.monto) as total_pagado')
            ->groupBy('pagos.id_prestamo')
            ->get()
            ->keyBy('id_prestamo');

        return Prestamo::with(['empleado', 'solicitudCredito'])
            ->whereIn('id_prestamo', $pagado->keys())
            ->get()
            ->map(fn (Prestamo $prestamo) => (object) [
                'prestamo' => $prestamo,
                'empleado' => (string) $prestamo->empleado?->nombre_completo,
                'cedula' => (string) $prestamo->cedula_empleado,
                'monto_solicitado' => (float) ($prestamo->solicitudCredito?->monto_solicitado ?? $prestamo->monto_aprobado),
                'total_pagado' => round((float) $pagado[$prestamo->id_prestamo]->total_pagado, 2),
                'total_pagos' => (int) $pagado[$prestamo->id_prestamo]->total_pagos,
            ])
            ->sortBy(fn (object $fila): string => Str::lower(Str::ascii($fila->empleado)).'|'.str_pad((string) $fila->prestamo->id_prestamo, 10, '0', STR_PAD_LEFT))
            ->values();
    }

    /**
     * El periodo de un resumen en palabras: sin fechas son todos los pagos registrados.
     */
    public function periodoLegible(?string $desde, ?string $hasta): string
    {
        $formato = fn (string $fecha): string => Carbon::parse($fecha)->format('d/m/Y');

        return match (true) {
            (bool) $desde && (bool) $hasta => 'del '.$formato($desde).' al '.$formato($hasta),
            (bool) $desde => 'desde el '.$formato($desde),
            (bool) $hasta => 'hasta el '.$formato($hasta),
            default => 'todos los pagos registrados',
        };
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
            ->whereHas('prestamo', fn ($q) => $q->enCobro())
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
