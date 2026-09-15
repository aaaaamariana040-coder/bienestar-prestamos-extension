<?php

namespace App\Policies;

use App\Enums\RolUsuario;
use App\Models\Usuario;

class UsuarioPolicy
{
    public function create(Usuario $actor): bool
    {
        return $actor->rol === RolUsuario::Administrador;
    }
}
