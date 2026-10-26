<?php

/*
 * Parámetros de préstamos que Bienestar Social puede ajustar sin tocar código.
 */

return [

    /*
     * Abono extraordinario con reliquidación (Acuerdo 19 de 2022, Art. 12
     * parágrafos 4 y 5).
     *
     *  - umbral_cuotas: un abono MAYOR a este número de cuotas quincenales
     *                   vigentes (sin seguro) permite elegir entre bajar la cuota
     *                   o bajar el plazo. Uno menor o igual reliquida solo, bajando
     *                   el plazo con la misma cuota.
     *  - modalidades:   nombres de modalidad en las que se ofrece esa elección,
     *                   comparados por prefijo (como TipoPrestamo::esVivienda).
     *                   '*' = todas (decisión de Bienestar: aplica a todas las
     *                   modalidades). En una modalidad que no esté en la lista,
     *                   todo abono reliquida solo, bajando el plazo.
     */
    'abono_extraordinario' => [
        'umbral_cuotas' => (int) env('ABONO_UMBRAL_CUOTAS', 10),
        'modalidades' => array_filter(array_map('trim', explode(',', (string) env('ABONO_MODALIDADES', '*')))),
    ],

    /*
     * Tope de la tasa de vivienda (Acuerdo Directivo 11 de 2023, que modifica el
     * art. 10 par. 1 y el art. 14 del Acuerdo 19 de 2022): la tasa es el IPC del
     * año anterior a la solicitud, sin superar el tope. Aplica a las cinco
     * modalidades de vivienda (las que empiezan por «Vivienda»); vehículo,
     * educación y calamidad no tienen tope.
     *
     *  - tope:          fracción anual (0,05 = 5 %). El IPC registrado en tasas_int
     *                   no se modifica: el tope se aplica al leer la tasa.
     *  - vigente_desde: fecha de expedición del Acuerdo. Las solicitudes anteriores
     *                   conservan su tasa.
     */
    'tasa_vivienda' => [
        'tope' => (float) env('TASA_VIVIENDA_TOPE', 0.05),
        'vigente_desde' => env('TASA_VIVIENDA_VIGENTE_DESDE', '2023-11-15'),
    ],

    /*
     * Seguros que el simulador muestra aparte de la cuota (monto × prima, sumado a
     * la cuota quincenal). SOLO EN VIVIENDA entran en la comparación con la
     * capacidad de endeudamiento (simulador y aprobación): cuota + seguros. En las
     * demás modalidades se compara únicamente la cuota.
     *
     * La prima de cada seguro y año se administra en la pantalla «Seguros»
     * (tabla primas_seguro) y cambia sin tocar código. Este valor es solo el
     * RESPALDO de vida de deudores cuando no hay prima registrada para ningún año.
     * Pendiente de confirmar con Bienestar: la periodicidad del seguro.
     */
    'seguro' => [
        'tasa_sobre_monto' => (float) env('SEGURO_TASA_SOBRE_MONTO', 0.0003231),
    ],

    /*
     * Capacidad de endeudamiento: una sola fuente de verdad para el simulador y la
     * aprobación de solicitudes (CapacidadEndeudamientoService).
     *
     *  - fuente: 'calculada' (por defecto) = 50 % de (salario devengado − descuentos
     *            de ley: salud, pensión, fondo de solidaridad; retención manual
     *            opcional). 'guardada' = el valor de empleados.capacidad_endeudamiento,
     *            como era antes. Cambiarlo no toca datos ni código. En ambos casos se
     *            restan las cuotas de los préstamos vigentes. La capacidad guardada
     *            sigue mostrándose como referencia.
     */
    'capacidad' => [
        'fuente' => env('CAPACIDAD_FUENTE', 'calculada'),
    ],

    /*
     * Simulador de crédito.
     *
     *  - plazo_tecnico_maximo:     tope de quincenas que se puede simular aunque
     *                              la modalidad permita más; evita tablas enormes.
     *  - descuentos_ley:           porcentajes sobre el salario. VERIFICAR CON LA NORMA
     *                              VIGENTE (salud y pensión del empleado, fondo de
     *                              solidaridad pensional por tramos de SMMLV). Cada tramo
     *                              incluye `desde` y excluye `hasta` (hasta null = sin tope).
     */
    'simulador' => [
        'plazo_tecnico_maximo' => (int) env('SIMULADOR_PLAZO_TECNICO_MAXIMO', 480),
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
