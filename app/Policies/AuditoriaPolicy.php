<?php

namespace App\Policies;

use App\Models\Usuario;

class AuditoriaPolicy
{
    public function viewAny(Usuario $actor): bool
    {
        return $actor->tienePermiso('auditoria.ver');
    }
}
