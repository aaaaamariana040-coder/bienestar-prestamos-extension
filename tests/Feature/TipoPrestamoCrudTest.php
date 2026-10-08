<?php

namespace Tests\Feature;

use App\Models\TipoPrestamo;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TipoPrestamoCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_cualquier_usuario_autenticado_puede_consultar_modalidades(): void
    {
        $funcionario = Usuario::factory()->create();
        $tipoPrestamo = TipoPrestamo::factory()->create();

        $this->actingAs($funcionario)->get(route('tipos-prestamo.index'))->assertOk();
        $this->actingAs($funcionario)->get(route('tipos-prestamo.show', $tipoPrestamo))->assertOk();
    }

    public function test_administrador_puede_crear_una_modalidad(): void
    {
        $administrador = Usuario::factory()->administrador()->create();

        $this->actingAs($administrador)->post(route('tipos-prestamo.store'), [
            'nombre' => 'Educación',
            'descripcion' => 'Crédito educativo',
            'plazo_maximo' => 72,
            'fuente_normativa' => 'Acuerdo Directivo No. 19/2022',
        ])->assertRedirect(route('tipos-prestamo.index'));

        $this->assertDatabaseHas('tipos_prestamo', ['nombre' => 'Educación', 'habilitada' => true]);
    }

    public function test_formulario_de_modalidad_no_pide_unidad_y_rotula_quincenas(): void
    {
        $this->actingAs(Usuario::factory()->administrador()->create())
            ->get(route('tipos-prestamo.create'))
            ->assertOk()
            ->assertSee('Plazo máximo (quincenas)')
            ->assertDontSee('unidad_plazo', false)
            ->assertDontSee('Unidad para mostrar el plazo');
    }

    public function test_listado_y_detalle_muestran_el_plazo_en_quincenas_con_equivalencia(): void
    {
        $this->actingAs(Usuario::factory()->administrador()->create());
        $vivienda = TipoPrestamo::factory()->create(['nombre' => 'Vivienda prueba', 'plazo_maximo' => 480]);
        $educacion = TipoPrestamo::factory()->create(['nombre' => 'Educación prueba', 'plazo_maximo' => 72]);

        $this->get(route('tipos-prestamo.index'))
            ->assertSee('480 quincenas (≈ 20 años)')
            ->assertSee('72 quincenas (≈ 36 meses)');

        $this->get(route('tipos-prestamo.show', $vivienda))->assertSee('480 quincenas (≈ 20 años)');
        $this->get(route('tipos-prestamo.show', $educacion))->assertSee('72 quincenas (≈ 36 meses)');
    }

    public function test_la_tabla_de_modalidades_ya_no_tiene_la_columna_unidad_plazo(): void
    {
        $this->assertFalse(Schema::hasColumn('tipos_prestamo', 'unidad_plazo'));
    }

    public function test_funcionario_bienestar_puede_crear_una_modalidad(): void
    {
        $funcionario = Usuario::factory()->create();

        $this->actingAs($funcionario)->post(route('tipos-prestamo.store'), [
            'nombre' => 'Educación',
            'descripcion' => 'Crédito educativo',
            'plazo_maximo' => 72,
            'fuente_normativa' => 'Acuerdo Directivo No. 19/2022',
        ])->assertRedirect(route('tipos-prestamo.index'));

        $this->assertDatabaseHas('tipos_prestamo', ['nombre' => 'Educación', 'habilitada' => true]);
    }

    public function test_funcionario_bienestar_puede_editar_una_modalidad(): void
    {
        $funcionario = Usuario::factory()->create();
        $tipoPrestamo = TipoPrestamo::factory()->create();

        $this->actingAs($funcionario)->put(route('tipos-prestamo.update', $tipoPrestamo), [
            'nombre' => 'Vehículo',
            'descripcion' => 'Crédito de vehículo',
            'plazo_maximo' => 168,
            'fuente_normativa' => 'Acuerdo Directivo No. 19/2022',
        ])->assertRedirect(route('tipos-prestamo.index'));

        $this->assertSame('Vehículo', $tipoPrestamo->fresh()->nombre);
    }

    public function test_funcionario_bienestar_puede_habilitar_y_deshabilitar_una_modalidad(): void
    {
        $funcionario = Usuario::factory()->create();
        $tipoPrestamo = TipoPrestamo::factory()->create();

        $this->actingAs($funcionario)
            ->patch(route('tipos-prestamo.deshabilitar', $tipoPrestamo))
            ->assertRedirect(route('tipos-prestamo.index'));

        $this->assertFalse($tipoPrestamo->fresh()->habilitada);

        $this->actingAs($funcionario)
            ->patch(route('tipos-prestamo.habilitar', $tipoPrestamo))
            ->assertRedirect(route('tipos-prestamo.index'));

        $this->assertTrue($tipoPrestamo->fresh()->habilitada);
    }

    public function test_un_usuario_deshabilitado_con_sesion_activa_no_puede_gestionar_modalidades(): void
    {
        $tipoPrestamo = TipoPrestamo::factory()->create();

        foreach ([Usuario::factory()->administrador(), Usuario::factory()] as $factory) {
            $deshabilitado = $factory->create(['habilitado' => false]);

            $this->actingAs($deshabilitado)->post(route('tipos-prestamo.store'), [
                'nombre' => 'Educación',
                'plazo_maximo' => 72,
            ])->assertRedirect(route('login'));

            $this->actingAs($deshabilitado)->put(route('tipos-prestamo.update', $tipoPrestamo), [
                'nombre' => 'Vehículo',
                'plazo_maximo' => 168,
            ])->assertRedirect(route('login'));

            $this->actingAs($deshabilitado)
                ->patch(route('tipos-prestamo.deshabilitar', $tipoPrestamo))
                ->assertRedirect(route('login'));

            $this->actingAs($deshabilitado)
                ->patch(route('tipos-prestamo.habilitar', $tipoPrestamo))
                ->assertRedirect(route('login'));
        }

        $this->assertDatabaseMissing('tipos_prestamo', ['nombre' => 'Educación']);
        $this->assertTrue($tipoPrestamo->fresh()->habilitada);
    }

    public function test_administrador_puede_editar_una_modalidad(): void
    {
        $administrador = Usuario::factory()->administrador()->create();
        $tipoPrestamo = TipoPrestamo::factory()->create();

        $this->actingAs($administrador)->put(route('tipos-prestamo.update', $tipoPrestamo), [
            'nombre' => 'Vehículo',
            'descripcion' => 'Crédito de vehículo',
            'plazo_maximo' => 168,
            'fuente_normativa' => 'Acuerdo Directivo No. 19/2022',
        ])->assertRedirect(route('tipos-prestamo.index'));

        $this->assertSame('Vehículo', $tipoPrestamo->fresh()->nombre);
    }

    public function test_administrador_puede_habilitar_y_deshabilitar_una_modalidad(): void
    {
        $administrador = Usuario::factory()->administrador()->create();
        $tipoPrestamo = TipoPrestamo::factory()->create();

        $this->actingAs($administrador)
            ->patch(route('tipos-prestamo.deshabilitar', $tipoPrestamo))
            ->assertRedirect(route('tipos-prestamo.index'));

        $this->assertFalse($tipoPrestamo->fresh()->habilitada);

        $this->actingAs($administrador)
            ->patch(route('tipos-prestamo.habilitar', $tipoPrestamo))
            ->assertRedirect(route('tipos-prestamo.index'));

        $this->assertTrue($tipoPrestamo->fresh()->habilitada);
    }
}
