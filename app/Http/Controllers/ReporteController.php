<?php

namespace App\Http\Controllers;

use App\Enums\EstadoPrestamo;
use App\Models\TipoPrestamo;
use App\Services\ReporteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ReporteController extends Controller
{
    public function prestamos(Request $request, ReporteService $reporteService): View
    {
        Gate::authorize('reportes.ver');

        $idTipo = $request->query('id_tipo') !== null ? (int) $request->query('id_tipo') : null;
        $estado = $request->query('estado');
        $desde = $request->query('desde');
        $hasta = $request->query('hasta');

        $filas = $reporteService->prestamosPorModalidad($idTipo, $estado, $desde, $hasta);
        $tiposPrestamo = TipoPrestamo::orderBy('nombre')->get();
        $estados = EstadoPrestamo::cases();

        return view('reportes.prestamos', compact('filas', 'tiposPrestamo', 'estados', 'idTipo', 'estado', 'desde', 'hasta'));
    }

    public function pagos(Request $request, ReporteService $reporteService): View
    {
        Gate::authorize('reportes.ver');

        $desde = $request->query('desde');
        $hasta = $request->query('hasta');

        $filas = $reporteService->pagosPorPeriodo($desde, $hasta);

        return view('reportes.pagos', compact('filas', 'desde', 'hasta'));
    }

    public function cartera(ReporteService $reporteService): View
    {
        Gate::authorize('reportes.ver');

        $filas = $reporteService->carteraVencida();

        return view('reportes.cartera', compact('filas'));
    }

    public function presupuesto(Request $request, ReporteService $reporteService): View
    {
        Gate::authorize('reportes.ver');

        $anio = (int) $request->query('anio', now()->year);
        $presupuestos = $reporteService->ejecucionPresupuesto($anio);

        return view('reportes.presupuesto', compact('anio', 'presupuestos'));
    }
}
