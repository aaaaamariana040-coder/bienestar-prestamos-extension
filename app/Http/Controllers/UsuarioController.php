<?php

namespace App\Http\Controllers;

use App\Enums\RolUsuario;
use App\Http\Requests\StoreUsuarioRequest;
use App\Http\Requests\UpdateUsuarioRequest;
use App\Models\Usuario;
use App\Services\CambioDeUsuarioNoPermitidoException;
use App\Services\PermisosUsuarioService;
use App\Services\UsuarioService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class UsuarioController extends Controller
{
    public function __construct(
        private readonly UsuarioService $usuarioService,
        private readonly PermisosUsuarioService $permisosService,
    ) {
        //
    }

    public function index(): View
    {
        $this->authorize('viewAny', Usuario::class);

        $usuarios = Usuario::sinSistema()->orderBy('nombre')->orderBy('apellido')->paginate(15);
        $personalizados = $usuarios->getCollection()
            ->mapWithKeys(fn (Usuario $usuario): array => [$usuario->cedula => $this->permisosService->esPersonalizado($usuario)]);

        return view('usuarios.index', compact('usuarios', 'personalizados'));
    }

    public function create(): View
    {
        $this->authorize('create', Usuario::class);

        $rol = RolUsuario::tryFrom((string) old('rol')) ?? RolUsuario::cases()[0];

        return view('usuarios.create', $this->datosDelFormularioDePermisos(
            old('permisos_enviados') ? (array) old('permisos', []) : $this->permisosService->defaultsPara($rol),
        ));
    }

    public function store(StoreUsuarioRequest $request): RedirectResponse
    {
        $this->authorize('create', Usuario::class);

        try {
            $this->usuarioService->crear([...$request->validated(), 'permisos' => $request->permisosSolicitados()], $request->user());
        } catch (CambioDeUsuarioNoPermitidoException $e) {
            return back()->withInput()->withErrors(['permisos' => $e->getMessage()]);
        }

        return redirect()->route('empleados.index')->with('status', 'Usuario creado correctamente.');
    }

    public function edit(Usuario $usuario): View
    {
        $this->authorize('update', $usuario);

        return view('usuarios.edit', [
            'usuario' => $usuario,
            'personalizado' => $this->permisosService->esPersonalizado($usuario),
            ...$this->datosDelFormularioDePermisos(old('permisos_enviados') ? (array) old('permisos', []) : $usuario->permisosAsignados()),
        ]);
    }

    public function update(UpdateUsuarioRequest $request, Usuario $usuario): RedirectResponse
    {
        $this->authorize('update', $usuario);

        try {
            $this->usuarioService->actualizar($usuario, [...$request->validated(), 'permisos' => $request->permisosSolicitados()], $request->user());
        } catch (CambioDeUsuarioNoPermitidoException $e) {
            return back()->withInput()->withErrors(['permisos' => $e->getMessage()]);
        }

        return redirect()->route('usuarios.index')->with('status', 'Usuario actualizado correctamente.');
    }

    public function habilitar(Usuario $usuario): RedirectResponse
    {
        $this->authorize('habilitar', $usuario);

        $this->usuarioService->habilitar($usuario, request()->user());

        return redirect()->route('usuarios.index')->with('status', 'Usuario habilitado correctamente.');
    }

    public function deshabilitar(Usuario $usuario): RedirectResponse
    {
        $this->authorize('deshabilitar', $usuario);

        try {
            $this->usuarioService->deshabilitar($usuario, request()->user());
        } catch (CambioDeUsuarioNoPermitidoException $e) {
            return redirect()->route('usuarios.index')->with('error', $e->getMessage());
        }

        return redirect()->route('usuarios.index')->with('status', 'Usuario deshabilitado correctamente.');
    }

    /**
     * Lo que el parcial de casillas necesita: el catálogo agrupado, qué casillas están
     * marcadas, los defaults de cada perfil, las dependencias y los permisos exclusivos
     * del Administrador.
     *
     * @param  array<int, mixed>  $seleccionados
     * @return array<string, mixed>
     */
    private function datosDelFormularioDePermisos(array $seleccionados): array
    {
        return [
            'grupos' => $this->permisosService->agrupado(),
            'seleccionados' => array_map('strval', $seleccionados),
            'defaultsPorRol' => collect(RolUsuario::cases())->mapWithKeys(fn (RolUsuario $rol): array => [$rol->value => $this->permisosService->defaultsPara($rol)])->all(),
            'exigidos' => config('permisos.dependencias'),
            'dependientes' => $this->permisosService->dependientes(),
            'soloAdministrador' => $this->permisosService->soloAdministrador(),
        ];
    }
}
