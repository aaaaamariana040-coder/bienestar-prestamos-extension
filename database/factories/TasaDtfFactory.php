<?php

namespace Database\Factories;

use App\Models\TasaDtf;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TasaDtf>
 */
class TasaDtfFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fecha_vigencia' => fake()->unique()->date(),
            'valor' => fake()->randomFloat(4, 3, 12),
            'fuente_normativa' => 'Superfinanciera',
        ];
    }
}
