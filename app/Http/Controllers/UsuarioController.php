<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUsuarioRequest;
use App\Models\Empleado;
use App\Models\Usuario;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class UsuarioController extends Controller
{
    public function create(): View
    {
        $this->authorize('create', Usuario::class);

        $empleados = Empleado::orderBy('nombre_completo')->get();

        return view('usuarios.create', compact('empleados'));
    }

    public function store(StoreUsuarioRequest $request): RedirectResponse
    {
        $this->authorize('create', Usuario::class);

        $datos = $request->validated();
        $empleado = Empleado::findOrFail($datos['cedula']);

        Usuario::create([
            'cedula' => $empleado->cedula,
            'nombre_completo' => $empleado->nombre_completo,
            'contrasena_hash' => Hash::make($datos['password']),
            'rol' => $datos['rol'],
            'habilitado' => true,
        ]);

        return redirect()->route('empleados.index')->with('status', 'Usuario creado correctamente.');
    }
}
