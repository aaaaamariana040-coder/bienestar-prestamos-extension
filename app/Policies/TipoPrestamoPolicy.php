<?php

namespace App\Policies;

use App\Models\TipoPrestamo;
use App\Models\Usuario;

class TipoPrestamoPolicy
{
    public function viewAny(Usuario $actor): bool
    {
        return $actor->tienePermiso('modalidades.ver');
    }

    public function view(Usuario $actor, TipoPrestamo $tipoPrestamo): bool
    {
        return $actor->tienePermiso('modalidades.ver');
    }

    public function create(Usuario $actor): bool
    {
        return $actor->tienePermiso('modalidades.gestionar');
    }

    public function update(Usuario $actor, TipoPrestamo $tipoPrestamo): bool
    {
        return $actor->tienePermiso('modalidades.gestionar');
    }

    public function habilitar(Usuario $actor, TipoPrestamo $tipoPrestamo): bool
    {
        return $actor->tienePermiso('modalidades.gestionar');
    }

    public function deshabilitar(Usuario $actor, TipoPrestamo $tipoPrestamo): bool
    {
        return $actor->tienePermiso('modalidades.gestionar');
    }
}
