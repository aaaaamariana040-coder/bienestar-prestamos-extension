<?php

namespace Database\Factories;

use App\Enums\ConceptoSeguro;
use App\Models\PrimaSeguro;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrimaSeguro>
 */
class PrimaSeguroFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'concepto' => ConceptoSeguro::VidaDeudores,
            'anio' => fake()->unique()->numberBetween(2100, 2200),
            'tasa' => 0.0003231,
        ];
    }
}
