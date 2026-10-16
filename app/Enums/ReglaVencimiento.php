<?php

namespace App\Enums;

enum ReglaVencimiento: string
{
    case Calendario = 'calendario';
    case Cada15Dias = 'cada_15_dias';
}
