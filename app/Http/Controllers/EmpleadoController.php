<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEmpleadoRequest;
use App\Http\Requests\UpdateEmpleadoRequest;
use App\Models\Empleado;
use App\Services\EmpleadoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class EmpleadoController extends Controller
{
    public function __construct(private readonly EmpleadoService $empleadoService)
    {
        //
    }

    public function index(): View
    {
        $empleados = Empleado::orderBy('nombre_completo')->paginate(15);

        return view('empleados.index', compact('empleados'));
    }

    public function create(): View
    {
        return view('empleados.create');
    }

    public function store(StoreEmpleadoRequest $request): RedirectResponse
    {
        $datos = $this->conCapacidadEndeudamiento($request->validated());

        Empleado::create($datos);

        return redirect()->route('empleados.index')->with('status', 'Empleado creado correctamente.');
    }

    public function show(Empleado $empleado): View
    {
        $empleado->loadMissing('usuario');

        return view('empleados.show', compact('empleado'));
    }

    public function edit(Empleado $empleado): View
    {
        return view('empleados.edit', compact('empleado'));
    }

    public function update(UpdateEmpleadoRequest $request, Empleado $empleado): RedirectResponse
    {
        $datos = $this->conCapacidadEndeudamiento($request->validated());

        $empleado->update($datos);

        return redirect()->route('empleados.index')->with('status', 'Empleado actualizado correctamente.');
    }

    public function confirmarEliminacion(Empleado $empleado): View
    {
        $empleado->loadMissing('usuario');

        return view('empleados.confirmar-eliminacion', compact('empleado'));
    }

    public function destroy(Empleado $empleado): RedirectResponse
    {
        if ($empleado->usuario()->exists()) {
            return redirect()
                ->route('empleados.index')
                ->with('error', 'No se puede eliminar un empleado con una cuenta de usuario asociada.');
        }

        $empleado->delete();

        return redirect()->route('empleados.index')->with('status', 'Empleado eliminado correctamente.');
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function conCapacidadEndeudamiento(array $datos): array
    {
        if (empty($datos['capacidad_endeudamiento'])) {
            $datos['capacidad_endeudamiento'] = $this->empleadoService->capacidadEndeudamientoSugerida((float) $datos['salario']);
        }

        return $datos;
    }
}
