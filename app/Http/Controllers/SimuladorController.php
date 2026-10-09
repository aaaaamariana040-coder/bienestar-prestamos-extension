<?php

namespace App\Http\Controllers;

use App\Models\Empleado;
use App\Models\TipoPrestamo;
use App\Services\SimuladorCreditoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class SimuladorController extends Controller
{
    public function __construct(private readonly SimuladorCreditoService $simuladorCreditoService)
    {
        //
    }

    public function index(): View
    {
        Gate::authorize('simulador.usar');

        return view('simulador.index');
    }

    public function empleado(string $cedula): JsonResponse
    {
        Gate::authorize('simulador.usar');

        $datos = $this->simuladorCreditoService->consultarEmpleado($cedula);

        return response()->json($datos, $datos['encontrado'] ? 200 : 404);
    }

    public function modalidad(string $cedula, TipoPrestamo $tipo_prestamo): JsonResponse
    {
        Gate::authorize('simulador.usar');

        $empleado = Empleado::find($cedula);

        if ($empleado === null) {
            return response()->json(['mensaje' => "No existe un empleado con la cédula {$cedula}."], 404);
        }

        if ($empleado->estado !== 'Activo') {
            return response()->json(['mensaje' => 'El empleado está retirado y no puede solicitar un crédito.'], 422);
        }

        return response()->json($this->simuladorCreditoService->evaluarModalidad($empleado, $tipo_prestamo));
    }
}
