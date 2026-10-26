<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTasaIntRequest;
use App\Http\Requests\UpdateTasaIntRequest;
use App\Models\TasaInt;
use App\Models\TipoPrestamo;
use App\Services\TasaIntService;
use Illuminate\Http\RedirectResponse;

class TasaIntController extends Controller
{
    public function store(StoreTasaIntRequest $request, TipoPrestamo $tipoPrestamo): RedirectResponse
    {
        $this->authorize('update', $tipoPrestamo);

        $tipoPrestamo->tasas()->create($request->validated());

        return redirect()->route('tipos-prestamo.show', $tipoPrestamo)->with('status', 'Tasa registrada correctamente.');
    }

    public function update(UpdateTasaIntRequest $request, TasaInt $tasa, TasaIntService $tasaIntService): RedirectResponse
    {
        $this->authorize('update', $tasa->tipoPrestamo);

        $cambio = $tasaIntService->actualizar($tasa, (float) $request->validated('tasa_anual'), $request->user());

        return redirect()->route('tipos-prestamo.show', $tasa->id_tipo)
            ->with('status', $cambio ? "Tasa de {$tasa->anio} actualizada correctamente." : "La tasa de {$tasa->anio} ya tenía ese valor; no hubo cambios.");
    }
}
