<?php

namespace App\Services\Carga;

/**
 * Lectura común de los CSV de carga (préstamos vigentes, empleados): UTF-8 con o sin BOM (o
 * Latin-1), separador punto y coma o coma, encabezado sin importar mayúsculas, filas vacías
 * ignoradas y un tope de filas. Los mensajes de error no incluyen datos del archivo.
 */
class LectorCsv
{
    /**
     * @param  list<string>  $columnas  todas las columnas de la plantilla
     * @param  list<string>  $obligatorias  columnas que deben estar en el encabezado
     * @param  string  $sujeto  para el mensaje de «sin filas» (p. ej. «de préstamos»)
     * @return array{errores: list<string>, filas: list<array{linea: int, datos: array<string, string>}>}
     */
    public static function leer(string $contenido, array $columnas, array $obligatorias, int $maximoFilas, string $sujeto): array
    {
        $contenido = preg_replace('/^\xEF\xBB\xBF/', '', $contenido) ?? $contenido;

        if (! mb_check_encoding($contenido, 'UTF-8')) {
            $contenido = mb_convert_encoding($contenido, 'UTF-8', 'ISO-8859-1');
        }

        $lineas = preg_split('/\r\n|\n|\r/', $contenido) ?: [];

        while ($lineas !== [] && trim((string) end($lineas)) === '') {
            array_pop($lineas);
        }

        if ($lineas === [] || trim($lineas[0]) === '') {
            return ['errores' => ['El archivo está vacío.'], 'filas' => []];
        }

        $separador = substr_count($lineas[0], ';') >= substr_count($lineas[0], ',') ? ';' : ',';
        $encabezado = array_map(fn (?string $c): string => mb_strtolower(trim((string) $c)), str_getcsv($lineas[0], $separador, '"', ''));
        $faltantes = array_values(array_diff($obligatorias, $encabezado));

        if ($faltantes !== []) {
            return ['errores' => ['Faltan columnas obligatorias en el encabezado: '.implode(', ', $faltantes).'. Descargue la plantilla y úsela tal cual.'], 'filas' => []];
        }

        $filas = [];

        foreach (array_slice($lineas, 1, null, true) as $indice => $linea) {
            if (trim($linea, " \t;,") === '') {
                continue;
            }

            $celdas = str_getcsv($linea, $separador, '"', '');
            $datos = [];

            foreach ($columnas as $columna) {
                $posicion = array_search($columna, $encabezado, true);
                $datos[$columna] = $posicion === false ? '' : trim((string) ($celdas[$posicion] ?? ''));
            }

            $filas[] = ['linea' => $indice + 1, 'datos' => $datos];
        }

        if ($filas === []) {
            return ['errores' => ["El archivo no tiene filas {$sujeto} debajo del encabezado."], 'filas' => []];
        }

        if (count($filas) > $maximoFilas) {
            return ['errores' => ['El archivo tiene '.count($filas)." filas; el máximo por carga es {$maximoFilas}. Divídalo en varios archivos."], 'filas' => []];
        }

        return ['errores' => [], 'filas' => $filas];
    }

    /**
     * Texto CSV con punto y coma, para las plantillas descargables (UTF-8 con BOM).
     *
     * @param  list<list<string>>  $filas
     */
    public static function escribir(array $filas): string
    {
        return "\xEF\xBB\xBF".implode("\r\n", array_map(fn (array $fila): string => implode(';', $fila), $filas))."\r\n";
    }
}
