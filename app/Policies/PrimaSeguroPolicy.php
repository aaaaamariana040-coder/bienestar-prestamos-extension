<?php

namespace App\Policies;

use App\Models\PrimaSeguro;
use App\Models\Usuario;

class PrimaSeguroPolicy
{
    public function viewAny(Usuario $actor): bool
    {
        return $actor->tienePermiso('seguros.ver');
    }

    public function create(Usuario $actor): bool
    {
        return $actor->tienePermiso('seguros.gestionar');
    }

    public function update(Usuario $actor, PrimaSeguro $prima): bool
    {
        return $actor->tienePermiso('seguros.gestionar');
    }
}
