<?php

namespace App\Services\Carga;

use Closure;
use Illuminate\Support\Str;

/**
 * Identificador de lote y resumen por estado, comunes a las cargas por archivo.
 */
class Lotes
{
    /**
     * «CARGA-AAAAMMDD-HHMMSS-XXXX»; reintenta si ya existe.
     *
     * @param  Closure(string): bool  $yaExiste
     */
    public static function nuevo(Closure $yaExiste): string
    {
        do {
            $lote = 'CARGA-'.now()->format('Ymd-His').'-'.Str::upper(Str::random(4));
        } while ($yaExiste($lote));

        return $lote;
    }

    /**
     * @param  list<array<string, mixed>>  $filas  cada una con la clave «estado»
     * @return array<string, int>
     */
    public static function resumir(array $filas, bool $conOmitidas = false): array
    {
        $estados = array_count_values(array_column($filas, 'estado'));

        $resumen = [
            'total' => count($filas),
            'correctas' => $estados['correcta'] ?? 0,
            'advertencias' => $estados['advertencia'] ?? 0,
            'errores' => $estados['error'] ?? 0,
        ];

        if ($conOmitidas) {
            $resumen['omitidas'] = $estados['omitida'] ?? 0;
        }

        return $resumen;
    }
}
