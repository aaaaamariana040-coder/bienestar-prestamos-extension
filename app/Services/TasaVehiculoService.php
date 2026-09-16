<?php

namespace App\Services;

use App\Models\TasaInt;
use App\Models\TasaVehiculo;

class TasaVehiculoService
{
    public function tasaEfectiva(int $anio, int $plazoAnios, bool $esPrimeraSolicitud): ?float
    {
        $tasaBase = TasaInt::whereHas('tipoPrestamo', fn ($query) => $query->where('nombre', 'Vehículo'))
            ->where('anio', $anio)
            ->value('tasa_anual');

        if ($tasaBase === null) {
            return null;
        }

        $puntosAdicionales = TasaVehiculo::where('plazo_anios', $plazoAnios)
            ->where('es_primera_solicitud', $esPrimeraSolicitud)
            ->value('puntos_adicionales_ipc') ?? 0;

        return round((float) $tasaBase + (float) $puntosAdicionales, 4);
    }
}
