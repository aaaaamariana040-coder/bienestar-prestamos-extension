<?php

namespace App\Policies;

use App\Models\InformeGuardado;
use App\Models\Usuario;

class InformeGuardadoPolicy
{
    public function viewAny(Usuario $actor): bool
    {
        return $actor->tienePermiso('reportes.ver');
    }

    public function view(Usuario $actor, InformeGuardado $informe): bool
    {
        return $actor->tienePermiso('reportes.ver');
    }

    public function create(Usuario $actor): bool
    {
        return $actor->tienePermiso('informes.guardar');
    }
}
