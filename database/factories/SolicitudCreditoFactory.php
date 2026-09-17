<?php

namespace Database\Factories;

use App\Enums\EstadoSolicitud;
use App\Models\Empleado;
use App\Models\SolicitudCredito;
use App\Models\TipoPrestamo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SolicitudCredito>
 */
class SolicitudCreditoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cedula_empleado' => Empleado::factory(),
            'id_tipo' => TipoPrestamo::factory(),
            'monto_solicitado' => fake()->randomFloat(2, 1_000_000, 20_000_000),
            'plazo_solicitado' => fake()->randomElement([24, 48, 72]),
            'fecha_solicitud' => fake()->dateTimeBetween('-1 year', 'now'),
            'estado' => EstadoSolicitud::Radicada,
        ];
    }

    public function aprobada(): static
    {
        return $this->state(fn (array $attributes) => [
            'estado' => EstadoSolicitud::Aprobada,
            'fecha_decision' => now()->toDateString(),
        ]);
    }

    public function rechazada(): static
    {
        return $this->state(fn (array $attributes) => [
            'estado' => EstadoSolicitud::Rechazada,
            'fecha_decision' => now()->toDateString(),
            'motivo_rechazo' => fake()->sentence(),
        ]);
    }
}
