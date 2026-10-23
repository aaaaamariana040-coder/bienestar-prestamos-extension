<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Verifica que un monto sea un número con a lo sumo dos decimales y distingue
 * dos fallos para decirle a la persona qué corregir: más de dos decimales, o un
 * formato que no se reconoce. Se usa con `bail` para que `numeric` y `min` no
 * repitan el error sobre el mismo texto.
 *
 * La interpretación del texto vive en normalizar(), que también usa el trait
 * NormalizaMontos antes de validar, de modo que regla y normalización no se
 * contradicen. Formatos que acepta: 7.520.507,28 · 7,520,507.28 · 7520507.28 ·
 * 7520507 · 7.520.507 · 150.000 · $ 7.520.507,28.
 */
class MontoColombiano implements ValidationRule
{
    /**
     * Convierte un monto escrito con separadores de miles ("7.500.000",
     * "7,500,000", "150.000", "$ 7.500.000,50") en un número plano
     * ("7500000", "150000", "7500000.50"). Un texto con formato inválido se
     * devuelve intacto para que la validación lo rechace en vez de adivinar.
     *
     * Un punto o una coma seguidos de exactamente tres dígitos no pueden ser
     * decimales (los pesos llevan máximo dos): son separador de miles. Con uno o
     * dos dígitos son decimales (7.5, 7,52).
     */
    public static function normalizar(string $valor): string
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

        // Un solo punto con tres dígitos después: miles (150.000 = 150000, 7.500 = 7500).
        // Sin cero a la izquierda: 0.500 sigue siendo un decimal con tres cifras.
        if (preg_match('/^[1-9]\d{0,2}\.\d{3}$/', $valor)) {
            return str_replace('.', '', $valor);
        }

        // Comas de miles: 7,500,000 o 7,500
        if (preg_match('/^\d{1,3}(?:,\d{3})+$/', $valor)) {
            return str_replace(',', '', $valor);
        }

        return $valor;
    }

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $texto = self::normalizar(is_scalar($value) ? trim((string) $value) : '');

        if (preg_match('/^\d+(\.\d{1,2})?$/', $texto) === 1) {
            return;
        }

        // Número bien escrito pero con tres o más decimales: 7.520,50728 · 7520507.28123 · 7520.507
        if (preg_match('/^(\d{1,3}(?:[.,]\d{3})*|\d+)[.,]\d{3,}$/', $texto) === 1) {
            $fail('validation.monto_colombiano.decimales')->translate();

            return;
        }

        $fail('validation.monto_colombiano.formato')->translate();
    }
}
