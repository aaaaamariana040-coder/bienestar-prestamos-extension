<?php

namespace App\Policies;

use App\Models\TasaVehiculo;
use App\Models\Usuario;

class TasaVehiculoPolicy
{
    public function viewAny(Usuario $actor): bool
    {
        return $actor->tienePermiso('modalidades.ver');
    }

    public function update(Usuario $actor, TasaVehiculo $tasaVehiculo): bool
    {
        return $actor->tienePermiso('modalidades.gestionar');
    }
}
