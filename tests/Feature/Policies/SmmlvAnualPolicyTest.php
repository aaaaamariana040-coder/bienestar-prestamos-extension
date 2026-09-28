<?php

namespace Tests\Feature\Policies;

use App\Models\Usuario;
use App\Policies\SmmlvAnualPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmmlvAnualPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_policy_rechaza_a_un_usuario_deshabilitado_sin_pasar_por_el_middleware(): void
    {
        $policy = new SmmlvAnualPolicy;

        foreach ([Usuario::factory()->administrador(), Usuario::factory()] as $factory) {
            $this->assertFalse($policy->create($factory->create(['habilitado' => false])));
        }
    }

    public function test_la_policy_permite_a_un_usuario_habilitado_registrar_el_smmlv(): void
    {
        $policy = new SmmlvAnualPolicy;

        foreach ([Usuario::factory()->administrador(), Usuario::factory()] as $factory) {
            $this->assertTrue($policy->create($factory->create()));
        }
    }
}
