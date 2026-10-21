<?php

namespace App\Policies;

use App\Models\Usuario;

class ProveedorPolicy
{
    public function viewAny(Usuario $actor): bool
    {
        return $actor->tienePermiso('proveedores.ver');
    }

    public function create(Usuario $actor): bool
    {
        return $actor->tienePermiso('proveedores.gestionar');
    }
}
