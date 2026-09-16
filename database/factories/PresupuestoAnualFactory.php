<?php

namespace Database\Factories;

use App\Models\PresupuestoAnual;
use App\Models\TipoPrestamo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PresupuestoAnual>
 */
class PresupuestoAnualFactory extends Factory
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
            'id_tipo' => TipoPrestamo::factory(),
            'monto_asignado' => fake()->randomFloat(2, 1_000_000, 100_000_000),
            'monto_comprometido' => 0,
        ];
    }
}
