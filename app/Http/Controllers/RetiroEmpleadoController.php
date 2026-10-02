<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegistrarRetiroEmpleadoRequest;
use App\Models\Empleado;
use App\Services\RetiroEmpleadoService;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

class RetiroEmpleadoController extends Controller
{
    public function store(RegistrarRetiroEmpleadoRequest $request, Empleado $empleado, RetiroEmpleadoService $retiroService): RedirectResponse
    {
        try {
            $retiroService->registrarRetiro($empleado, $request->validated('fecha_retiro'), $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('empleados.show', $empleado)->with('status', 'Retiro registrado. La liquidación del saldo de sus préstamos queda pendiente de cálculo.');
    }
}
