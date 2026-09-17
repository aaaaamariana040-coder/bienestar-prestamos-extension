<?php

namespace App\Policies;

use App\Enums\EstadoPrestamo;
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

    public function registrarDesembolso(Usuario $actor, Prestamo $prestamo): bool
    {
        return $prestamo->estado === EstadoPrestamo::Vigente;
    }

    public function registrarPago(Usuario $actor, Prestamo $prestamo): bool
    {
        return $prestamo->estado === EstadoPrestamo::Vigente;
    }
}
