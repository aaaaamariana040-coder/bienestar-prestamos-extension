<?php

namespace App\Policies;

use App\Enums\RolUsuario;
use App\Models\TasaVehiculo;
use App\Models\Usuario;

class TasaVehiculoPolicy
{
    public function viewAny(Usuario $actor): bool
    {
        return true;
    }

    public function update(Usuario $actor, TasaVehiculo $tasaVehiculo): bool
    {
        return $actor->rol === RolUsuario::Administrador;
    }
}
