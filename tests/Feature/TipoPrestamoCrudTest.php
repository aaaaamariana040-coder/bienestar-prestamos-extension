<?php

namespace Tests\Feature;

use App\Enums\UnidadPlazo;
use App\Models\TipoPrestamo;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'unidad_plazo' => UnidadPlazo::Meses->value,
            'fuente_normativa' => 'Acuerdo Directivo No. 19/2022',
        ])->assertRedirect(route('tipos-prestamo.index'));

        $this->assertDatabaseHas('tipos_prestamo', ['nombre' => 'Educación', 'habilitada' => true]);
    }

    public function test_funcionario_bienestar_no_puede_crear_una_modalidad(): void
    {
        $funcionario = Usuario::factory()->create();

        $this->actingAs($funcionario)->post(route('tipos-prestamo.store'), [
            'nombre' => 'Educación',
            'plazo_maximo' => 72,
            'unidad_plazo' => UnidadPlazo::Meses->value,
        ])->assertForbidden();
    }

    public function test_administrador_puede_editar_una_modalidad(): void
    {
        $administrador = Usuario::factory()->administrador()->create();
        $tipoPrestamo = TipoPrestamo::factory()->create();

        $this->actingAs($administrador)->put(route('tipos-prestamo.update', $tipoPrestamo), [
            'nombre' => 'Vehículo',
            'descripcion' => 'Crédito de vehículo',
            'plazo_maximo' => 168,
            'unidad_plazo' => UnidadPlazo::Anios->value,
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
