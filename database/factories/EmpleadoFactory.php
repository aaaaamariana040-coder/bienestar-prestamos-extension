<?php

namespace Database\Factories;

use App\Enums\TipoVinculacion;
use App\Models\Empleado;
use App\Services\EmpleadoService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Empleado>
 */
class EmpleadoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $salario = fake()->numberBetween(1_300_000, 15_000_000);

        return [
            'cedula' => fake()->unique()->numerify('##########'),
            'nombre_completo' => fake()->name(),
            'tipo_vinculacion' => fake()->randomElement([TipoVinculacion::Vinculado, TipoVinculacion::Docente]),
            'cargo' => fake()->jobTitle(),
            'dependencia' => fake()->word(),
            'salario' => $salario,
            'fecha_vinculacion' => fake()->dateTimeBetween('-15 years', '-2 years'),
            'estado' => 'Activo',
            'capacidad_endeudamiento' => app(EmpleadoService::class)->capacidadEndeudamientoSugerida($salario),
        ];
    }

    /**
     * Indicate that the employee has withdrawn from the institution.
     */
    public function retirado(): static
    {
        return $this->state(fn (array $attributes) => [
            'estado' => 'Retirado',
        ]);
    }
}
