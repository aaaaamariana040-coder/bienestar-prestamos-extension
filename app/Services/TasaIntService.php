<?php

namespace App\Services;

use App\Models\TasaInt;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;

class TasaIntService
{
    public function __construct(private readonly AuditoriaService $auditoriaService)
    {
        //
    }

    /**
     * Cambia la tasa registrada de un año y deja en Auditoría el valor anterior
     * y el nuevo. Es el IPC real: el tope de vivienda no se aplica aquí sino al
     * leer la tasa (PrestamoService::tasaDetallada).
     *
     * @return bool false si el valor era el mismo y no se escribió nada
     */
    public function actualizar(TasaInt $tasa, float $tasaAnual, Usuario $actor): bool
    {
        $anterior = (float) $tasa->tasa_anual;

        if (abs($anterior - $tasaAnual) < 1e-9) {
            return false;
        }

        DB::transaction(function () use ($tasa, $tasaAnual, $actor, $anterior): void {
            $tasa->update(['tasa_anual' => $tasaAnual]);

            $this->auditoriaService->registrar(
                $actor,
                'Edición de tasa de interés',
                "Modalidad {$tasa->tipoPrestamo->nombre} — año {$tasa->anio}: tasa anterior ".number_format($anterior, 4, ',', '.').' % → nueva '.number_format($tasaAnual, 4, ',', '.').' %',
            );
        });

        return true;
    }
}
