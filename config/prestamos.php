<?php

/*
 * Parámetros de préstamos que Bienestar Social puede ajustar sin tocar código.
 */

return [

    /*
     * Abono extraordinario con reliquidación (Acuerdo 19 de 2022, Art. 12
     * parágrafos 4 y 5).
     *
     *  - umbral_cuotas: un abono igual o mayor a este número de cuotas vigentes
     *                   permite elegir entre bajar la cuota o bajar el plazo.
     *  - modalidades:   nombres de modalidad a las que aplica, comparados por
     *                   prefijo (como TipoPrestamo::esVivienda). '*' = todas.
     *                   Pendiente confirmar con Bienestar si aplica a todas.
     */
    'abono_extraordinario' => [
        'umbral_cuotas' => (int) env('ABONO_UMBRAL_CUOTAS', 10),
        'modalidades' => array_filter(array_map('trim', explode(',', (string) env('ABONO_MODALIDADES', 'Vivienda')))),
    ],

];
