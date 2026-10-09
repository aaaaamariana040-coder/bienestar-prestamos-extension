<?php

namespace App\Services;

use App\Models\Empleado;

class EmpleadoService
{
    public function capacidadEndeudamientoSugerida(float $salario): float
    {
        return round($salario * 0.5, 2);
    }

    /**
     * Capacidad de endeudamiento del empleado: el total es el guardado en el
     * empleado; lo comprometido son las cuotas quincenales de sus préstamos
     * Vigente y En mora, dos por mes; lo disponible es la diferencia. La usan
     * la validación de solicitudes y el simulador.
     *
     * Por confirmar (reunión 14-oct): otras deducciones (judiciales,
     * voluntarias, etc.) no se descuentan todavía; solo cuentan los préstamos
     * de este sistema.
     *
     * @return array{total: float, comprometida: float, disponible: float}
     */
    public function capacidadEndeudamiento(Empleado $empleado): array
    {
        $total = (float) $empleado->capacidad_endeudamiento;
        $comprometida = round((float) $empleado->prestamosActivos()->sum('cuota_periodica') * 2, 2);

        return [
            'total' => $total,
            'comprometida' => $comprometida,
            'disponible' => round($total - $comprometida, 2),
        ];
    }
}
