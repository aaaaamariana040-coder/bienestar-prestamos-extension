<?php

namespace App\Policies;

use App\Models\Prestamo;
use App\Models\Usuario;

class PrestamoPolicy
{
    public function viewAny(Usuario $actor): bool
    {
        return true;
    }

    public function view(Usuario $actor, Prestamo $prestamo): bool
    {
        return true;
    }
}
