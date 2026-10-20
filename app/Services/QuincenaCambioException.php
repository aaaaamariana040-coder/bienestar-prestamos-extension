<?php

namespace App\Services;

use RuntimeException;

/**
 * Entre la vista previa y la confirmación cambió el conjunto de cuotas de la
 * quincena (un pago manual, una reliquidación o una cancelación). No se paga
 * nada: la persona debe revisar la vista previa actualizada.
 */
class QuincenaCambioException extends RuntimeException
{
    /**
     * @param  list<string>  $diferencias
     */
    public function __construct(public readonly array $diferencias)
    {
        parent::__construct('La quincena cambió desde que se mostró la vista previa. Revise las diferencias y vuelva a confirmar.');
    }
}
