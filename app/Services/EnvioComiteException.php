<?php

namespace App\Services;

use RuntimeException;

/**
 * El envío al comité no se puede (o no se pudo) hacer; el mensaje está pensado
 * para mostrarse tal cual al operador.
 */
class EnvioComiteException extends RuntimeException
{
    //
}
