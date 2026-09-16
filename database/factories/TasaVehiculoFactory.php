<?php

namespace Database\Factories;

use App\Models\TasaVehiculo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TasaVehiculo>
 */
class TasaVehiculoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'plazo_anios' => fake()->unique()->randomElement([3, 4, 5, 7]),
            'es_primera_solicitud' => true,
            'puntos_adicionales_ipc' => fake()->randomFloat(2, 0, 5),
        ];
    }
}
