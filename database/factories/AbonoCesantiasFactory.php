<?php

namespace Database\Factories;

use App\Models\AbonoCesantias;
use App\Models\Prestamo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AbonoCesantias>
 */
class AbonoCesantiasFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id_prestamo' => Prestamo::factory(),
            'anio' => fake()->numberBetween(2020, 2026),
            'porcentaje' => 50,
            'monto' => fake()->randomFloat(2, 500_000, 5_000_000),
            'fecha_abono' => fake()->dateTimeBetween('-1 year', 'now'),
        ];
    }
}
