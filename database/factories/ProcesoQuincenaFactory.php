<?php

namespace Database\Factories;

use App\Enums\EstadoProcesoQuincena;
use App\Enums\ModoMarcado;
use App\Enums\TipoProcesoQuincena;
use App\Models\ProcesoQuincena;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProcesoQuincena>
 */
class ProcesoQuincenaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'anio' => fake()->numberBetween(2024, 2030),
            'numero_quincena' => fake()->numberBetween(1, 24),
            'tipo' => TipoProcesoQuincena::Real,
            'estado' => EstadoProcesoQuincena::PendienteConfirmacion,
            'modo_marcado' => ModoMarcado::Confirmar,
            'cedula_usu' => Usuario::factory(),
            'iniciado_en' => now(),
            'finalizado_en' => now(),
            'resumen' => [],
        ];
    }
}
