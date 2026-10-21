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

    /*
     * Seguro de vida deudor que el simulador muestra aparte de la cuota:
     * monto × tasa_sobre_monto, sumado a la cuota quincenal. NO entra en la
     * comparación con la capacidad de endeudamiento (igual que en la
     * aprobación de solicitudes). Pendiente de confirmar con Bienestar: la
     * tarifa (docs/reglas-negocio.md §12 menciona 0,00034516 mensual sobre el
     * saldo), su periodicidad y si la cuota comparada debe incluirlo.
     */
    'seguro' => [
        'tasa_sobre_monto' => (float) env('SEGURO_TASA_SOBRE_MONTO', 0.0003231),
    ],

    /*
     * Simulador de crédito.
     *
     *  - plazo_tecnico_maximo:     tope de quincenas que se puede simular aunque
     *                              la modalidad permita más; evita tablas enormes.
     *  - restar_descuentos_ley:    ACTIVO: la capacidad que decide el veredicto del
     *                              simulador es 50 % de (salario − descuentos de ley).
     *                              Apagado: se usa la capacidad guardada del empleado,
     *                              la misma de la aprobación. El Acuerdo 19/2022
     *                              (art. 10, par. 3) dice que seguridad social y
     *                              retención NO se cuentan; Bienestar debe confirmarlo.
     *  - descuentos_ley:           porcentajes sobre el salario. VERIFICAR CON LA NORMA
     *                              VIGENTE (salud y pensión del empleado, fondo de
     *                              solidaridad pensional por tramos de SMMLV). Cada tramo
     *                              incluye `desde` y excluye `hasta` (hasta null = sin tope).
     */
    'simulador' => [
        'plazo_tecnico_maximo' => (int) env('SIMULADOR_PLAZO_TECNICO_MAXIMO', 480),
        'restar_descuentos_ley' => (bool) env('SIMULADOR_RESTAR_DESCUENTOS_LEY', true),
        'descuentos_ley' => [
            'salud' => 4.0,
            'pension' => 4.0,
            'solidaridad_pensional' => [
                ['desde_smmlv' => 0, 'hasta_smmlv' => 4, 'porcentaje' => 0.0],
                ['desde_smmlv' => 4, 'hasta_smmlv' => 16, 'porcentaje' => 1.0],
                ['desde_smmlv' => 16, 'hasta_smmlv' => 17, 'porcentaje' => 1.2],
                ['desde_smmlv' => 17, 'hasta_smmlv' => 18, 'porcentaje' => 1.4],
                ['desde_smmlv' => 18, 'hasta_smmlv' => 19, 'porcentaje' => 1.6],
                ['desde_smmlv' => 19, 'hasta_smmlv' => 20, 'porcentaje' => 1.8],
                ['desde_smmlv' => 20, 'hasta_smmlv' => null, 'porcentaje' => 2.0],
            ],
        ],
    ],

];
