<?php

namespace Tests\Feature;

use App\Models\SmmlvAnual;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmmlvAnualTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrador_puede_registrar_el_valor_del_smmlv_de_un_anio(): void
    {
        $administrador = Usuario::factory()->administrador()->create();

        $this->actingAs($administrador)->post(route('smmlv.store'), [
            'anio' => 2026,
            'valor' => 1_423_500,
            'fuente_normativa' => 'Decreto 2026 de 2025',
        ])->assertRedirect(route('smmlv.index'));

        $this->assertDatabaseHas('smmlv_anual', [
            'anio' => 2026,
            'valor' => 1_423_500,
            'fuente_normativa' => 'Decreto 2026 de 2025',
        ]);
    }

    public function test_funcionario_bienestar_puede_registrar_el_valor_del_smmlv(): void
    {
        $funcionario = Usuario::factory()->create();

        $this->actingAs($funcionario)->post(route('smmlv.store'), [
            'anio' => 2026,
            'valor' => 1_423_500,
        ])->assertRedirect(route('smmlv.index'));

        $this->assertDatabaseHas('smmlv_anual', ['anio' => 2026, 'valor' => 1_423_500]);
    }

    public function test_un_usuario_deshabilitado_con_sesion_activa_no_puede_registrar_el_valor_del_smmlv(): void
    {
        foreach ([Usuario::factory()->administrador(), Usuario::factory()] as $factory) {
            $this->actingAs($factory->create(['habilitado' => false]))
                ->post(route('smmlv.store'), [
                    'anio' => 2026,
                    'valor' => 1_423_500,
                ])->assertRedirect(route('login'));
        }

        $this->assertDatabaseMissing('smmlv_anual', ['anio' => 2026]);
    }

    public function test_no_se_puede_registrar_dos_valores_para_el_mismo_anio(): void
    {
        $administrador = Usuario::factory()->administrador()->create();
        SmmlvAnual::factory()->create(['anio' => 2026]);

        $this->actingAs($administrador)->post(route('smmlv.store'), [
            'anio' => 2026,
            'valor' => 1_500_000,
        ])->assertSessionHasErrors('anio');
    }

    public function test_cualquier_usuario_autenticado_puede_consultar_los_valores_registrados(): void
    {
        $funcionario = Usuario::factory()->create();
        SmmlvAnual::factory()->create(['anio' => 2026]);

        $this->actingAs($funcionario)->get(route('smmlv.index'))
            ->assertOk()
            ->assertSee('2026');
    }

    public function test_invitado_es_redirigido_a_login(): void
    {
        $this->get(route('smmlv.index'))->assertRedirect(route('login'));
    }
}
