<?php

namespace App\Http\Requests\Concerns;

/**
 * Convierte montos escritos con separadores de miles ("7.500.000",
 * "7,500,000", "$ 7.500.000,50") en un número plano ("7500000", "7500000.50")
 * antes de validar. Un texto con formato ambiguo o inválido se deja intacto
 * para que la regla `numeric` lo rechace en vez de adivinar.
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
        $valor = preg_replace('/[\s$]/u', '', $valor) ?? $valor;

        // Coma decimal (1 o 2 dígitos al final); los puntos anteriores son miles.
        if (preg_match('/^(\d{1,3}(?:\.\d{3})*|\d+),(\d{1,2})$/', $valor, $partes)) {
            return str_replace('.', '', $partes[1]).'.'.$partes[2];
        }

        // Punto decimal con comas de miles: 7,500,000.50
        if (preg_match('/^(\d{1,3}(?:,\d{3})+)\.(\d{1,2})$/', $valor, $partes)) {
            return str_replace(',', '', $partes[1]).'.'.$partes[2];
        }

        // Dos o más separadores iguales: son miles (7.500.000).
        if (preg_match('/^\d{1,3}(?:\.\d{3}){2,}$/', $valor)) {
            return str_replace('.', '', $valor);
        }

        // Comas de miles: 7,500,000 o 7,500
        if (preg_match('/^\d{1,3}(?:,\d{3})+$/', $valor)) {
            return str_replace(',', '', $valor);
        }

        return $valor;
    }
}
