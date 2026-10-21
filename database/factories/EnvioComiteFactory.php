<?php

namespace Database\Factories;

use App\Models\EnvioComite;
use App\Models\SolicitudCredito;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EnvioComite>
 */
class EnvioComiteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id_solicitud' => SolicitudCredito::factory(),
            'cedula_usu' => Usuario::factory(),
            'enviado_en' => now(),
            'destinatarios' => ['comite@example.test'],
            'copia' => ['bienestar@example.test'],
            'asunto' => 'Solicitud de crédito de prueba',
            'adjuntos' => [],
            'datos' => [],
            'mailer' => 'smtp',
            'simulado' => false,
            'es_reenvio' => false,
        ];
    }

    public function simulado(): static
    {
        return $this->state(fn (array $atributos) => ['mailer' => 'log', 'simulado' => true]);
    }

    public function reenvio(): static
    {
        return $this->state(fn (array $atributos) => ['es_reenvio' => true]);
    }
}
