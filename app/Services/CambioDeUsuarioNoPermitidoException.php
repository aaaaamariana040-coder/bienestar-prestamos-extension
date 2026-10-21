<?php

namespace App\Services;

use RuntimeException;

/**
 * Un cambio en una cuenta rompe una salvaguarda (último administrador, auto-bloqueo,
 * al menos una cuenta que gestione usuarios); el mensaje se muestra tal cual.
 */
class CambioDeUsuarioNoPermitidoException extends RuntimeException
{
    //
}
