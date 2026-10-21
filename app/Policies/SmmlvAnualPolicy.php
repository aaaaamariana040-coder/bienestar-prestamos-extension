<?php

namespace App\Policies;

use App\Models\Usuario;

class SmmlvAnualPolicy
{
    public function viewAny(Usuario $actor): bool
    {
        return $actor->tienePermiso('smmlv.ver');
    }

    public function create(Usuario $actor): bool
    {
        return $actor->tienePermiso('smmlv.gestionar');
    }
}
