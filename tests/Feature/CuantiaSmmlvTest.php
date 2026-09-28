<?php

namespace Tests\Feature;

use App\Models\CuantiaSmmlv;
use App\Models\TipoPrestamo;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CuantiaSmmlvTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrador_puede_agregar_un_tramo_de_cuantia(): void
    {
        $administrador = Usuario::factory()->administrador()->create();
        $tipoPrestamo = TipoPrestamo::factory()->create();

        $this->actingAs($administrador)->post(route('cuantias-smmlv.store', $tipoPrestamo), [
            'tramo_desde_smmlv' => 0,
            'tramo_hasta_smmlv' => 4,
            'cuantia_maxima_smmlv' => 130,
        ])->assertRedirect(route('tipos-prestamo.show', $tipoPrestamo));

        $this->assertDatabaseHas('cuantias_smmlv', [
            'id_tipo' => $tipoPrestamo->id_tipo,
            'cuantia_maxima_smmlv' => 130,
        ]);
    }

    public function test_tramo_hasta_debe_ser_mayor_que_tramo_desde(): void
    {
        $administrador = Usuario::factory()->administrador()->create();
        $tipoPrestamo = TipoPrestamo::factory()->create();

        $this->actingAs($administrador)->post(route('cuantias-smmlv.store', $tipoPrestamo), [
            'tramo_desde_smmlv' => 8,
            'tramo_hasta_smmlv' => 4,
            'cuantia_maxima_smmlv' => 130,
        ])->assertSessionHasErrors('tramo_hasta_smmlv');
    }

    public function test_administrador_puede_editar_y_eliminar_un_tramo(): void
    {
        $administrador = Usuario::factory()->administrador()->create();
        $cuantia = CuantiaSmmlv::factory()->create();

        $this->actingAs($administrador)->put(route('cuantias-smmlv.update', $cuantia), [
            'tramo_desde_smmlv' => 0,
            'tramo_hasta_smmlv' => 4,
            'cuantia_maxima_smmlv' => 150,
        ])->assertRedirect(route('tipos-prestamo.show', $cuantia->id_tipo));

        $this->assertSame('150.00', $cuantia->fresh()->cuantia_maxima_smmlv);

        $this->actingAs($administrador)
            ->delete(route('cuantias-smmlv.destroy', $cuantia))
            ->assertRedirect(route('tipos-prestamo.show', $cuantia->id_tipo));

        $this->assertDatabaseMissing('cuantias_smmlv', ['id_cuantia' => $cuantia->id_cuantia]);
    }

    public function test_funcionario_bienestar_puede_gestionar_cuantias(): void
    {
        $funcionario = Usuario::factory()->create();
        $tipoPrestamo = TipoPrestamo::factory()->create();

        $this->actingAs($funcionario)->post(route('cuantias-smmlv.store', $tipoPrestamo), [
            'tramo_desde_smmlv' => 0,
            'tramo_hasta_smmlv' => 4,
            'cuantia_maxima_smmlv' => 130,
        ])->assertRedirect(route('tipos-prestamo.show', $tipoPrestamo));

        $cuantia = CuantiaSmmlv::where('id_tipo', $tipoPrestamo->id_tipo)->firstOrFail();

        $this->actingAs($funcionario)->put(route('cuantias-smmlv.update', $cuantia), [
            'tramo_desde_smmlv' => 0,
            'tramo_hasta_smmlv' => 4,
            'cuantia_maxima_smmlv' => 150,
        ])->assertRedirect(route('tipos-prestamo.show', $tipoPrestamo));

        $this->assertSame('150.00', $cuantia->fresh()->cuantia_maxima_smmlv);

        $this->actingAs($funcionario)
            ->delete(route('cuantias-smmlv.destroy', $cuantia))
            ->assertRedirect(route('tipos-prestamo.show', $tipoPrestamo));

        $this->assertDatabaseMissing('cuantias_smmlv', ['id_cuantia' => $cuantia->id_cuantia]);
    }

    public function test_un_usuario_deshabilitado_con_sesion_activa_no_puede_gestionar_cuantias(): void
    {
        $cuantia = CuantiaSmmlv::factory()->create();

        foreach ([Usuario::factory()->administrador(), Usuario::factory()] as $factory) {
            $deshabilitado = $factory->create(['habilitado' => false]);

            $this->actingAs($deshabilitado)->post(route('cuantias-smmlv.store', $cuantia->id_tipo), [
                'tramo_desde_smmlv' => 0,
                'tramo_hasta_smmlv' => 4,
                'cuantia_maxima_smmlv' => 130,
            ])->assertRedirect(route('login'));

            $this->actingAs($deshabilitado)->put(route('cuantias-smmlv.update', $cuantia), [
                'tramo_desde_smmlv' => 0,
                'tramo_hasta_smmlv' => 4,
                'cuantia_maxima_smmlv' => 150,
            ])->assertRedirect(route('login'));

            $this->actingAs($deshabilitado)
                ->delete(route('cuantias-smmlv.destroy', $cuantia))
                ->assertRedirect(route('login'));
        }

        $this->assertDatabaseHas('cuantias_smmlv', ['id_cuantia' => $cuantia->id_cuantia]);
        $this->assertDatabaseCount('cuantias_smmlv', 1);
    }
}
