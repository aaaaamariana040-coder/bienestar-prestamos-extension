<?php

namespace Tests\Feature;

use App\Enums\EstadoCuota;
use App\Enums\EstadoPrestamo;
use App\Enums\TipoPago;
use App\Models\Prestamo;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PagoTest extends TestCase
{
    use RefreshDatabase;

    public function test_se_registra_el_pago_regular_de_una_cuota_pendiente(): void
    {
        $funcionario = Usuario::factory()->create();
        $prestamo = Prestamo::factory()->create();
        $cuota = $prestamo->cuotas()->create([
            'numero_cuota' => 1,
            'fecha_vencimiento' => '2026-02-15',
            'valor_capital' => 80_000,
            'valor_interes' => 5_000,
            'valor_total' => 85_000,
            'estado' => EstadoCuota::Pendiente,
        ]);

        $this->actingAs($funcionario)->post(route('pagos.store-cuota', [$prestamo, $cuota]), [
            'fecha_pago' => '2026-02-15',
        ])->assertRedirect(route('prestamos.show', $prestamo));

        $this->assertDatabaseHas('cuotas', ['id_cuota' => $cuota->id_cuota, 'estado' => EstadoCuota::Pagada->value]);
        $this->assertDatabaseHas('pagos', [
            'id_prestamo' => $prestamo->id_prestamo,
            'id_cuota' => $cuota->id_cuota,
            'monto' => 85_000,
            'tipo' => TipoPago::Regular->value,
            'monto_aplicado_interes' => 5_000,
            'monto_aplicado_capital' => 80_000,
            'cedula_usu' => $funcionario->cedula,
        ]);
    }

    public function test_pagar_la_ultima_cuota_pendiente_cierra_el_prestamo(): void
    {
        $funcionario = Usuario::factory()->create();
        $prestamo = Prestamo::factory()->create();
        $cuota = $prestamo->cuotas()->create([
            'numero_cuota' => 1,
            'fecha_vencimiento' => '2026-02-15',
            'valor_capital' => 80_000,
            'valor_interes' => 5_000,
            'valor_total' => 85_000,
            'estado' => EstadoCuota::Pendiente,
        ]);

        $this->actingAs($funcionario)->post(route('pagos.store-cuota', [$prestamo, $cuota]), [
            'fecha_pago' => '2026-02-15',
        ]);

        $this->assertDatabaseHas('prestamos', ['id_prestamo' => $prestamo->id_prestamo, 'estado' => EstadoPrestamo::Cancelado->value]);
    }

    public function test_no_se_puede_pagar_una_cuota_ya_pagada(): void
    {
        $funcionario = Usuario::factory()->create();
        $prestamo = Prestamo::factory()->create();
        $cuota = $prestamo->cuotas()->create([
            'numero_cuota' => 1,
            'fecha_vencimiento' => '2026-02-15',
            'valor_capital' => 80_000,
            'valor_interes' => 5_000,
            'valor_total' => 85_000,
            'estado' => EstadoCuota::Pagada,
        ]);

        $this->actingAs($funcionario)->post(route('pagos.store-cuota', [$prestamo, $cuota]), [
            'fecha_pago' => '2026-02-15',
        ])->assertRedirect()->assertSessionHas('error');
    }

    public function test_se_registra_un_abono_extraordinario(): void
    {
        $funcionario = Usuario::factory()->create();
        // Valores fijos: con una cuota aleatoria baja (monto/num_cuotas < 50.000) el abono de 500.000
        // supera las 10 cuotas y dispara la reliquidación. El préstamo necesita cuotas pendientes:
        // un abono se compara siempre contra el saldo de capital y debe ser menor.
        $prestamo = Prestamo::factory()->create([
            'monto_aprobado' => 2_400_000,
            'num_cuotas' => 24,
            'cuota_periodica' => 100_000,
        ]);
        foreach (range(1, 24) as $numero) {
            $prestamo->cuotas()->create([
                'numero_cuota' => $numero,
                'fecha_vencimiento' => '2026-03-01',
                'valor_capital' => 100_000,
                'valor_interes' => 0,
                'valor_total' => 100_000,
                'estado' => EstadoCuota::Pendiente,
            ]);
        }

        $this->actingAs($funcionario)->post(route('pagos.store-abono', $prestamo), [
            'monto' => 500_000,
            'fecha_pago' => '2026-02-15',
        ])->assertRedirect(route('prestamos.show', $prestamo));

        $this->assertDatabaseHas('pagos', [
            'id_prestamo' => $prestamo->id_prestamo,
            'id_cuota' => null,
            'monto' => 500_000,
            'tipo' => TipoPago::AbonoExtraordinario->value,
            'monto_aplicado_interes' => 0,
            'monto_aplicado_capital' => 500_000,
        ]);
    }

    public function test_la_cancelacion_total_paga_las_cuotas_pendientes_y_cierra_el_prestamo(): void
    {
        $funcionario = Usuario::factory()->create();
        $prestamo = Prestamo::factory()->create();
        $prestamo->cuotas()->create([
            'numero_cuota' => 1,
            'fecha_vencimiento' => '2026-02-15',
            'valor_capital' => 80_000,
            'valor_interes' => 5_000,
            'valor_total' => 85_000,
            'estado' => EstadoCuota::Pendiente,
        ]);
        $prestamo->cuotas()->create([
            'numero_cuota' => 2,
            'fecha_vencimiento' => '2026-03-01',
            'valor_capital' => 82_000,
            'valor_interes' => 3_000,
            'valor_total' => 85_000,
            'estado' => EstadoCuota::Pendiente,
        ]);

        $this->actingAs($funcionario)->post(route('pagos.store-cancelacion', $prestamo), [
            'fecha_pago' => '2026-02-15',
        ])->assertRedirect(route('prestamos.show', $prestamo));

        $this->assertDatabaseHas('pagos', [
            'id_prestamo' => $prestamo->id_prestamo,
            'monto' => 170_000,
            'tipo' => TipoPago::Cancelacion->value,
        ]);
        $this->assertSame(0, $prestamo->cuotas()->where('estado', EstadoCuota::Pendiente)->count());
        $this->assertDatabaseHas('prestamos', ['id_prestamo' => $prestamo->id_prestamo, 'estado' => EstadoPrestamo::Cancelado->value]);
    }

    public function test_no_se_puede_registrar_pagos_en_un_prestamo_ya_cancelado(): void
    {
        $funcionario = Usuario::factory()->create();
        $prestamo = Prestamo::factory()->cancelado()->create();

        $this->actingAs($funcionario)->post(route('pagos.store-abono', $prestamo), [
            'monto' => 100_000,
            'fecha_pago' => '2026-02-15',
        ])->assertForbidden();
    }

    public function test_invitado_es_redirigido_a_login(): void
    {
        $prestamo = Prestamo::factory()->create();

        $this->post(route('pagos.store-abono', $prestamo), [])->assertRedirect(route('login'));
    }
}
