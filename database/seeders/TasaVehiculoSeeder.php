<?php

namespace Database\Seeders;

use App\Models\TasaVehiculo;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class TasaVehiculoSeeder extends Seeder
{
    /**
     * Precarga la matriz fija de puntos adicionales sobre el IPC para crédito de vehículo,
     * según la tabla 4.4 de reglas-negocio.md (Acuerdo Directivo No. 19/2022, Art. 10).
     */
    use WithoutModelEvents;

    /**
     * @var array<int, int>
     */
    private const PUNTOS_PRIMERA_SOLICITUD = [3 => 0, 4 => 1, 5 => 2, 7 => 3];

    /**
     * @var array<int, int>
     */
    private const PUNTOS_SEGUNDA_SOLICITUD = [3 => 2, 4 => 3, 5 => 4, 7 => 5];

    public function run(): void
    {
        foreach (self::PUNTOS_PRIMERA_SOLICITUD as $plazoAnios => $puntos) {
            TasaVehiculo::updateOrCreate(
                ['plazo_anios' => $plazoAnios, 'es_primera_solicitud' => true],
                ['puntos_adicionales_ipc' => $puntos],
            );
        }

        foreach (self::PUNTOS_SEGUNDA_SOLICITUD as $plazoAnios => $puntos) {
            TasaVehiculo::updateOrCreate(
                ['plazo_anios' => $plazoAnios, 'es_primera_solicitud' => false],
                ['puntos_adicionales_ipc' => $puntos],
            );
        }
    }
}
