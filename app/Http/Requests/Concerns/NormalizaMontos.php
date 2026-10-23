<?php

namespace App\Http\Requests\Concerns;

use App\Rules\MontoColombiano;

/**
 * Convierte montos escritos con separadores de miles ("7.500.000",
 * "7,500,000", "150.000", "$ 7.500.000,50") en un número plano ("7500000",
 * "150000", "7500000.50") antes de validar. Un texto con formato inválido se
 * deja intacto para que la regla MontoColombiano lo rechace en vez de adivinar.
 * Las reglas de interpretación están en MontoColombiano::normalizar().
 */
trait NormalizaMontos
{
    /**
     * @param  array<int, string>  $campos
     */
    protected function normalizarMontos(array $campos): void
    {
        $normalizados = [];

        foreach ($campos as $campo) {
            $valor = $this->input($campo);

            if (is_string($valor)) {
                $normalizados[$campo] = $this->normalizarMonto($valor);
            }
        }

        $this->merge($normalizados);
    }

    protected function normalizarMonto(string $valor): string
    {
        return MontoColombiano::normalizar($valor);
    }
}
