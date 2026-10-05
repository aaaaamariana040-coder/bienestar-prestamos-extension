<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEmpleadoRequest;
use App\Http\Requests\UpdateEmpleadoRequest;
use App\Models\Empleado;
use App\Services\EmpleadoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EmpleadoController extends Controller
{
    public function __construct(private readonly EmpleadoService $empleadoService)
    {
        //
    }

    public function index(Request $request): View
    {
        $busqueda = trim((string) $request->query('q', ''));
        $estado = (string) $request->query('estado', '');

        if (! in_array($estado, ['Activo', 'Retirado'], true)) {
            $estado = '';
        }

        $empleados = Empleado::query()
            ->when($busqueda !== '', function ($query) use ($busqueda) {
                $patron = '%'.addcslashes($busqueda, '\\%_').'%';

                $query->where(function ($query) use ($patron) {
                    $query->where('cedula', 'like', $patron)
                        ->orWhere('nombre_completo', 'like', $patron);
                });
            })
            ->when($estado !== '', fn ($query) => $query->where('estado', $estado))
            ->orderBy('nombre_completo')
            ->paginate(15)
            ->withQueryString();

        return view('empleados.index', compact('empleados', 'busqueda', 'estado'));
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
