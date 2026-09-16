<?php

namespace Database\Factories;

use App\Models\TasaInt;
use App\Models\TipoPrestamo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TasaInt>
 */
class TasaIntFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id_tipo' => TipoPrestamo::factory(),
            'anio' => fake()->unique()->numberBetween(2000, 2100),
            'tasa_anual' => fake()->randomFloat(4, 1, 5),
            'fuente_normativa' => 'Acuerdo Directivo No. 11/2023',
        ];
    }
}
