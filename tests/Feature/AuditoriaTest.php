<?php

namespace Tests\Feature;

use App\Enums\EstadoCuota;
use App\Models\CuantiaSmmlv;
use App\Models\Empleado;
use App\Models\Prestamo;
use App\Models\PresupuestoAnual;
use App\Models\SmmlvAnual;
use App\Models\SolicitudCredito;
use App\Models\TasaInt;
use App\Models\TipoPrestamo;
use App\Models\Usuario;
use App\Services\AuditoriaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditoriaTest extends TestCase
{
    use RefreshDatabase;

    public function test_registrar_una_solicitud_deja_traza_de_auditoria(): void
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

        $this->assertDatabaseHas('auditorias', [
            'cedula_usu' => $funcionario->cedula,
            'funcion_realizada' => 'Registro de solicitud de crédito',
        ]);
    }

    public function test_aprobar_una_solicitud_deja_traza_de_auditoria(): void
    {
        $administrador = Usuario::factory()->principal()->create();
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
        PresupuestoAnual::factory()->create([
            'id_tipo' => $tipoPrestamo->id_tipo,
            'anio' => 2026,
            'monto_asignado' => 50_000_000,
        ]);

        $solicitud = SolicitudCredito::factory()->create([
            'cedula_empleado' => $empleado->cedula,
            'id_tipo' => $tipoPrestamo->id_tipo,
            'monto_solicitado' => 2_000_000,
            'plazo_solicitado' => 24,
            'fecha_solicitud' => '2026-01-15',
        ]);

        $this->actingAs($administrador)->patch(route('solicitudes.aprobar', $solicitud))
            ->assertRedirect(route('solicitudes.show', $solicitud));

        $this->assertDatabaseHas('auditorias', [
            'cedula_usu' => $administrador->cedula,
            'funcion_realizada' => 'Aprobación de solicitud de crédito',
        ]);
    }

    public function test_rechazar_una_solicitud_deja_traza_de_auditoria(): void
    {
        $funcionario = Usuario::factory()->create();
        $solicitud = SolicitudCredito::factory()->create();

        $this->actingAs($funcionario)->patch(route('solicitudes.rechazar', $solicitud), [
            'motivo_rechazo' => 'No cumple con la antigüedad mínima requerida.',
        ])->assertRedirect(route('solicitudes.show', $solicitud));

        $this->assertDatabaseHas('auditorias', [
            'cedula_usu' => $funcionario->cedula,
            'funcion_realizada' => 'Rechazo de solicitud de crédito',
        ]);
    }

    public function test_registrar_un_desembolso_deja_traza_de_auditoria(): void
    {
        $funcionario = Usuario::factory()->create();
        $tipoPrestamo = TipoPrestamo::factory()->create(['nombre' => 'Educación']);
        $prestamo = Prestamo::factory()->create(['id_tipo' => $tipoPrestamo->id_tipo, 'monto_aprobado' => 10_000_000]);

        $this->actingAs($funcionario)->post(route('desembolsos.store', $prestamo), [
            'numero_tramo' => 1,
            'fecha' => '2026-02-01',
        ])->assertRedirect(route('prestamos.show', $prestamo));

        $this->assertDatabaseHas('auditorias', [
            'cedula_usu' => $funcionario->cedula,
            'funcion_realizada' => 'Registro de desembolso',
        ]);
    }

    public function test_pagar_una_cuota_deja_traza_de_auditoria(): void
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

        $this->assertDatabaseHas('auditorias', [
            'cedula_usu' => $funcionario->cedula,
            'funcion_realizada' => 'Pago de cuota',
        ]);
    }

    public function test_un_abono_extraordinario_que_supera_el_umbral_deja_traza_de_pago_y_reliquidacion(): void
    {
        $funcionario = Usuario::factory()->create();
        $prestamo = Prestamo::factory()->create([
            'id_tipo' => TipoPrestamo::factory()->create(['nombre' => 'Vivienda — compra de inmueble'])->id_tipo,
            'cuota_periodica' => 100_000,
        ]);
        $prestamo->cuotas()->create([
            'numero_cuota' => 1,
            'fecha_vencimiento' => '2026-02-15',
            'valor_capital' => 5_000_000,
            'valor_interes' => 100_000,
            'valor_total' => 5_100_000,
            'estado' => EstadoCuota::Pendiente,
        ]);

        $this->actingAs($funcionario)->post(route('pagos.store-abono', $prestamo), [
            'monto' => 1_500_000,
            'fecha_pago' => '2026-02-15',
            'modo_reliquidacion' => 'cuota',
        ])->assertRedirect(route('prestamos.show', $prestamo));

        $this->assertDatabaseHas('auditorias', [
            'cedula_usu' => $funcionario->cedula,
            'funcion_realizada' => 'Abono extraordinario',
        ]);
        $this->assertDatabaseHas('auditorias', [
            'cedula_usu' => $funcionario->cedula,
            'funcion_realizada' => 'Reliquidación de préstamo',
        ]);
    }

    public function test_la_cancelacion_total_deja_traza_de_auditoria(): void
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

        $this->actingAs($funcionario)->post(route('pagos.store-cancelacion', $prestamo), [
            'fecha_pago' => '2026-02-15',
        ])->assertRedirect(route('prestamos.show', $prestamo));

        $this->assertDatabaseHas('auditorias', [
            'cedula_usu' => $funcionario->cedula,
            'funcion_realizada' => 'Cancelación de préstamo',
        ]);
    }

    public function test_un_abono_de_cesantias_deja_traza_de_abono_y_reliquidacion(): void
    {
        $funcionario = Usuario::factory()->create();
        $tipoPrestamo = TipoPrestamo::factory()->create(['nombre' => 'Vivienda — compra de inmueble']);
        $prestamo = Prestamo::factory()->create([
            'id_tipo' => $tipoPrestamo->id_tipo,
            'monto_aprobado' => 10_000_000,
            'cuota_periodica' => 100_000,
        ]);
        $prestamo->cuotas()->create([
            'numero_cuota' => 1,
            'fecha_vencimiento' => '2026-02-15',
            'valor_capital' => 9_000_000,
            'valor_interes' => 100_000,
            'valor_total' => 9_100_000,
            'estado' => EstadoCuota::Pendiente,
        ]);

        $this->actingAs($funcionario)->post(route('cesantias.store', $prestamo), [
            'anio' => 2025,
            'monto' => 300_000,
            'fecha_abono' => '2026-02-15',
            'modo_reliquidacion' => 'plazo',
        ])->assertRedirect(route('prestamos.show', $prestamo));

        $this->assertDatabaseHas('auditorias', [
            'cedula_usu' => $funcionario->cedula,
            'funcion_realizada' => 'Abono de cesantías',
        ]);
        $this->assertDatabaseHas('auditorias', [
            'cedula_usu' => $funcionario->cedula,
            'funcion_realizada' => 'Reliquidación de préstamo',
        ]);
    }

    public function test_solo_el_administrador_puede_consultar_la_auditoria(): void
    {
        $funcionario = Usuario::factory()->create();

        $this->actingAs($funcionario)->get(route('auditoria.index'))->assertForbidden();
    }

    public function test_el_administrador_puede_consultar_la_auditoria(): void
    {
        $administrador = Usuario::factory()->principal()->create();

        $this->actingAs($administrador)->get(route('auditoria.index'))->assertOk();
    }

    public function test_la_auditoria_muestra_el_nombre_y_apellido_de_quien_hizo_la_accion_sin_empleado(): void
    {
        $administrador = Usuario::factory()->principal()->create();
        $funcionario = Usuario::factory()->create(['nombre' => 'Camila', 'apellido' => 'Ospina']);
        $this->assertDatabaseMissing('empleados', ['cedula' => $funcionario->cedula]);

        app(AuditoriaService::class)->registrar($funcionario, 'Prueba de actor independiente');

        $this->assertDatabaseHas('auditorias', ['cedula_usu' => $funcionario->cedula, 'funcion_realizada' => 'Prueba de actor independiente']);

        $this->actingAs($administrador)
            ->get(route('auditoria.index'))
            ->assertOk()
            ->assertSee('Camila Ospina')
            ->assertSee('Prueba de actor independiente');
    }

    public function test_invitado_es_redirigido_a_login(): void
    {
        $this->get(route('auditoria.index'))->assertRedirect(route('login'));
    }
}
