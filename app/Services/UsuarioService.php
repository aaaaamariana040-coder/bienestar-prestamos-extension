<?php

namespace App\Services;

use App\Enums\RolUsuario;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Altas y cambios de cuentas, con sus salvaguardas y su auditoría. Salvaguardas:
 *  - al único administrador activo no se le quita ningún permiso ni se le cambia el rol;
 *  - el administrador principal no se deshabilita ni deja de ser Administrador, y siempre
 *    tiene los permisos de Usuarios y Auditoría, que ninguna otra cuenta puede tener;
 *  - nadie puede quitarse a sí mismo el acceso a Usuarios;
 *  - siempre queda al menos una cuenta activa que pueda gestionar usuarios.
 */
class UsuarioService
{
    private const PERMISO_USUARIOS = 'usuarios.gestionar';

    public function __construct(
        private readonly PermisosUsuarioService $permisosService,
        private readonly AuditoriaService $auditoriaService,
    ) {
        //
    }

    /**
     * @param  array{cedula: string, nombre: string, apellido: string, password: string, rol: string, permisos?: list<string>|null}  $datos
     *                                                                                                                                       `permisos` null usa los del perfil.
     */
    public function crear(array $datos, Usuario $actor): Usuario
    {
        $rol = RolUsuario::from($datos['rol']);
        $permisos = ($datos['permisos'] ?? null) === null
            ? $this->permisosService->defaultsPara($rol)
            : $this->permisosService->normalizar($datos['permisos']);

        $this->exigirQueSeanPermitidosParaElRol($rol, $permisos);

        // Una cuenta nueva nunca es la principal: los permisos exclusivos del principal se ignoran.
        $permisos = array_values(array_diff($permisos, $this->permisosService->soloAdministrador()));

        return DB::transaction(function () use ($datos, $rol, $permisos, $actor): Usuario {
            $usuario = Usuario::create([
                'cedula' => $datos['cedula'],
                'nombre' => $datos['nombre'],
                'apellido' => $datos['apellido'],
                'contrasena_hash' => Hash::make($datos['password']),
                'rol' => $rol,
                'permisos' => $permisos,
                'habilitado' => true,
            ]);

            $this->auditoriaService->registrar(
                $actor,
                'Creación de usuario',
                "Usuario {$usuario->cedula} ({$usuario->nombre_completo}) — rol {$rol->value}; permisos: ".($permisos === [] ? 'ninguno' : implode(', ', $permisos)),
            );

            return $usuario;
        });
    }

    /**
     * `permisos` null deja los permisos como están (aunque cambie el rol, solo se
     * quitan los exclusivos del Administrador si ya no lo es).
     *
     * @param  array{nombre: string, apellido: string, rol: string, permisos?: list<string>|null}  $datos
     */
    public function actualizar(Usuario $usuario, array $datos, Usuario $actor): Usuario
    {
        $rolNuevo = RolUsuario::from($datos['rol']);
        if ($usuario->esPrincipal() && $rolNuevo !== RolUsuario::Administrador) {
            throw new CambioDeUsuarioNoPermitidoException('El administrador principal no puede dejar de ser Administrador.');
        }

        $permisosAntes = $usuario->permisosAsignados();
        $permisosDespues = ($datos['permisos'] ?? null) === null
            ? $permisosAntes
            : $this->permisosService->normalizar($datos['permisos']);

        if (($datos['permisos'] ?? null) === null && $rolNuevo !== RolUsuario::Administrador && ! $usuario->esPrincipal()) {
            $permisosDespues = array_values(array_diff($permisosDespues, $this->permisosService->soloAdministrador()));
        }

        $this->exigirQueSeanPermitidosParaElRol($rolNuevo, $permisosDespues);

        // Usuarios y Auditoría: el principal siempre los tiene; las demás cuentas, nunca (se ignoran si llegan).
        $exclusivos = $this->permisosService->soloAdministrador();
        $permisosDespues = $usuario->esPrincipal()
            ? $this->permisosService->normalizar([...$permisosDespues, ...$exclusivos])
            : array_values(array_diff($permisosDespues, $rolNuevo === RolUsuario::Administrador ? $exclusivos : []));

        $cambios = $this->permisosService->diferencia($permisosAntes, $permisosDespues);
        $rolAntes = $usuario->rol;

        $this->exigirSalvaguardas($usuario, $actor, $rolNuevo, $permisosDespues, $cambios['quitados']);

        return DB::transaction(function () use ($usuario, $datos, $rolNuevo, $rolAntes, $permisosDespues, $cambios, $actor): Usuario {
            $usuario->update([
                'nombre' => $datos['nombre'],
                'apellido' => $datos['apellido'],
                'rol' => $rolNuevo,
                'permisos' => $permisosDespues,
            ]);

            $quien = "Usuario {$usuario->cedula} ({$usuario->nombre_completo})";

            if ($rolAntes !== $rolNuevo) {
                $this->auditoriaService->registrar($actor, 'Cambio de rol de usuario', "{$quien} — de {$rolAntes->value} a {$rolNuevo->value}");
            }

            if ($cambios['agregados'] !== [] || $cambios['quitados'] !== []) {
                $this->auditoriaService->registrar(
                    $actor,
                    'Cambio de permisos de usuario',
                    "{$quien} — agregó: ".($cambios['agregados'] === [] ? 'nada' : implode(', ', $cambios['agregados']))
                        .'; quitó: '.($cambios['quitados'] === [] ? 'nada' : implode(', ', $cambios['quitados'])),
                );
            }

            return $usuario;
        });
    }

    public function habilitar(Usuario $usuario, Usuario $actor): void
    {
        $usuario->update(['habilitado' => true]);

        $this->auditoriaService->registrar($actor, 'Habilitación de usuario', "Usuario {$usuario->cedula} ({$usuario->nombre_completo})");
    }

    public function deshabilitar(Usuario $usuario, Usuario $actor): void
    {
        if ($usuario->esPrincipal()) {
            throw new CambioDeUsuarioNoPermitidoException('El administrador principal no se puede deshabilitar.');
        }

        if ($this->perderiaLaGestionDeUsuarios($usuario) && ! $this->hayOtraCuentaQueGestioneUsuarios($usuario)) {
            throw new CambioDeUsuarioNoPermitidoException('Debe quedar al menos una cuenta activa con permiso para gestionar usuarios.');
        }

        $usuario->update(['habilitado' => false]);

        $this->auditoriaService->registrar($actor, 'Deshabilitación de usuario', "Usuario {$usuario->cedula} ({$usuario->nombre_completo})");
    }

    /**
     * @param  list<string>  $permisos
     */
    private function exigirQueSeanPermitidosParaElRol(RolUsuario $rol, array $permisos): void
    {
        $exclusivos = $rol === RolUsuario::Administrador ? [] : $this->permisosService->exclusivosDeAdministradorEn($permisos);

        if ($exclusivos !== []) {
            throw new CambioDeUsuarioNoPermitidoException('Solo una cuenta con rol Administrador puede tener: '.implode(', ', $exclusivos).'.');
        }
    }

    /**
     * @param  list<string>  $permisosDespues
     * @param  list<string>  $quitados
     */
    private function exigirSalvaguardas(Usuario $usuario, Usuario $actor, RolUsuario $rolNuevo, array $permisosDespues, array $quitados): void
    {
        $esElUnicoAdministradorActivo = $usuario->habilitado
            && $usuario->rol === RolUsuario::Administrador
            && ! Usuario::where('habilitado', true)->where('rol', RolUsuario::Administrador)->where('cedula', '!=', $usuario->cedula)->exists();

        if ($esElUnicoAdministradorActivo && $rolNuevo !== RolUsuario::Administrador) {
            throw new CambioDeUsuarioNoPermitidoException('No se puede cambiar el rol del único administrador activo.');
        }

        if ($esElUnicoAdministradorActivo && $quitados !== []) {
            throw new CambioDeUsuarioNoPermitidoException('No se pueden quitar permisos al único administrador activo.');
        }

        $conservaLaGestion = $rolNuevo === RolUsuario::Administrador && in_array(self::PERMISO_USUARIOS, $permisosDespues, true);

        if ($this->perderiaLaGestionDeUsuarios($usuario) && ! $conservaLaGestion) {
            if ($usuario->is($actor)) {
                throw new CambioDeUsuarioNoPermitidoException('No puedes quitarte a ti mismo el acceso a Usuarios.');
            }

            if (! $this->hayOtraCuentaQueGestioneUsuarios($usuario)) {
                throw new CambioDeUsuarioNoPermitidoException('Debe quedar al menos una cuenta activa con permiso para gestionar usuarios.');
            }
        }
    }

    /**
     * ¿Hoy esta cuenta gestiona usuarios (y por eso quitarle el permiso, el rol o
     * la habilitación la dejaría sin ese acceso)?
     */
    private function perderiaLaGestionDeUsuarios(Usuario $usuario): bool
    {
        return $usuario->tienePermiso(self::PERMISO_USUARIOS);
    }

    private function hayOtraCuentaQueGestioneUsuarios(Usuario $excluida): bool
    {
        return Usuario::where('habilitado', true)
            ->where('rol', RolUsuario::Administrador)
            ->where('cedula', '!=', $excluida->cedula)
            ->get()
            ->contains(fn (Usuario $otra): bool => $otra->tienePermiso(self::PERMISO_USUARIOS));
    }
}
