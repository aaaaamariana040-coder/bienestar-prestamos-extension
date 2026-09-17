<?php

namespace Tests\Feature;

use App\Enums\EstadoDesembolso;
use App\Models\Prestamo;
use App\Models\TipoPrestamo;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DesembolsoTest extends TestCase
{
    use RefreshDatabase;

    public function test_se_registra_un_desembolso_unico_para_modalidades_sin_tramos(): void
    {
        $funcionario = Usuario::factory()->create();
        $tipoPrestamo = TipoPrestamo::factory()->create(['nombre' => 'Educación']);
        $prestamo = Prestamo::factory()->create(['id_tipo' => $tipoPrestamo->id_tipo, 'monto_aprobado' => 10_000_000]);

        $this->actingAs($funcionario)->post(route('desembolsos.store', $prestamo), [
            'numero_tramo' => 1,
            'fecha' => '2026-02-01',
        ])->assertRedirect(route('prestamos.show', $prestamo));

        $this->assertDatabaseHas('desembolsos', [
            'id_prestamo' => $prestamo->id_prestamo,
            'numero_tramo' => 1,
            'porcentaje' => 100,
            'valor' => 10_000_000,
            'estado' => EstadoDesembolso::Realizado->value,
        ]);
    }

    public function test_construccion_se_desembolsa_en_dos_tramos_iguales_tras_inspeccion(): void
    {
        $funcionario = Usuario::factory()->create();
        $tipoPrestamo = TipoPrestamo::factory()->create(['nombre' => 'Vivienda — construcción']);
        $prestamo = Prestamo::factory()->create(['id_tipo' => $tipoPrestamo->id_tipo, 'monto_aprobado' => 20_000_000]);

        $this->actingAs($funcionario)->post(route('desembolsos.store', $prestamo), [
            'numero_tramo' => 1,
            'fecha' => '2026-02-01',
        ])->assertRedirect(route('prestamos.show', $prestamo));

        $this->assertDatabaseHas('desembolsos', [
            'id_prestamo' => $prestamo->id_prestamo,
            'numero_tramo' => 1,
            'porcentaje' => 50,
            'valor' => 10_000_000,
        ]);

        $this->actingAs($funcionario)->post(route('desembolsos.store', $prestamo), [
            'numero_tramo' => 2,
            'fecha' => '2026-03-01',
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertDatabaseMissing('desembolsos', ['id_prestamo' => $prestamo->id_prestamo, 'numero_tramo' => 2]);

        $this->actingAs($funcionario)->post(route('desembolsos.store', $prestamo), [
            'numero_tramo' => 2,
            'fecha' => '2026-03-01',
            'inspeccion_aprobada' => 1,
        ])->assertRedirect(route('prestamos.show', $prestamo));

        $this->assertDatabaseHas('desembolsos', [
            'id_prestamo' => $prestamo->id_prestamo,
            'numero_tramo' => 2,
            'porcentaje' => 50,
            'valor' => 10_000_000,
            'requiere_inspeccion' => true,
            'inspeccion_aprobada' => true,
        ]);
    }

    public function test_no_se_puede_registrar_el_mismo_tramo_dos_veces(): void
    {
        $funcionario = Usuario::factory()->create();
        $tipoPrestamo = TipoPrestamo::factory()->create(['nombre' => 'Educación']);
        $prestamo = Prestamo::factory()->create(['id_tipo' => $tipoPrestamo->id_tipo]);

        $this->actingAs($funcionario)->post(route('desembolsos.store', $prestamo), [
            'numero_tramo' => 1,
            'fecha' => '2026-02-01',
        ])->assertRedirect(route('prestamos.show', $prestamo));

        $this->actingAs($funcionario)->post(route('desembolsos.store', $prestamo), [
            'numero_tramo' => 1,
            'fecha' => '2026-02-05',
        ])->assertRedirect()->assertSessionHas('error');
    }

    public function test_invitado_es_redirigido_a_login(): void
    {
        $prestamo = Prestamo::factory()->create();

        $this->post(route('desembolsos.store', $prestamo), [])->assertRedirect(route('login'));
    }
}
