<?php

namespace Database\Factories;

use App\Enums\EstadoDesembolso;
use App\Models\Desembolso;
use App\Models\Prestamo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Desembolso>
 */
class DesembolsoFactory extends Factory
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
            'id_proveedor' => null,
            'numero_tramo' => 1,
            'porcentaje' => 100,
            'fecha' => fake()->dateTimeBetween('-1 year', 'now'),
            'valor' => fake()->randomFloat(2, 1_000_000, 20_000_000),
            'requiere_inspeccion' => false,
            'inspeccion_aprobada' => null,
            'estado' => EstadoDesembolso::Realizado,
        ];
    }
}
