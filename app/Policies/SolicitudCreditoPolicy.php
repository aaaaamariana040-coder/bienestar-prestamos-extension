<?php

namespace App\Policies;

use App\Enums\EstadoSolicitud;
use App\Models\SolicitudCredito;
use App\Models\Usuario;

class SolicitudCreditoPolicy
{
    public function viewAny(Usuario $actor): bool
    {
        return $actor->tienePermiso('solicitudes.ver');
    }

    public function view(Usuario $actor, SolicitudCredito $solicitud): bool
    {
        return $actor->tienePermiso('solicitudes.ver');
    }

    public function create(Usuario $actor): bool
    {
        return $actor->tienePermiso('solicitudes.registrar');
    }

    public function aprobar(Usuario $actor, SolicitudCredito $solicitud): bool
    {
        return $actor->tienePermiso('solicitudes.aprobar') && $solicitud->estado === EstadoSolicitud::Radicada;
    }

    public function rechazar(Usuario $actor, SolicitudCredito $solicitud): bool
    {
        return $actor->tienePermiso('solicitudes.rechazar') && $solicitud->estado === EstadoSolicitud::Radicada;
    }
}
