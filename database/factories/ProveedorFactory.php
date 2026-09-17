<?php

namespace Database\Factories;

use App\Models\Proveedor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Proveedor>
 */
class ProveedorFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id_proveedor' => fake()->unique()->numerify('PROV-####'),
            'nit' => fake()->unique()->numerify('##########-#'),
            'nombre_empresa' => fake()->company(),
            'direccion' => fake()->address(),
            'num_cuenta_bancaria' => fake()->numerify('##########'),
        ];
    }
}
