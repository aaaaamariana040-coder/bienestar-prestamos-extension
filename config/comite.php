<?php

/*
 * Envío de cada solicitud de crédito al Comité de Bienestar Social por correo.
 *
 * Todos los valores son PROVISIONALES (Bienestar Social aún no define los
 * correos, el remitente ni los textos) y se sobrescriben desde .env. Los
 * dominios .test no existen: sirven para que en desarrollo nada salga de verdad.
 * En producción el envío se bloquea mientras sigan los correos provisionales o
 * el mailer sea log/array. Ver docs/restricciones.md.
 */

$lista = fn (string $valor): array => array_values(array_filter(array_map('trim', explode(',', $valor))));

return [

    /*
     * Remitente del correo. Si no se define, se usa el de config/mail.php.
     */
    'remitente' => [
        'direccion' => env('COMITE_REMITENTE_DIRECCION', 'bienestar@example.test'),
        'nombre' => env('COMITE_REMITENTE_NOMBRE', 'Bienestar Social — Préstamos'),
    ],

    /*
     * Correos del comité (separados por coma) y copia a Bienestar. Sin
     * destinatarios no se puede enviar.
     */
    'destinatarios' => $lista((string) env('COMITE_DESTINATARIOS', 'comite@example.test')),
    'copia' => $lista((string) env('COMITE_COPIA', 'bienestar@example.test')),

    /*
     * Asunto y textos. Marcadores disponibles: {solicitud}, {empleado},
     * {cedula}, {modalidad}, {monto}. El cuerpo con los datos de la solicitud
     * va entre la introducción y el cierre.
     */
    'asunto' => env('COMITE_ASUNTO', 'Solicitud de crédito #{solicitud} — {modalidad} — {empleado}'),
    'texto_introduccion' => env('COMITE_TEXTO_INTRODUCCION', 'Señores miembros del Comité de Bienestar Social: se somete a su consideración la siguiente solicitud de crédito.'),
    'texto_cierre' => env('COMITE_TEXTO_CIERRE', 'Los documentos de soporte van adjuntos. Agradecemos su respuesta por este mismo medio.'),

    /*
     * Adjuntos: tamaño total máximo en MB (los SMTP suelen cortar hacia los
     * 20-25 MB y el base64 agrega ~33 %) y si se exige al menos un documento.
     */
    'adjuntos' => [
        'maximo_total_mb' => (float) env('COMITE_ADJUNTOS_MAX_MB', 15),
        'exigir_documentos' => (bool) env('COMITE_EXIGIR_DOCUMENTOS', false),
    ],

    /*
     * Incluir en el cuerpo las observaciones de incumplimientos que detecta el
     * sistema (los mismos motivos por los que no se podría aprobar).
     */
    'incluir_observaciones' => (bool) env('COMITE_INCLUIR_OBSERVACIONES', true),

    /*
     * Segundos durante los cuales un segundo envío de la misma solicitud se
     * considera un doble clic y se rechaza, aun con el reenvío confirmado.
     */
    'segundos_entre_envios' => (int) env('COMITE_SEGUNDOS_ENTRE_ENVIOS', 60),

];
