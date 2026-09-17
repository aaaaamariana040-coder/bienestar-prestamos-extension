<?php

namespace App\Enums;

enum EstadoCuota: string
{
    case Pendiente = 'Pendiente';
    case Pagada = 'Pagada';
    case Vencida = 'Vencida';
}
