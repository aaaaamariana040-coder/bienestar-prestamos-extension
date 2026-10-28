<?php

/*
 * Proceso de la quincena automática. Todo lo que depende de una decisión de
 * Bienestar Social todavía abierta es configurable; el valor predeterminado
 * siempre es la opción segura (nada se paga solo, nada corre solo).
 * Ver docs/quincena-automatica.md.
 */

return [

    /*
     * Cuándo vence una cuota:
     *  - calendario:    la quincena calendario (1-15 y 16-fin de mes) que
     *                   contiene la fecha de vencimiento de la cuota.
     *  - cada_15_dias:  ciclos de 15 días contados desde la autorización del
     *                   préstamo.
     */
    'regla_vencimiento' => env('QUINCENA_REGLA_VENCIMIENTO', 'calendario'),

    /*
     * Qué hace el proceso real:
     *  - confirmar:   solo registra el proceso con las cuotas pendientes de
     *                 confirmar; no crea pagos.
     *  - automatico:  crea los pagos con origen Automático a nombre del
     *                 usuario Sistema.
     */
    'modo_marcado' => env('QUINCENA_MODO_MARCADO', 'confirmar'),

    /*
     * Desde cuándo corre la primera cuota: desde_aprobacion o desde_desembolso.
     * desde_desembolso (decisión de Bienestar): las cuotas de un préstamo nuevo no
     * se cobran hasta su desembolso, y la primera vence en el primer 15 o último
     * día de mes posterior al desembolso. desde_aprobacion: regla anterior.
     */
    'inicio_primera_cuota' => env('QUINCENA_INICIO_PRIMERA_CUOTA', 'desde_desembolso'),

    /*
     * Cierre de novedades de nómina — PROVISIONAL, a la espera de la respuesta de
     * Bienestar: días calendario antes de cada vencimiento (día 15 y último día
     * del mes). Es solo informativo, se muestra en la vista previa de la quincena
     * y no bloquea ni cambia ninguna operación.
     */
    'cierre_novedades_dias_antes' => (int) env('QUINCENA_CIERRE_NOVEDADES_DIAS_ANTES', 2),

    /*
     * Primera cuota de un crédito nuevo: el desembolso que se hace el mismo día del
     * cierre de novedades de una quincena, ¿alcanza a reportarse en ella? Pendiente de
     * confirmar con Bienestar. false: no entra y la primera cuota pasa a la quincena
     * siguiente; true: sí entra.
     */
    'desembolso_dia_cierre_entra' => (bool) env('QUINCENA_DESEMBOLSO_DIA_CIERRE_ENTRA', false),

    /*
     * Si el programador (schedule) ejecuta el proceso real el 15 y el último
     * día de cada mes. Apagado por defecto.
     */
    /*
     * Aviso informativo para una cuota excluida del descuento por nómina
     * (licencia no remunerada, situación administrativa sin pago de nómina,
     * etc.): el aplicativo no controla ese pago, que depende de otra área.
     * {lugar} se reemplaza por aviso_cuota_excluida_lugar. Solo informa: no
     * cambia cálculos, estados, mora ni cierres.
     */
    'aviso_cuota_excluida' => env('QUINCENA_AVISO_CUOTA_EXCLUIDA', 'Esta cuota no se descontó por nómina. Debe pagarse por ventanilla: {lugar}.'),

    'aviso_cuota_excluida_lugar' => env('QUINCENA_AVISO_CUOTA_EXCLUIDA_LUGAR', 'consigne en la cuenta del Fondo de Bienestar Social el valor de la cuota, con la factura que genera Costeo y Facturación (puede solicitarla allí o a Bienestar Social), y entregue el soporte de pago a Bienestar Social para legalizarlo en Tesorería'),

    'programador_activo' => (bool) env('QUINCENA_PROGRAMADOR_ACTIVO', false),

];
