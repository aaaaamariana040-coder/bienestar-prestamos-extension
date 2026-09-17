<?php

namespace Database\Factories;

use App\Models\SmmlvAnual;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SmmlvAnual>
 */
class SmmlvAnualFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'anio' => fake()->unique()->numberBetween(2000, 2100),
            'valor' => fake()->randomFloat(2, 900_000, 2_000_000),
            'fuente_normativa' => 'Decreto anual del Gobierno Nacional',
        ];
    }
}
