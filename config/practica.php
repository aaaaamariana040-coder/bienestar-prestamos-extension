<?php

/*
 * Datos de práctica (docs/guia-practica.md): PracticaSeeder y el comando practica:reiniciar
 * solo funcionan si PRACTICA_HABILITADA=true en el .env (por defecto false) y nunca con
 * APP_ENV=production.
 */

return [

    'habilitada' => (bool) env('PRACTICA_HABILITADA', false),

];
