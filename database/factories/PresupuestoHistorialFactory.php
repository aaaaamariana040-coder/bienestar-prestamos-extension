<?php

namespace Database\Factories;

use App\Enums\CampoPresupuesto;
use App\Models\PresupuestoAnual;
use App\Models\PresupuestoHistorial;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PresupuestoHistorial>
 */
class PresupuestoHistorialFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id_presupuesto' => PresupuestoAnual::factory(),
            'campo' => CampoPresupuesto::MontoAsignado,
            'monto_anterior' => 1_000_000,
            'monto_nuevo' => 1_200_000,
            'motivo' => fake()->sentence(),
            'cedula_usu' => Usuario::factory(),
            'fecha' => now(),
        ];
    }
}
