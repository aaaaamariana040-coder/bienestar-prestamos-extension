<?php

namespace Database\Factories;

use App\Models\CuantiaSmmlv;
use App\Models\TipoPrestamo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CuantiaSmmlv>
 */
class CuantiaSmmlvFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id_tipo' => TipoPrestamo::factory(),
            'tramo_desde_smmlv' => 0,
            'tramo_hasta_smmlv' => 4,
            'cuantia_maxima_smmlv' => 130,
        ];
    }
}
