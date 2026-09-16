<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUsuarioRequest;
use App\Http\Requests\UpdateUsuarioRequest;
use App\Models\Empleado;
use App\Models\Usuario;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class UsuarioController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', Usuario::class);

        $usuarios = Usuario::orderBy('nombre_completo')->paginate(15);

        return view('usuarios.index', compact('usuarios'));
    }

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

    public function edit(Usuario $usuario): View
    {
        $this->authorize('update', $usuario);

        return view('usuarios.edit', compact('usuario'));
    }

    public function update(UpdateUsuarioRequest $request, Usuario $usuario): RedirectResponse
    {
        $this->authorize('update', $usuario);

        $usuario->update($request->validated());

        return redirect()->route('usuarios.index')->with('status', 'Usuario actualizado correctamente.');
    }

    public function habilitar(Usuario $usuario): RedirectResponse
    {
        $this->authorize('habilitar', $usuario);

        $usuario->update(['habilitado' => true]);

        return redirect()->route('usuarios.index')->with('status', 'Usuario habilitado correctamente.');
    }

    public function deshabilitar(Usuario $usuario): RedirectResponse
    {
        $this->authorize('deshabilitar', $usuario);

        $usuario->update(['habilitado' => false]);

        return redirect()->route('usuarios.index')->with('status', 'Usuario deshabilitado correctamente.');
    }
}
