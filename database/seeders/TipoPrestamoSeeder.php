<?php

namespace Database\Seeders;

use App\Models\TipoPrestamo;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class TipoPrestamoSeeder extends Seeder
{
    /**
     * Precarga el catálogo de modalidades y sus cuantías máximas por tramo salarial,
     * según reglas-negocio.md sección 3 y 4 (Acuerdo Directivo No. 19/2022).
     *
     * plazo_maximo se almacena siempre en quincenas (24 por año), la misma unidad que
     * usan prestamo.num_cuotas y cuota: vivienda 480 (20 años), vehículo 168 (7 años),
     * educación y calamidad 72 (36 meses).
     * No se precargan tasa_int: el IPC se registra cada año desde la pantalla de la modalidad.
     */
    use WithoutModelEvents;

    public function run(): void
    {
        $tramosVivienda = [
            ['tramo_desde_smmlv' => 0, 'tramo_hasta_smmlv' => 4, 'cuantia_maxima_smmlv' => 130],
            ['tramo_desde_smmlv' => 4, 'tramo_hasta_smmlv' => 8, 'cuantia_maxima_smmlv' => 190],
            ['tramo_desde_smmlv' => 8, 'tramo_hasta_smmlv' => null, 'cuantia_maxima_smmlv' => 220],
        ];

        $tramosEducacion = [
            ['tramo_desde_smmlv' => 0, 'tramo_hasta_smmlv' => 4, 'cuantia_maxima_smmlv' => 17.5],
            ['tramo_desde_smmlv' => 4, 'tramo_hasta_smmlv' => 8, 'cuantia_maxima_smmlv' => 19],
            ['tramo_desde_smmlv' => 8, 'tramo_hasta_smmlv' => null, 'cuantia_maxima_smmlv' => 20],
        ];

        $tramosCalamidad = [
            ['tramo_desde_smmlv' => 0, 'tramo_hasta_smmlv' => 4, 'cuantia_maxima_smmlv' => 5],
            ['tramo_desde_smmlv' => 4, 'tramo_hasta_smmlv' => 8, 'cuantia_maxima_smmlv' => 10],
            ['tramo_desde_smmlv' => 8, 'tramo_hasta_smmlv' => null, 'cuantia_maxima_smmlv' => 15],
        ];

        $tramosVehiculo = [
            ['tramo_desde_smmlv' => 0, 'tramo_hasta_smmlv' => null, 'cuantia_maxima_smmlv' => 50],
        ];

        $modalidades = [
            ['nombre' => 'Vivienda — compra de inmueble', 'plazo_maximo' => 480, 'tramos' => $tramosVivienda],
            ['nombre' => 'Vivienda — construcción', 'plazo_maximo' => 480, 'tramos' => $tramosVivienda],
            ['nombre' => 'Vivienda — cancelación total de hipoteca (deshipoteca)', 'plazo_maximo' => 480, 'tramos' => $tramosVivienda],
            ['nombre' => 'Vivienda — reparaciones o mejoras', 'plazo_maximo' => 480, 'tramos' => $tramosVivienda],
            ['nombre' => 'Vivienda — cambio de vivienda', 'plazo_maximo' => 480, 'tramos' => $tramosVivienda],
            ['nombre' => 'Educación', 'plazo_maximo' => 72, 'tramos' => $tramosEducacion],
            ['nombre' => 'Calamidad doméstica y necesidades especiales', 'plazo_maximo' => 72, 'tramos' => $tramosCalamidad],
            ['nombre' => 'Vehículo', 'plazo_maximo' => 168, 'tramos' => $tramosVehiculo],
        ];

        foreach ($modalidades as $modalidad) {
            $tipoPrestamo = TipoPrestamo::updateOrCreate(
                ['nombre' => $modalidad['nombre']],
                [
                    'plazo_maximo' => $modalidad['plazo_maximo'],
                    'habilitada' => true,
                    'fuente_normativa' => 'Acuerdo Directivo No. 19/2022',
                ],
            );

            if ($tipoPrestamo->cuantias()->exists()) {
                continue;
            }

            foreach ($modalidad['tramos'] as $tramo) {
                $tipoPrestamo->cuantias()->create($tramo);
            }
        }
    }
}
