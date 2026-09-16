<?php

namespace Database\Seeders;

use App\Enums\UnidadPlazo;
use App\Models\TipoPrestamo;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class TipoPrestamoSeeder extends Seeder
{
    /**
     * Precarga el catálogo de modalidades y sus cuantías máximas por tramo salarial,
     * según reglas-negocio.md sección 3 y 4 (Acuerdo Directivo No. 19/2022).
     *
     * plazo_maximo se almacena en cuotas quincenales (2 quincenas = 1 mes), la misma
     * unidad que usan prestamo.num_cuotas y cuota. unidad_plazo indica en qué unidad
     * debe mostrarse (años para vivienda y vehículo, meses para educación y calamidad).
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
            ['nombre' => 'Vivienda — compra de inmueble', 'plazo_maximo' => 480, 'unidad_plazo' => UnidadPlazo::Anios, 'tramos' => $tramosVivienda],
            ['nombre' => 'Vivienda — construcción', 'plazo_maximo' => 480, 'unidad_plazo' => UnidadPlazo::Anios, 'tramos' => $tramosVivienda],
            ['nombre' => 'Vivienda — cancelación total de hipoteca (deshipoteca)', 'plazo_maximo' => 480, 'unidad_plazo' => UnidadPlazo::Anios, 'tramos' => $tramosVivienda],
            ['nombre' => 'Vivienda — reparaciones o mejoras', 'plazo_maximo' => 480, 'unidad_plazo' => UnidadPlazo::Anios, 'tramos' => $tramosVivienda],
            ['nombre' => 'Vivienda — cambio de vivienda', 'plazo_maximo' => 480, 'unidad_plazo' => UnidadPlazo::Anios, 'tramos' => $tramosVivienda],
            ['nombre' => 'Educación', 'plazo_maximo' => 72, 'unidad_plazo' => UnidadPlazo::Meses, 'tramos' => $tramosEducacion],
            ['nombre' => 'Calamidad doméstica y necesidades especiales', 'plazo_maximo' => 72, 'unidad_plazo' => UnidadPlazo::Meses, 'tramos' => $tramosCalamidad],
            ['nombre' => 'Vehículo', 'plazo_maximo' => 168, 'unidad_plazo' => UnidadPlazo::Anios, 'tramos' => $tramosVehiculo],
        ];

        foreach ($modalidades as $modalidad) {
            $tipoPrestamo = TipoPrestamo::updateOrCreate(
                ['nombre' => $modalidad['nombre']],
                [
                    'plazo_maximo' => $modalidad['plazo_maximo'],
                    'unidad_plazo' => $modalidad['unidad_plazo'],
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
