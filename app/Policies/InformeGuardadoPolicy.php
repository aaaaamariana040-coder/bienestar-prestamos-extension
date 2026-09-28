<?php

namespace App\Policies;

use App\Models\InformeGuardado;
use App\Models\Usuario;
use Illuminate\Support\Facades\Gate;

class InformeGuardadoPolicy
{
    public function viewAny(Usuario $actor): bool
    {
        return $this->puedeVerReportes($actor);
    }

    public function view(Usuario $actor, InformeGuardado $informe): bool
    {
        return $this->puedeVerReportes($actor);
    }

    public function create(Usuario $actor): bool
    {
        return $this->puedeVerReportes($actor);
    }

    private function puedeVerReportes(Usuario $actor): bool
    {
        return Gate::forUser($actor)->allows('reportes.ver');
    }
}
