<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTasaIntRequest;
use App\Models\TipoPrestamo;
use Illuminate\Http\RedirectResponse;

class TasaIntController extends Controller
{
    public function store(StoreTasaIntRequest $request, TipoPrestamo $tipoPrestamo): RedirectResponse
    {
        $this->authorize('update', $tipoPrestamo);

        $tipoPrestamo->tasas()->create($request->validated());

        return redirect()->route('tipos-prestamo.show', $tipoPrestamo)->with('status', 'Tasa registrada correctamente.');
    }
}
