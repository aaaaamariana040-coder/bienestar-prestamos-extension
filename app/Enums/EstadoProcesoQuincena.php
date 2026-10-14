<?php

namespace App\Enums;

enum EstadoProcesoQuincena: string
{
    case PendienteConfirmacion = 'Pendiente de confirmación';
    case Completado = 'Completado';
}
