<?php

namespace App\Enums;

enum EstadoPrestamo: string
{
    case Vigente = 'Vigente';
    case Cancelado = 'Cancelado';
    case EnMora = 'En mora';
}
