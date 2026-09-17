<?php

namespace App\Enums;

enum TipoPago: string
{
    case Regular = 'Regular';
    case AbonoExtraordinario = 'Abono extraordinario';
    case Cancelacion = 'Cancelación';
}
