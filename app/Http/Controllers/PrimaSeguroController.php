<?php

namespace App\Http\Controllers;

use App\Enums\ConceptoSeguro;
use App\Http\Requests\StorePrimaSeguroRequest;
use App\Http\Requests\UpdatePrimaSeguroRequest;
use App\Models\PrimaSeguro;
use App\Services\PrimaSeguroService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class PrimaSeguroController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', PrimaSeguro::class);

        $primas = PrimaSeguro::orderByDesc('anio')->orderBy('concepto')->get();
        $conceptos = ConceptoSeguro::cases();
        $respaldo = PrimaSeguro::formatear((float) config('prestamos.seguro.tasa_sobre_monto'));

        return view('seguros.index', compact('primas', 'conceptos', 'respaldo'));
    }

    public function store(StorePrimaSeguroRequest $request, PrimaSeguroService $primaSeguroService): RedirectResponse
    {
        $this->authorize('create', PrimaSeguro::class);

        $primaSeguroService->crear($request->validated(), $request->user());

        return redirect()->route('seguros.index')->with('status', 'Prima registrada correctamente.');
    }

    public function update(UpdatePrimaSeguroRequest $request, PrimaSeguro $prima, PrimaSeguroService $primaSeguroService): RedirectResponse
    {
        $this->authorize('update', $prima);

        $cambio = $primaSeguroService->actualizar($prima, (float) $request->validated('tasa'), $request->user());

        return redirect()->route('seguros.index')
            ->with('status', $cambio
                ? "Prima de {$prima->concepto->etiqueta()} {$prima->anio} actualizada correctamente."
                : "La prima de {$prima->concepto->etiqueta()} {$prima->anio} ya tenía ese valor; no hubo cambios.");
    }
}
