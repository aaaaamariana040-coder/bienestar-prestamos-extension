<?php

namespace Database\Factories;

use App\Enums\UnidadPlazo;
use App\Models\TipoPrestamo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TipoPrestamo>
 */
class TipoPrestamoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre' => fake()->unique()->words(3, true),
            'descripcion' => fake()->sentence(),
            'plazo_maximo' => fake()->randomElement([72, 120, 168, 480]),
            'unidad_plazo' => fake()->randomElement(UnidadPlazo::cases()),
            'habilitada' => true,
            'fuente_normativa' => 'Acuerdo Directivo No. 19/2022',
        ];
    }
}
