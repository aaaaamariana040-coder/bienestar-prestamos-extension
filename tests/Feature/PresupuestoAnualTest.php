<?php

namespace Tests\Feature;

use App\Models\PresupuestoAnual;
use App\Models\TipoPrestamo;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PresupuestoAnualTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrador_puede_registrar_un_presupuesto(): void
    {
        $administrador = Usuario::factory()->administrador()->create();
        $tipoPrestamo = TipoPrestamo::factory()->create();

        $this->actingAs($administrador)->post(route('presupuestos.store'), [
            'anio' => 2026,
            'id_tipo' => $tipoPrestamo->id_tipo,
            'monto_asignado' => 1_000_000,
        ])->assertRedirect(route('presupuestos.index', ['anio' => 2026]));

        $this->assertDatabaseHas('presupuestos_anuales', [
            'anio' => 2026,
            'id_tipo' => $tipoPrestamo->id_tipo,
            'monto_asignado' => 1_000_000,
            'monto_comprometido' => 0,
            'monto_disponible' => 1_000_000,
        ]);
    }

    public function test_funcionario_bienestar_tambien_puede_registrar_un_presupuesto(): void
    {
        $funcionario = Usuario::factory()->create();
        $tipoPrestamo = TipoPrestamo::factory()->create();

        $this->actingAs($funcionario)->post(route('presupuestos.store'), [
            'anio' => 2026,
            'id_tipo' => $tipoPrestamo->id_tipo,
            'monto_asignado' => 1_000_000,
        ])->assertRedirect(route('presupuestos.index', ['anio' => 2026]));

        $this->assertDatabaseHas('presupuestos_anuales', ['id_tipo' => $tipoPrestamo->id_tipo, 'anio' => 2026]);
    }

    public function test_no_se_puede_registrar_dos_presupuestos_para_la_misma_modalidad_y_anio(): void
    {
        $administrador = Usuario::factory()->administrador()->create();
        $tipoPrestamo = TipoPrestamo::factory()->create();
        PresupuestoAnual::factory()->create(['anio' => 2026, 'id_tipo' => $tipoPrestamo->id_tipo]);

        $this->actingAs($administrador)->post(route('presupuestos.store'), [
            'anio' => 2026,
            'id_tipo' => $tipoPrestamo->id_tipo,
            'monto_asignado' => 500_000,
        ])->assertSessionHasErrors('id_tipo');
    }

    public function test_actualizar_un_presupuesto_registra_historial_por_cada_campo_modificado(): void
    {
        $funcionario = Usuario::factory()->create();
        $presupuesto = PresupuestoAnual::factory()->create([
            'monto_asignado' => 1_000_000,
            'monto_comprometido' => 0,
        ]);

        $this->actingAs($funcionario)->put(route('presupuestos.update', $presupuesto), [
            'monto_asignado' => 1_200_000,
            'monto_comprometido' => 300_000,
            'motivo' => 'Ajuste anual por IPC',
        ])->assertRedirect(route('presupuestos.index', ['anio' => $presupuesto->anio]));

        $presupuesto->refresh();
        $this->assertSame('1200000.00', $presupuesto->monto_asignado);
        $this->assertSame('300000.00', $presupuesto->monto_comprometido);
        $this->assertSame('900000.00', $presupuesto->monto_disponible);

        $this->assertSame(2, $presupuesto->historial()->count());
        $this->assertDatabaseHas('presupuesto_historial', [
            'id_presupuesto' => $presupuesto->id_presupuesto,
            'campo' => 'monto_asignado',
            'monto_anterior' => 1_000_000,
            'monto_nuevo' => 1_200_000,
            'motivo' => 'Ajuste anual por IPC',
            'cedula_usu' => $funcionario->cedula,
        ]);
        $this->assertDatabaseHas('presupuesto_historial', [
            'id_presupuesto' => $presupuesto->id_presupuesto,
            'campo' => 'monto_comprometido',
            'monto_anterior' => 0,
            'monto_nuevo' => 300_000,
        ]);
    }

    public function test_no_se_registra_historial_para_campos_sin_cambios(): void
    {
        $administrador = Usuario::factory()->administrador()->create();
        $presupuesto = PresupuestoAnual::factory()->create([
            'monto_asignado' => 1_000_000,
            'monto_comprometido' => 0,
        ]);

        $this->actingAs($administrador)->put(route('presupuestos.update', $presupuesto), [
            'monto_asignado' => 1_000_000,
            'monto_comprometido' => 200_000,
            'motivo' => 'Se compromete parte del presupuesto',
        ]);

        $this->assertSame(1, $presupuesto->historial()->count());
        $this->assertSame('monto_comprometido', $presupuesto->historial()->first()->campo->value);
    }

    public function test_el_comprometido_no_puede_superar_al_asignado(): void
    {
        $administrador = Usuario::factory()->administrador()->create();
        $presupuesto = PresupuestoAnual::factory()->create(['monto_asignado' => 1_000_000]);

        $this->actingAs($administrador)->put(route('presupuestos.update', $presupuesto), [
            'monto_asignado' => 1_000_000,
            'monto_comprometido' => 1_500_000,
            'motivo' => 'Intento invalido',
        ])->assertSessionHasErrors('monto_comprometido');
    }

    public function test_invitado_es_redirigido_a_login(): void
    {
        $this->get(route('presupuestos.index'))->assertRedirect(route('login'));
    }
}
