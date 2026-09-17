<?php

namespace Database\Factories;

use App\Enums\EstadoCuota;
use App\Models\Cuota;
use App\Models\Prestamo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cuota>
 */
class CuotaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $capital = fake()->randomFloat(2, 50_000, 500_000);
        $interes = fake()->randomFloat(2, 0, 50_000);

        return [
            'id_prestamo' => Prestamo::factory(),
            'numero_cuota' => fake()->numberBetween(1, 60),
            'fecha_vencimiento' => fake()->dateTimeBetween('now', '+3 years'),
            'valor_capital' => $capital,
            'valor_interes' => $interes,
            'valor_total' => round($capital + $interes, 2),
            'estado' => EstadoCuota::Pendiente,
        ];
    }

    public function pagada(): static
    {
        return $this->state(fn (array $attributes) => [
            'estado' => EstadoCuota::Pagada,
        ]);
    }
}
