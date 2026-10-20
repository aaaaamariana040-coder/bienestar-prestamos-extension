<?php

namespace App\Services;

use App\Models\ProcesoQuincena;
use RuntimeException;

/**
 * La quincena ya tiene un proceso real confirmado (por una persona o por el
 * proceso automático): no se puede confirmar de nuevo.
 */
class QuincenaYaConfirmadaException extends RuntimeException
{
    public function __construct(public readonly ProcesoQuincena $proceso)
    {
        $quien = $proceso->usuario?->nombre_completo ?: 'el sistema';
        $cuando = $proceso->finalizado_en?->setTimezone(CalendarioQuincenalService::ZONA)->format('d/m/Y H:i') ?? 'una fecha desconocida';

        parent::__construct("La quincena {$proceso->numero_quincena} de {$proceso->anio} ya fue confirmada por {$quien} el {$cuando}.");
    }
}
