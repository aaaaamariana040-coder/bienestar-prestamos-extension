<?php

namespace App\Policies;

use App\Models\PresupuestoAnual;
use App\Models\Usuario;

class PresupuestoAnualPolicy
{
    public function viewAny(Usuario $actor): bool
    {
        return $actor->tienePermiso('presupuesto.ver');
    }

    public function view(Usuario $actor, PresupuestoAnual $presupuesto): bool
    {
        return $actor->tienePermiso('presupuesto.ver');
    }

    public function create(Usuario $actor): bool
    {
        return $actor->tienePermiso('presupuesto.gestionar');
    }

    public function update(Usuario $actor, PresupuestoAnual $presupuesto): bool
    {
        return $actor->tienePermiso('presupuesto.gestionar');
    }
}
