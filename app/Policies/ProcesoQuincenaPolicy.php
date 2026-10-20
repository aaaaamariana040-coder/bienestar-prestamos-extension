<?php

namespace App\Policies;

use App\Models\ProcesoQuincena;
use App\Models\Usuario;

class ProcesoQuincenaPolicy
{
    public function viewAny(Usuario $actor): bool
    {
        return $actor->puedeOperar();
    }

    public function view(Usuario $actor, ProcesoQuincena $proceso): bool
    {
        return $actor->puedeOperar();
    }

    public function confirmar(Usuario $actor): bool
    {
        return $actor->puedeOperar();
    }
}
