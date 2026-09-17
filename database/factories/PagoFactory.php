<?php

namespace Database\Factories;

use App\Enums\TipoPago;
use App\Models\Pago;
use App\Models\Prestamo;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Pago>
 */
class PagoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $interes = fake()->randomFloat(2, 0, 50_000);
        $capital = fake()->randomFloat(2, 50_000, 500_000);

        return [
            'id_prestamo' => Prestamo::factory(),
            'id_cuota' => null,
            'fecha_pago' => fake()->dateTimeBetween('-1 year', 'now'),
            'monto' => round($interes + $capital, 2),
            'tipo' => TipoPago::AbonoExtraordinario,
            'monto_aplicado_interes' => $interes,
            'monto_aplicado_capital' => $capital,
            'cedula_usu' => Usuario::factory(),
        ];
    }
}
