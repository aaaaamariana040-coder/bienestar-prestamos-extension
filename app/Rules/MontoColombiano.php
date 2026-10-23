<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Verifica que un monto, ya pasado por el trait NormalizaMontos, sea un número
 * plano con a lo sumo dos decimales ("7520507" o "7520507.28"). Distingue dos
 * fallos para decirle a la persona qué corregir: más de dos decimales, o un
 * formato que no se reconoce. Se usa con `bail` para que `numeric` y `min` no
 * repitan el error sobre el mismo texto.
 *
 * Formatos que el trait deja pasar: 7.520.507,28 · 7,520,507.28 · 7520507.28 ·
 * 7520507 · 7.520.507 · $ 7.520.507,28.
 */
class MontoColombiano implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $texto = is_scalar($value) ? trim((string) $value) : '';

        if (preg_match('/^\d+(\.\d{1,2})?$/', $texto) === 1) {
            return;
        }

        // Número bien escrito pero con tres o más decimales: 7.520,50728 · 7520507.28123 · 7.500
        if (preg_match('/^(\d{1,3}(?:[.,]\d{3})*|\d+)[.,]\d{3,}$/', $texto) === 1) {
            $fail('validation.monto_colombiano.decimales')->translate();

            return;
        }

        $fail('validation.monto_colombiano.formato')->translate();
    }
}
