<?php

namespace Tests\Feature;

use App\Enums\EstadoSolicitud;
use App\Models\CuantiaSmmlv;
use App\Models\Empleado;
use App\Models\PresupuestoAnual;
use App\Models\SmmlvAnual;
use App\Models\SolicitudCredito;
use App\Models\TasaInt;
use App\Models\TipoPrestamo;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SolicitudCreditoTest extends TestCase
{
    use RefreshDatabase;

    private function prepararCondicionesParaAprobar(TipoPrestamo $tipoPrestamo, Empleado $empleado): void
    {
        SmmlvAnual::factory()->create(['anio' => 2026, 'valor' => 1_300_000]);

        CuantiaSmmlv::factory()->create([
            'id_tipo' => $tipoPrestamo->id_tipo,
            'tramo_desde_smmlv' => 0,
            'tramo_hasta_smmlv' => null,
            'cuantia_maxima_smmlv' => 100,
        ]);

        TasaInt::factory()->create([
            'id_tipo' => $tipoPrestamo->id_tipo,
            'anio' => 2026,
            'tasa_anual' => 4.85,
        ]);

        PresupuestoAnual::factory()->create([
            'id_tipo' => $tipoPrestamo->id_tipo,
            'anio' => 2026,
            'monto_asignado' => 50_000_000,
        ]);
    }

    public function test_funcionario_puede_registrar_una_solicitud(): void
    {
        $funcionario = Usuario::factory()->create();
        $empleado = Empleado::factory()->create();
        $tipoPrestamo = TipoPrestamo::factory()->create();

        $this->actingAs($funcionario)->post(route('solicitudes.store'), [
            'cedula_empleado' => $empleado->cedula,
            'id_tipo' => $tipoPrestamo->id_tipo,
            'monto_solicitado' => 2_000_000,
            'plazo_solicitado' => 24,
            'fecha_solicitud' => '2026-01-15',
        ])->assertRedirect();

        $this->assertDatabaseHas('solicitudes_credito', [
            'cedula_empleado' => $empleado->cedula,
            'id_tipo' => $tipoPrestamo->id_tipo,
            'estado' => EstadoSolicitud::Radicada->value,
        ]);
    }

    public function test_aprobar_una_solicitud_valida_genera_un_prestamo_con_tabla_de_amortizacion(): void
    {
        $administrador = Usuario::factory()->administrador()->create();
        $empleado = Empleado::factory()->create(['salario' => 3_000_000]);
        $tipoPrestamo = TipoPrestamo::factory()->create(['plazo_maximo' => 72]);
        $this->prepararCondicionesParaAprobar($tipoPrestamo, $empleado);

        $solicitud = SolicitudCredito::factory()->create([
            'cedula_empleado' => $empleado->cedula,
            'id_tipo' => $tipoPrestamo->id_tipo,
            'monto_solicitado' => 2_000_000,
            'plazo_solicitado' => 24,
            'fecha_solicitud' => '2026-01-15',
        ]);

        $this->actingAs($administrador)->patch(route('solicitudes.aprobar', $solicitud))
            ->assertRedirect(route('solicitudes.show', $solicitud));

        $solicitud->refresh();
        $this->assertSame(EstadoSolicitud::Aprobada, $solicitud->estado);

        $this->assertDatabaseHas('prestamos', [
            'id_solicitud' => $solicitud->id_solicitud,
            'cedula_empleado' => $empleado->cedula,
            'num_cuotas' => 24,
        ]);

        $prestamo = $solicitud->prestamo()->firstOrFail();
        $this->assertSame(24, $prestamo->cuotas()->count());

        $this->assertDatabaseHas('presupuestos_anuales', [
            'id_tipo' => $tipoPrestamo->id_tipo,
            'anio' => 2026,
            'monto_comprometido' => 2_000_000,
        ]);
    }

    public function test_no_se_puede_aprobar_una_solicitud_sin_presupuesto_disponible(): void
    {
        $administrador = Usuario::factory()->administrador()->create();
        $empleado = Empleado::factory()->create(['salario' => 3_000_000]);
        $tipoPrestamo = TipoPrestamo::factory()->create(['plazo_maximo' => 72]);

        SmmlvAnual::factory()->create(['anio' => 2026, 'valor' => 1_300_000]);
        CuantiaSmmlv::factory()->create([
            'id_tipo' => $tipoPrestamo->id_tipo,
            'tramo_desde_smmlv' => 0,
            'tramo_hasta_smmlv' => null,
            'cuantia_maxima_smmlv' => 100,
        ]);
        TasaInt::factory()->create(['id_tipo' => $tipoPrestamo->id_tipo, 'anio' => 2026, 'tasa_anual' => 4.85]);
        // Sin presupuesto_anual registrado para esta modalidad y año.

        $solicitud = SolicitudCredito::factory()->create([
            'cedula_empleado' => $empleado->cedula,
            'id_tipo' => $tipoPrestamo->id_tipo,
            'monto_solicitado' => 2_000_000,
            'plazo_solicitado' => 24,
            'fecha_solicitud' => '2026-01-15',
        ]);

        $this->actingAs($administrador)->patch(route('solicitudes.aprobar', $solicitud))
            ->assertRedirect()
            ->assertSessionHas('error');

        $solicitud->refresh();
        $this->assertSame(EstadoSolicitud::Radicada, $solicitud->estado);
        $this->assertDatabaseMissing('prestamos', ['id_solicitud' => $solicitud->id_solicitud]);
    }

    public function test_funcionario_puede_rechazar_una_solicitud(): void
    {
        $funcionario = Usuario::factory()->create();
        $solicitud = SolicitudCredito::factory()->create();

        $this->actingAs($funcionario)->patch(route('solicitudes.rechazar', $solicitud), [
            'motivo_rechazo' => 'No cumple con la antigüedad mínima requerida.',
        ])->assertRedirect(route('solicitudes.show', $solicitud));

        $this->assertDatabaseHas('solicitudes_credito', [
            'id_solicitud' => $solicitud->id_solicitud,
            'estado' => EstadoSolicitud::Rechazada->value,
            'motivo_rechazo' => 'No cumple con la antigüedad mínima requerida.',
        ]);
    }

    public function test_no_se_puede_decidir_una_solicitud_que_ya_fue_decidida(): void
    {
        $administrador = Usuario::factory()->administrador()->create();
        $solicitud = SolicitudCredito::factory()->aprobada()->create();

        $this->actingAs($administrador)->patch(route('solicitudes.aprobar', $solicitud))
            ->assertForbidden();
    }

    public function test_invitado_es_redirigido_a_login(): void
    {
        $this->get(route('solicitudes.index'))->assertRedirect(route('login'));
    }
}
