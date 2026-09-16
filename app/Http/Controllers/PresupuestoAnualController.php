<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePresupuestoAnualRequest;
use App\Http\Requests\UpdatePresupuestoAnualRequest;
use App\Models\PresupuestoAnual;
use App\Models\TipoPrestamo;
use App\Services\PresupuestoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PresupuestoAnualController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', PresupuestoAnual::class);

        $anio = (int) $request->query('anio', now()->year);

        $tiposPrestamo = TipoPrestamo::orderBy('nombre')->get();
        $presupuestos = PresupuestoAnual::where('anio', $anio)->get()->keyBy('id_tipo');

        return view('presupuestos.index', compact('anio', 'tiposPrestamo', 'presupuestos'));
    }

    public function create(Request $request): View
    {
        $this->authorize('create', PresupuestoAnual::class);

        $tiposPrestamo = TipoPrestamo::orderBy('nombre')->get();

        return view('presupuestos.create', compact('tiposPrestamo'));
    }

    public function store(StorePresupuestoAnualRequest $request): RedirectResponse
    {
        $this->authorize('create', PresupuestoAnual::class);

        $presupuesto = PresupuestoAnual::create([...$request->validated(), 'monto_comprometido' => 0]);

        return redirect()->route('presupuestos.index', ['anio' => $presupuesto->anio])
            ->with('status', 'Presupuesto registrado correctamente.');
    }

    public function show(PresupuestoAnual $presupuesto): View
    {
        $this->authorize('view', $presupuesto);

        $presupuesto->loadMissing(['tipoPrestamo', 'historial.usuario']);

        return view('presupuestos.show', compact('presupuesto'));
    }

    public function edit(PresupuestoAnual $presupuesto): View
    {
        $this->authorize('update', $presupuesto);

        return view('presupuestos.edit', compact('presupuesto'));
    }

    public function update(UpdatePresupuestoAnualRequest $request, PresupuestoAnual $presupuesto, PresupuestoService $presupuestoService): RedirectResponse
    {
        $this->authorize('update', $presupuesto);

        $presupuestoService->actualizar(
            $presupuesto,
            $request->safe()->only(['monto_asignado', 'monto_comprometido']),
            $request->validated('motivo'),
            $request->user(),
        );

        return redirect()->route('presupuestos.index', ['anio' => $presupuesto->anio])
            ->with('status', 'Presupuesto actualizado correctamente.');
    }

    public function sugerenciaVivienda(Request $request, PresupuestoService $presupuestoService): View
    {
        $this->authorize('viewAny', PresupuestoAnual::class);

        $anio = (int) $request->query('anio', now()->year);
        $montoTotalVivienda = $request->query('monto_total_vivienda');

        $sugerencias = $montoTotalVivienda !== null
            ? $presupuestoService->sugerenciaVivienda((float) $montoTotalVivienda)
            : null;

        $tiposVivienda = TipoPrestamo::where('nombre', 'like', 'Vivienda%')->pluck('id_tipo', 'nombre');

        return view('presupuestos.sugerencia-vivienda', compact('anio', 'montoTotalVivienda', 'sugerencias', 'tiposVivienda'));
    }
}
