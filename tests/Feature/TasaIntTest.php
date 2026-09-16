<?php

namespace Tests\Feature;

use App\Models\TipoPrestamo;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TasaIntTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrador_puede_registrar_la_tasa_del_anio(): void
    {
        $administrador = Usuario::factory()->administrador()->create();
        $tipoPrestamo = TipoPrestamo::factory()->create();

        $this->actingAs($administrador)->post(route('tasas-int.store', $tipoPrestamo), [
            'anio' => 2026,
            'tasa_anual' => 4.85,
            'fuente_normativa' => 'Acuerdo Directivo No. 11/2023',
        ])->assertRedirect(route('tipos-prestamo.show', $tipoPrestamo));

        $this->assertDatabaseHas('tasas_int', [
            'id_tipo' => $tipoPrestamo->id_tipo,
            'anio' => 2026,
        ]);
    }

    public function test_no_se_puede_registrar_dos_tasas_para_el_mismo_anio_y_modalidad(): void
    {
        $administrador = Usuario::factory()->administrador()->create();
        $tipoPrestamo = TipoPrestamo::factory()->create();
        $tipoPrestamo->tasas()->create(['anio' => 2026, 'tasa_anual' => 4.85]);

        $this->actingAs($administrador)->post(route('tasas-int.store', $tipoPrestamo), [
            'anio' => 2026,
            'tasa_anual' => 5,
        ])->assertSessionHasErrors('anio');
    }

    public function test_funcionario_bienestar_no_puede_registrar_tasas(): void
    {
        $funcionario = Usuario::factory()->create();
        $tipoPrestamo = TipoPrestamo::factory()->create();

        $this->actingAs($funcionario)->post(route('tasas-int.store', $tipoPrestamo), [
            'anio' => 2026,
            'tasa_anual' => 4.85,
        ])->assertForbidden();
    }
}
