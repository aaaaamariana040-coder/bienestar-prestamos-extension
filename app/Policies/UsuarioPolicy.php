<?php

namespace App\Policies;

use App\Enums\RolUsuario;
use App\Models\Usuario;

class UsuarioPolicy
{
    public function viewAny(Usuario $actor): bool
    {
        return $actor->tienePermiso('usuarios.gestionar');
    }

    public function create(Usuario $actor): bool
    {
        return $actor->tienePermiso('usuarios.gestionar');
    }

    public function update(Usuario $actor, Usuario $usuario): bool
    {
        return $actor->tienePermiso('usuarios.gestionar') && ! $usuario->esSistema();
    }

    public function habilitar(Usuario $actor, Usuario $usuario): bool
    {
        return $actor->tienePermiso('usuarios.gestionar') && ! $usuario->esSistema();
    }

    public function deshabilitar(Usuario $actor, Usuario $usuario): bool
    {
        return $actor->tienePermiso('usuarios.gestionar')
            && $usuario->rol !== RolUsuario::Administrador
            && ! $usuario->esSistema();
    }
}
