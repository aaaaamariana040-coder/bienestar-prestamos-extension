<?php

namespace App\Enums;

enum TipoInforme: string
{
    case Prestamos = 'prestamos';
    case Pagos = 'pagos';
    case Cartera = 'cartera';
    case Presupuesto = 'presupuesto';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Prestamos => 'Reporte de préstamos',
            self::Pagos => 'Reporte de pagos',
            self::Cartera => 'Cartera en mora',
            self::Presupuesto => 'Ejecución del presupuesto',
        };
    }
}
