<?php

namespace Tests\Feature\Policies;

use App\Models\TasaVehiculo;
use App\Models\Usuario;
use App\Policies\TasaVehiculoPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TasaVehiculoPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_policy_rechaza_a_un_usuario_deshabilitado_sin_pasar_por_el_middleware(): void
    {
        $policy = new TasaVehiculoPolicy;
        $tasaVehiculo = TasaVehiculo::factory()->create();

        foreach ([Usuario::factory()->administrador(), Usuario::factory()] as $factory) {
            $this->assertFalse($policy->update($factory->create(['habilitado' => false]), $tasaVehiculo));
        }
    }

    public function test_la_policy_permite_a_un_usuario_habilitado_editar_la_matriz(): void
    {
        $policy = new TasaVehiculoPolicy;
        $tasaVehiculo = TasaVehiculo::factory()->create();

        foreach ([Usuario::factory()->administrador(), Usuario::factory()] as $factory) {
            $this->assertTrue($policy->update($factory->create(), $tasaVehiculo));
        }
    }
}
