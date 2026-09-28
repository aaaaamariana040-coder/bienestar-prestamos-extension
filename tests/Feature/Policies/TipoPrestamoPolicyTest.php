<?php

namespace Tests\Feature\Policies;

use App\Models\TipoPrestamo;
use App\Models\Usuario;
use App\Policies\TipoPrestamoPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TipoPrestamoPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_policy_rechaza_a_un_usuario_deshabilitado_sin_pasar_por_el_middleware(): void
    {
        $policy = new TipoPrestamoPolicy;
        $tipoPrestamo = TipoPrestamo::factory()->create();

        foreach ([Usuario::factory()->administrador(), Usuario::factory()] as $factory) {
            $deshabilitado = $factory->create(['habilitado' => false]);

            $this->assertFalse($policy->create($deshabilitado));
            $this->assertFalse($policy->update($deshabilitado, $tipoPrestamo));
            $this->assertFalse($policy->habilitar($deshabilitado, $tipoPrestamo));
            $this->assertFalse($policy->deshabilitar($deshabilitado, $tipoPrestamo));
        }
    }

    public function test_la_policy_permite_a_un_usuario_habilitado_gestionar_modalidades(): void
    {
        $policy = new TipoPrestamoPolicy;
        $tipoPrestamo = TipoPrestamo::factory()->create();

        foreach ([Usuario::factory()->administrador(), Usuario::factory()] as $factory) {
            $habilitado = $factory->create();

            $this->assertTrue($policy->create($habilitado));
            $this->assertTrue($policy->update($habilitado, $tipoPrestamo));
        }
    }
}
