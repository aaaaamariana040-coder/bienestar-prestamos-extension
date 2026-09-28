<?php

namespace Tests\Feature;

use App\Models\TasaVehiculo;
use App\Models\TipoPrestamo;
use App\Models\Usuario;
use App\Services\TasaVehiculoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TasaVehiculoTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrador_puede_editar_los_puntos_adicionales_de_una_celda(): void
    {
        $administrador = Usuario::factory()->administrador()->create();
        $tasaVehiculo = TasaVehiculo::factory()->create(['plazo_anios' => 5, 'es_primera_solicitud' => false, 'puntos_adicionales_ipc' => 4]);

        $this->actingAs($administrador)
            ->put(route('tasa-vehiculo.update', $tasaVehiculo), ['puntos_adicionales_ipc' => 4.5])
            ->assertRedirect(route('tasa-vehiculo.index'));

        $this->assertSame('4.50', $tasaVehiculo->fresh()->puntos_adicionales_ipc);
    }

    public function test_funcionario_bienestar_puede_editar_la_matriz(): void
    {
        $funcionario = Usuario::factory()->create();
        $tasaVehiculo = TasaVehiculo::factory()->create(['puntos_adicionales_ipc' => 4]);

        $this->actingAs($funcionario)
            ->put(route('tasa-vehiculo.update', $tasaVehiculo), ['puntos_adicionales_ipc' => 4.5])
            ->assertRedirect(route('tasa-vehiculo.index'));

        $this->assertSame('4.50', $tasaVehiculo->fresh()->puntos_adicionales_ipc);
    }

    public function test_un_usuario_deshabilitado_con_sesion_activa_no_puede_editar_la_matriz(): void
    {
        $tasaVehiculo = TasaVehiculo::factory()->create(['puntos_adicionales_ipc' => 4]);

        foreach ([Usuario::factory()->administrador(), Usuario::factory()] as $factory) {
            $this->actingAs($factory->create(['habilitado' => false]))
                ->put(route('tasa-vehiculo.update', $tasaVehiculo), ['puntos_adicionales_ipc' => 4.5])
                ->assertForbidden();
        }

        $this->assertSame('4.00', $tasaVehiculo->fresh()->puntos_adicionales_ipc);
    }

    public function test_tasa_efectiva_combina_el_ipc_base_con_los_puntos_del_plazo_y_tipo_de_solicitud(): void
    {
        $vehiculo = TipoPrestamo::factory()->create(['nombre' => 'Vehículo']);
        $vehiculo->tasas()->create(['anio' => 2026, 'tasa_anual' => 5.1234]);

        TasaVehiculo::factory()->create(['plazo_anios' => 7, 'es_primera_solicitud' => false, 'puntos_adicionales_ipc' => 5]);

        $tasaEfectiva = app(TasaVehiculoService::class)->tasaEfectiva(2026, 7, false);

        $this->assertSame(10.1234, $tasaEfectiva);
    }

    public function test_tasa_efectiva_es_nula_si_no_hay_tasa_ipc_registrada_para_el_anio(): void
    {
        TipoPrestamo::factory()->create(['nombre' => 'Vehículo']);
        TasaVehiculo::factory()->create(['plazo_anios' => 3, 'es_primera_solicitud' => true, 'puntos_adicionales_ipc' => 0]);

        $tasaEfectiva = app(TasaVehiculoService::class)->tasaEfectiva(2026, 3, true);

        $this->assertNull($tasaEfectiva);
    }
}
