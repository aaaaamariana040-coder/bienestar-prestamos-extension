<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTipoPrestamoRequest;
use App\Http\Requests\UpdateTipoPrestamoRequest;
use App\Models\PresupuestoAnual;
use App\Models\TipoPrestamo;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class TipoPrestamoController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', TipoPrestamo::class);

        $anio = now()->year;

        $tiposPrestamo = TipoPrestamo::orderBy('nombre')->paginate(15);
        $presupuestos = PresupuestoAnual::where('anio', $anio)->get()->keyBy('id_tipo');

        return view('tipos-prestamo.index', compact('tiposPrestamo', 'presupuestos', 'anio'));
    }

    public function create(): View
    {
        $this->authorize('create', TipoPrestamo::class);

        return view('tipos-prestamo.create');
    }

    public function store(StoreTipoPrestamoRequest $request): RedirectResponse
    {
        $this->authorize('create', TipoPrestamo::class);

        TipoPrestamo::create([...$request->validated(), 'habilitada' => true]);

        return redirect()->route('tipos-prestamo.index')->with('status', 'Modalidad creada correctamente.');
    }

    public function show(TipoPrestamo $tipoPrestamo): View
    {
        $this->authorize('view', $tipoPrestamo);

        $tipoPrestamo->loadMissing(['tasas', 'cuantias']);

        return view('tipos-prestamo.show', compact('tipoPrestamo'));
    }

    public function edit(TipoPrestamo $tipoPrestamo): View
    {
        $this->authorize('update', $tipoPrestamo);

        return view('tipos-prestamo.edit', compact('tipoPrestamo'));
    }

    public function update(UpdateTipoPrestamoRequest $request, TipoPrestamo $tipoPrestamo): RedirectResponse
    {
        $this->authorize('update', $tipoPrestamo);

        $tipoPrestamo->update($request->validated());

        return redirect()->route('tipos-prestamo.index')->with('status', 'Modalidad actualizada correctamente.');
    }

    public function habilitar(TipoPrestamo $tipoPrestamo): RedirectResponse
    {
        $this->authorize('habilitar', $tipoPrestamo);

        $tipoPrestamo->update(['habilitada' => true]);

        return redirect()->route('tipos-prestamo.index')->with('status', 'Modalidad habilitada correctamente.');
    }

    public function deshabilitar(TipoPrestamo $tipoPrestamo): RedirectResponse
    {
        $this->authorize('deshabilitar', $tipoPrestamo);

        $tipoPrestamo->update(['habilitada' => false]);

        return redirect()->route('tipos-prestamo.index')->with('status', 'Modalidad deshabilitada correctamente.');
    }
}
