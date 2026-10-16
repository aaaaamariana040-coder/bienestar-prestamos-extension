<?php

namespace App\Policies;

use App\Enums\RolUsuario;
use App\Models\Usuario;

class UsuarioPolicy
{
    public function viewAny(Usuario $actor): bool
    {
        return $actor->rol === RolUsuario::Administrador;
    }

    public function create(Usuario $actor): bool
    {
        return $actor->rol === RolUsuario::Administrador;
    }

    public function update(Usuario $actor, Usuario $usuario): bool
    {
        return $actor->rol === RolUsuario::Administrador && ! $usuario->esSistema();
    }

    public function habilitar(Usuario $actor, Usuario $usuario): bool
    {
        return $actor->rol === RolUsuario::Administrador && ! $usuario->esSistema();
    }

    public function deshabilitar(Usuario $actor, Usuario $usuario): bool
    {
        return $actor->rol === RolUsuario::Administrador
            && $usuario->rol !== RolUsuario::Administrador
            && ! $usuario->esSistema();
    }
}
