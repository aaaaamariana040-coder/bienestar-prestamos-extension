<?php

namespace App\Services;

use App\Models\TasaInt;
use App\Models\TasaVehiculo;

class TasaVehiculoService
{
    public function tasaEfectiva(int $anio, int $plazoAnios, bool $esPrimeraSolicitud): ?float
    {
        $componentes = $this->componentes($anio, $plazoAnios, $esPrimeraSolicitud);

        return $componentes === null ? null : round($componentes['base'] + (float) $componentes['puntos'], 4);
    }

    /**
     * Tasa base (IPC) y puntos adicionales por separado. `puntos` es null
     * cuando no hay fila para ese plazo y tipo de solicitud; tasaEfectiva()
     * lo toma como 0, pero el simulador lo avisa. Null si no hay tasa base.
     *
     * @return array{base: float, puntos: ?float}|null
     */
    public function componentes(int $anio, int $plazoAnios, bool $esPrimeraSolicitud): ?array
    {
        $tasaBase = TasaInt::whereHas('tipoPrestamo', fn ($query) => $query->where('nombre', 'Vehículo'))
            ->where('anio', $anio)
            ->value('tasa_anual');

        if ($tasaBase === null) {
            return null;
        }

        $puntos = TasaVehiculo::where('plazo_anios', $plazoAnios)
            ->where('es_primera_solicitud', $esPrimeraSolicitud)
            ->value('puntos_adicionales_ipc');

        return ['base' => (float) $tasaBase, 'puntos' => $puntos === null ? null : (float) $puntos];
    }
}
