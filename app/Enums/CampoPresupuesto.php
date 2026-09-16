<?php

namespace App\Enums;

enum CampoPresupuesto: string
{
    case MontoAsignado = 'monto_asignado';
    case MontoComprometido = 'monto_comprometido';
}
