<?php

namespace Database\Factories;

use App\Enums\TipoInforme;
use App\Models\InformeGuardado;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InformeGuardado>
 */
class InformeGuardadoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tipo' => TipoInforme::Presupuesto,
            'parametros' => ['anio' => now()->year],
            'datos' => [
                'filas' => [
                    [
                        'modalidad' => 'Educación',
                        'monto_asignado' => 10_000_000.0,
                        'monto_comprometido' => 2_500_000.0,
                        'monto_disponible' => 7_500_000.0,
                        'porcentaje_ejecutado' => 25.0,
                    ],
                ],
                'totales' => [
                    'monto_asignado' => 10_000_000.0,
                    'monto_comprometido' => 2_500_000.0,
                    'monto_disponible' => 7_500_000.0,
                ],
            ],
            'fecha_corte' => now()->toDateString(),
            'generado_por' => Usuario::factory(),
        ];
    }

    public function prestamos(): static
    {
        return $this->state(fn () => [
            'tipo' => TipoInforme::Prestamos,
            'parametros' => ['id_tipo' => null, 'estado' => null, 'desde' => null, 'hasta' => null],
            'datos' => [
                'filas' => [
                    ['modalidad' => 'Educación', 'estado' => 'Vigente', 'total_prestamos' => 2, 'monto_total' => 4_000_000.0],
                ],
                'totales' => ['total_prestamos' => 2, 'monto_total' => 4_000_000.0],
            ],
        ]);
    }

    public function pagos(): static
    {
        return $this->state(fn () => [
            'tipo' => TipoInforme::Pagos,
            'parametros' => ['desde' => null, 'hasta' => null],
            'datos' => [
                'filas' => [
                    ['modalidad' => 'Educación', 'total_pagos' => 3, 'monto_total' => 300_000.0, 'monto_interes' => 30_000.0, 'monto_capital' => 270_000.0],
                ],
                'totales' => ['total_pagos' => 3, 'monto_total' => 300_000.0, 'monto_interes' => 30_000.0, 'monto_capital' => 270_000.0],
            ],
        ]);
    }

    public function cartera(): static
    {
        return $this->state(fn () => [
            'tipo' => TipoInforme::Cartera,
            'parametros' => [],
            'datos' => [
                'filas' => [
                    [
                        'id_prestamo' => 1,
                        'cedula_empleado' => '1000000001',
                        'empleado' => 'Empleado de prueba',
                        'modalidad' => 'Educación',
                        'numero_cuota' => 2,
                        'fecha_vencimiento' => now()->subDays(20)->toDateString(),
                        'valor_total' => 150_000.0,
                        'dias_mora' => 20,
                    ],
                ],
                'totales' => ['total_cuotas' => 1, 'valor_total' => 150_000.0],
            ],
        ]);
    }

    public function presupuesto(): static
    {
        return $this->state(fn () => []);
    }
}
