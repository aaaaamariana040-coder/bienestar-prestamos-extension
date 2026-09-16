<?php

namespace App\Policies;

use App\Models\PresupuestoAnual;
use App\Models\Usuario;

class PresupuestoAnualPolicy
{
    public function viewAny(Usuario $actor): bool
    {
        return true;
    }

    public function view(Usuario $actor, PresupuestoAnual $presupuesto): bool
    {
        return true;
    }

    public function create(Usuario $actor): bool
    {
        return true;
    }

    public function update(Usuario $actor, PresupuestoAnual $presupuesto): bool
    {
        return true;
    }
}
