<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCuantiaSmmlvRequest;
use App\Http\Requests\UpdateCuantiaSmmlvRequest;
use App\Models\CuantiaSmmlv;
use App\Models\TipoPrestamo;
use Illuminate\Http\RedirectResponse;

class CuantiaSmmlvController extends Controller
{
    public function store(StoreCuantiaSmmlvRequest $request, TipoPrestamo $tipoPrestamo): RedirectResponse
    {
        $this->authorize('update', $tipoPrestamo);

        $tipoPrestamo->cuantias()->create($request->validated());

        return redirect()->route('tipos-prestamo.show', $tipoPrestamo)->with('status', 'Tramo de cuantía agregado correctamente.');
    }

    public function update(UpdateCuantiaSmmlvRequest $request, CuantiaSmmlv $cuantia): RedirectResponse
    {
        $this->authorize('update', $cuantia->tipoPrestamo);

        $cuantia->update($request->validated());

        return redirect()->route('tipos-prestamo.show', $cuantia->id_tipo)->with('status', 'Tramo de cuantía actualizado correctamente.');
    }

    public function destroy(CuantiaSmmlv $cuantia): RedirectResponse
    {
        $this->authorize('update', $cuantia->tipoPrestamo);

        $idTipo = $cuantia->id_tipo;
        $cuantia->delete();

        return redirect()->route('tipos-prestamo.show', $idTipo)->with('status', 'Tramo de cuantía eliminado correctamente.');
    }
}
