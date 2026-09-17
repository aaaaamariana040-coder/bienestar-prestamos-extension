<?php

namespace App\Enums;

enum EstadoSolicitud: string
{
    case Radicada = 'Radicada';
    case Aprobada = 'Aprobada';
    case Rechazada = 'Rechazada';
}
