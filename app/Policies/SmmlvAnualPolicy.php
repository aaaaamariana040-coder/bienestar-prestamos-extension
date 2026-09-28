<?php

namespace App\Policies;

use App\Models\Usuario;

class SmmlvAnualPolicy
{
    public function viewAny(Usuario $actor): bool
    {
        return true;
    }

    public function create(Usuario $actor): bool
    {
        return $actor->puedeOperar();
    }
}
