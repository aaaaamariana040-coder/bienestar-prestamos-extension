<?php

namespace App\Policies;

use App\Enums\RolUsuario;
use App\Models\TipoPrestamo;
use App\Models\Usuario;

class TipoPrestamoPolicy
{
    public function viewAny(Usuario $actor): bool
    {
        return true;
    }

    public function view(Usuario $actor, TipoPrestamo $tipoPrestamo): bool
    {
        return true;
    }

    public function create(Usuario $actor): bool
    {
        return $actor->rol === RolUsuario::Administrador;
    }

    public function update(Usuario $actor, TipoPrestamo $tipoPrestamo): bool
    {
        return $actor->rol === RolUsuario::Administrador;
    }

    public function habilitar(Usuario $actor, TipoPrestamo $tipoPrestamo): bool
    {
        return $actor->rol === RolUsuario::Administrador;
    }

    public function deshabilitar(Usuario $actor, TipoPrestamo $tipoPrestamo): bool
    {
        return $actor->rol === RolUsuario::Administrador;
    }
}
