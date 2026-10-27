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
        $nominal = fake()->randomFloat(4, 3, 12);

        return [
            'fecha_vigencia' => fake()->unique()->date(),
            'dtf_nominal' => $nominal,
            'valor' => round(((1 / (1 - $nominal / 100 / 4)) ** 4 - 1) * 100, 4),
            'fuente_normativa' => 'Superfinanciera',
        ];
    }
}
