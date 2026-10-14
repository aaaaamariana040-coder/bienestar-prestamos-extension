<?php

namespace App\Enums;

enum InicioPrimeraCuota: string
{
    case DesdeAprobacion = 'desde_aprobacion';
    case DesdeDesembolso = 'desde_desembolso';
}
