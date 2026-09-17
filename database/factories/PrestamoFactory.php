<?php

namespace Database\Factories;

use App\Enums\EstadoPrestamo;
use App\Models\Empleado;
use App\Models\Prestamo;
use App\Models\TipoPrestamo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Prestamo>
 */
class PrestamoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $numCuotas = fake()->randomElement([24, 48, 72]);
        $montoAprobado = fake()->randomFloat(2, 1_000_000, 20_000_000);

        return [
            'id_solicitud' => null,
            'cedula_empleado' => Empleado::factory(),
            'id_tipo' => TipoPrestamo::factory(),
            'id_tasa' => null,
            'monto_aprobado' => $montoAprobado,
            'num_cuotas' => $numCuotas,
            'cuota_periodica' => round($montoAprobado / $numCuotas, 2),
            'fecha_autorizacion' => fake()->dateTimeBetween('-1 year', 'now'),
            'estado' => EstadoPrestamo::Vigente,
        ];
    }

    public function cancelado(): static
    {
        return $this->state(fn (array $attributes) => [
            'estado' => EstadoPrestamo::Cancelado,
        ]);
    }
}
