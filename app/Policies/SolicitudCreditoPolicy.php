<?php

namespace App\Policies;

use App\Enums\EstadoSolicitud;
use App\Models\SolicitudCredito;
use App\Models\Usuario;

class SolicitudCreditoPolicy
{
    public function viewAny(Usuario $actor): bool
    {
        return true;
    }

    public function view(Usuario $actor, SolicitudCredito $solicitud): bool
    {
        return true;
    }

    public function create(Usuario $actor): bool
    {
        return true;
    }

    public function decidir(Usuario $actor, SolicitudCredito $solicitud): bool
    {
        return $solicitud->estado === EstadoSolicitud::Radicada;
    }
}
