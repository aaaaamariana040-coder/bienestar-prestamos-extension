<?php

namespace App\Enums;

enum MotivoReliquidacion: string
{
    case AbonoExtraordinario = 'Abono extraordinario';
    case CambioCondiciones = 'Cambio de condiciones';
    case Retiro = 'Retiro';
}
