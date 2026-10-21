<?php

/*
 * Permisos por rol. Cada permiso se registra como Gate (AppServiceProvider), así
 * que las vistas y los controladores usan @can / Gate::authorize con el nombre
 * del permiso. Cuando haya permisos por cuenta, solo cambia Usuario::tienePermiso().
 *
 * La gestión de usuarios y la auditoría siguen decidiéndose por rol (policies).
 */

return [

    'por_rol' => [
        'ADMINISTRADOR' => [
            'solicitudes.enviar-comite',
        ],
        'FUNCIONARIO_BIENESTAR' => [
            'solicitudes.enviar-comite',
        ],
    ],

];
