<?php

/*
 * TEMPORAL — herramienta de migración puntual (docs/conversion-q.md). Solo la usa el comando
 * datos:convertir-q; el aplicativo no lee formatos del AS/400. Aquí no hay datos personales.
 */

return [

    /*
     * Carpeta de los archivos de entrada y salida. Debe estar ignorada por git: el comando se niega
     * a correr si no lo está (verificar_git = true). Solo las pruebas cambian estos dos valores.
     */
    'carpeta' => storage_path('app/private/real'),

    'verificar_git' => true,

    'archivos' => [
        'nomina' => 'q09.csv',
        'cartera' => 'q15.csv',
        'empleados' => 'empleados-q09.csv',
        'prestamos' => 'prestamos-q15.csv',
    ],

    /*
     * Concepto de la cartera (Q15) => nombre de la modalidad del aplicativo (TipoPrestamoSeeder).
     * Solo lo seguro; lo dudoso va en null y sale en el informe como «sin mapeo».
     *
     * 30 (vivienda) es una APROXIMACIÓN: el comando no puede saber cuál de las modalidades de
     * vivienda es, y se asigna «compra de inmueble». 33 es la deshipoteca. 31 y 34: significado
     * desconocido (sin mapeo).
     */
    'mapeo' => [
        14 => 'Educación',
        15 => 'Calamidad doméstica y necesidades especiales',
        16 => 'Vehículo',
        26 => 'Vehículo',
        30 => 'Vivienda — compra de inmueble',
        31 => null,
        33 => 'Vivienda — cancelación total de hipoteca (deshipoteca)',
        34 => null,
    ],

    /*
     * Conceptos que no se cargan, con el motivo que se cuenta en el informe.
     */
    'excluidos' => [
        10 => 'crédito de seguros (no es del Fondo)',
        19 => 'computador (no existe como modalidad)',
    ],

    /*
     * Conceptos que son la segunda solicitud de la modalidad (cambia los puntos de la tasa de vehículo).
     */
    'segunda_solicitud' => [26],

    /*
     * Cargo (Q09) → tipo de vinculación, comparado sin mayúsculas ni tildes. Cualquier otro cargo
     * es Vinculado.
     */
    'cargos' => [
        'jubilado' => ['JUBILADO'],
        'docente' => ['PROFESOR'],
    ],

    /*
     * Tipo Contrato (Q09), sin mayúsculas ni tildes.
     *  - codigos: código de Tipo Contrato → tipo de vinculación. Cuando el código y el cargo se
     *    contradicen, manda el código (evidencia en docs/conversion-q.md).
     *  - ocasional_contiene: si el código no está en «codigos», el docente cuyo tipo de contrato
     *    contiene alguno de estos textos es Docente ocasional.
     *  - carrera / no_carrera: valores exactos que significan «de carrera» o «no de carrera».
     *    Vacíos hasta confirmar con Bienestar qué significa cada valor (el informe los lista con
     *    su conteo); mientras tanto de_carrera sale vacío.
     */
    'tipo_contrato' => [
        'codigos' => [
            6 => 'docente_ocasional',
            7 => 'jubilado',
            2 => 'docente',
            1 => 'vinculado',
        ],
        'ocasional_contiene' => ['OCASIONAL'],
        'carrera' => [],
        'no_carrera' => [],
    ],

];
