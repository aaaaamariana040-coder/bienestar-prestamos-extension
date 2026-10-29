<?php

namespace App\Policies;

use App\Enums\EstadoPrestamo;
use App\Models\Prestamo;
use App\Models\Usuario;

class PrestamoPolicy
{
    public function viewAny(Usuario $actor): bool
    {
        return $actor->tienePermiso('prestamos.ver');
    }

    public function view(Usuario $actor, Prestamo $prestamo): bool
    {
        return $actor->tienePermiso('prestamos.ver');
    }

    public function cargarVigentes(Usuario $actor): bool
    {
        return $actor->tienePermiso('prestamos.cargar_vigentes');
    }

    public function registrarDesembolso(Usuario $actor, Prestamo $prestamo): bool
    {
        return $actor->tienePermiso('desembolsos.registrar') && $prestamo->estado === EstadoPrestamo::Vigente;
    }

    public function registrarPagoCuota(Usuario $actor, Prestamo $prestamo): bool
    {
        return $actor->tienePermiso('pagos.registrar-cuota') && $prestamo->estado === EstadoPrestamo::Vigente;
    }

    public function registrarAbono(Usuario $actor, Prestamo $prestamo): bool
    {
        return $actor->tienePermiso('pagos.registrar-abono') && $prestamo->estado === EstadoPrestamo::Vigente;
    }

    public function cancelar(Usuario $actor, Prestamo $prestamo): bool
    {
        return $actor->tienePermiso('pagos.cancelar-prestamo') && $prestamo->estado === EstadoPrestamo::Vigente;
    }
}
