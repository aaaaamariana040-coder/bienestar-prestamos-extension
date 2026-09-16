<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateTasaVehiculoRequest;
use App\Models\TasaVehiculo;
use App\Services\TasaVehiculoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class TasaVehiculoController extends Controller
{
    public function index(TasaVehiculoService $tasaVehiculoService): View
    {
        $this->authorize('viewAny', TasaVehiculo::class);

        $tasasVehiculo = TasaVehiculo::orderByDesc('es_primera_solicitud')->orderBy('plazo_anios')->get();
        $anioActual = (int) now()->year;

        $tasasEfectivas = $tasasVehiculo->mapWithKeys(fn (TasaVehiculo $tasaVehiculo) => [
            $tasaVehiculo->id_tasa_vehiculo => $tasaVehiculoService->tasaEfectiva(
                $anioActual,
                $tasaVehiculo->plazo_anios,
                $tasaVehiculo->es_primera_solicitud,
            ),
        ]);

        $puedeEditar = $tasasVehiculo->isNotEmpty()
            && auth()->user()->can('update', $tasasVehiculo->first());

        return view('tasa-vehiculo.index', compact('tasasVehiculo', 'tasasEfectivas', 'anioActual', 'puedeEditar'));
    }

    public function update(UpdateTasaVehiculoRequest $request, TasaVehiculo $tasaVehiculo): RedirectResponse
    {
        $this->authorize('update', $tasaVehiculo);

        $tasaVehiculo->update($request->validated());

        return redirect()->route('tasa-vehiculo.index')->with('status', 'Tasa de vehículo actualizada correctamente.');
    }
}
