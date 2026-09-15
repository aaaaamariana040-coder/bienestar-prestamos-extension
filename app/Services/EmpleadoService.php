<?php

namespace App\Services;

class EmpleadoService
{
    public function capacidadEndeudamientoSugerida(float $salario): float
    {
        return round($salario * 0.5, 2);
    }
}
