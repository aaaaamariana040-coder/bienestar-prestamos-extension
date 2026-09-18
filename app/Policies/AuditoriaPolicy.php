<?php

namespace App\Policies;

use App\Enums\RolUsuario;
use App\Models\Usuario;

class AuditoriaPolicy
{
    public function viewAny(Usuario $actor): bool
    {
        return $actor->rol === RolUsuario::Administrador;
    }
}
