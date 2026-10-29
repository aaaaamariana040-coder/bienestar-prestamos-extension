<?php

namespace App\Http\Requests\Concerns;

/**
 * Acepta la coma decimal en las tasas («6,5») y la convierte a punto antes de
 * validar. No usa la normalización de montos: allí un punto con tres dígitos se
 * lee como separador de miles, y en una tasa («5.250») es un decimal.
 */
trait NormalizaTasas
{
    /**
     * @param  array<int, string>  $campos
     */
    protected function normalizarTasas(array $campos): void
    {
        $normalizados = [];

        foreach ($campos as $campo) {
            $valor = $this->input($campo);

            if (is_string($valor)) {
                $normalizados[$campo] = self::normalizarTasaTexto($valor);
            }
        }

        $this->merge($normalizados);
    }

    /**
     * La regla de una sola tasa, también usada fuera de los formularios (carga de archivo).
     */
    protected static function normalizarTasaTexto(string $valor): string
    {
        return str_replace(',', '.', trim($valor));
    }
}
