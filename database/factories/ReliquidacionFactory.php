<?php

namespace Database\Factories;

use App\Enums\MotivoReliquidacion;
use App\Models\Prestamo;
use App\Models\Reliquidacion;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reliquidacion>
 */
class ReliquidacionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $saldoAnterior = fake()->randomFloat(2, 1_000_000, 20_000_000);

        return [
            'id_prestamo' => Prestamo::factory(),
            'motivo' => MotivoReliquidacion::AbonoExtraordinario,
            'saldo_anterior' => $saldoAnterior,
            'saldo_nuevo' => round($saldoAnterior * 0.7, 2),
            'tasa_aplicada' => fake()->randomFloat(4, 1, 5),
            'fecha' => fake()->dateTimeBetween('-1 year', 'now'),
            'cedula_usu' => Usuario::factory(),
        ];
    }
}
