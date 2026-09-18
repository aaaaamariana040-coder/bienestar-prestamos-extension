<?php

namespace Database\Factories;

use App\Models\Auditoria;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Auditoria>
 */
class AuditoriaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cedula_usu' => Usuario::factory(),
            'fecha' => now()->toDateString(),
            'hora' => now()->toTimeString(),
            'funcion_realizada' => fake()->randomElement([
                'Registro de solicitud de crédito',
                'Aprobación de solicitud de crédito',
                'Registro de desembolso',
                'Pago de cuota',
            ]),
            'detalle' => fake()->sentence(),
        ];
    }
}
