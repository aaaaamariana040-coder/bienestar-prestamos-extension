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
use App\Services\SolicitudCreditoService;
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
        $this->assertSame('4.8500', $prestamo->tasa_efectiva_anual);

        $this->assertDatabaseHas('presupuestos_anuales', [
            'id_tipo' => $tipoPrestamo->id_tipo,
            'anio' => 2026,
            'monto_comprometido' => 2_000_000,
        ]);
    }

    public function test_la_validacion_compara_quincenas_con_quincenas(): void
    {
        $empleado = Empleado::factory()->create();
        $tipoPrestamo = TipoPrestamo::factory()->create(['plazo_maximo' => 72]);

        $dentro = SolicitudCredito::factory()->create([
            'cedula_empleado' => $empleado->cedula,
            'id_tipo' => $tipoPrestamo->id_tipo,
            'plazo_solicitado' => 72,
            'fecha_solicitud' => '2026-01-15',
        ]);
        $fuera = SolicitudCredito::factory()->create([
            'cedula_empleado' => $empleado->cedula,
            'id_tipo' => $tipoPrestamo->id_tipo,
            'plazo_solicitado' => 73,
            'fecha_solicitud' => '2026-01-15',
        ]);

        $servicio = app(SolicitudCreditoService::class);
        $mensaje = 'El plazo solicitado (73 quincenas) supera el plazo máximo de la modalidad (72 quincenas (≈ 36 meses)).';

        $this->assertNotContains($mensaje, $servicio->validar($dentro));
        $this->assertNotContains('El plazo solicitado (72 quincenas) supera el plazo máximo de la modalidad (72 quincenas (≈ 36 meses)).', $servicio->validar($dentro));
        $this->assertContains($mensaje, $servicio->validar($fuera));
    }

    public function test_pantallas_de_solicitud_rotulan_el_plazo_en_quincenas(): void
    {
        $this->actingAs(Usuario::factory()->create());
        $solicitud = SolicitudCredito::factory()->create(['plazo_solicitado' => 72]);

        $this->get(route('solicitudes.create'))->assertSee('Plazo (quincenas)');
        $this->get(route('solicitudes.index'))->assertSee('72 quincenas (≈ 36 meses)');
        $this->get(route('solicitudes.show', $solicitud))->assertSee('72 quincenas (≈ 36 meses)');
    }

    public function test_formulario_de_solicitud_busca_por_cedula_en_vez_de_listar_empleados(): void
    {
        $this->actingAs(Usuario::factory()->create());
        Empleado::factory()->create(['nombre_completo' => 'Persona En Lista Vieja']);

        $this->get(route('solicitudes.create'))
            ->assertOk()
            ->assertSee('Cédula del empleado')
            ->assertSee('id="cedula_empleado"', false)
            ->assertSee('inputmode="numeric"', false)
            ->assertSee('maxlength="10"', false)
            ->assertDontSee('Selecciona un empleado')
            ->assertDontSee('Persona En Lista Vieja')
            ->assertSee('Selecciona una modalidad')
            ->assertSee('Monto solicitado')
            ->assertSee('Plazo (quincenas)')
            ->assertSee('Crear empleado');
    }

    public function test_buscar_empleado_activo_por_cedula_devuelve_sus_datos(): void
    {
        $this->actingAs(Usuario::factory()->create());
        $empleado = Empleado::factory()->create([
            'cedula' => '1234567890',
            'nombre_completo' => 'Ana María Gómez',
            'dependencia' => 'Bienestar Social',
            'salario' => 4_500_000,
            'capacidad_endeudamiento' => 2_250_000,
            'estado' => 'Activo',
        ]);

        $this->getJson(route('solicitudes.buscar-empleado', $empleado->cedula))
            ->assertOk()
            ->assertExactJson([
                'encontrado' => true,
                'cedula' => '1234567890',
                'nombre_completo' => 'Ana María Gómez',
                'dependencia' => 'Bienestar Social',
                'salario' => '4,500,000.00',
                'capacidad_endeudamiento' => '2,250,000.00',
            ]);
    }

    public function test_buscar_cedula_inexistente_avisa_que_no_existe(): void
    {
        $this->actingAs(Usuario::factory()->create());

        $this->getJson(route('solicitudes.buscar-empleado', '999999'))
            ->assertNotFound()
            ->assertJson(['encontrado' => false, 'mensaje' => 'No existe un empleado con la cédula 999999.']);
    }

    public function test_un_empleado_retirado_no_se_puede_buscar(): void
    {
        $this->actingAs(Usuario::factory()->create());
        $empleado = Empleado::factory()->create(['estado' => 'Retirado']);

        $this->getJson(route('solicitudes.buscar-empleado', $empleado->cedula))
            ->assertNotFound()
            ->assertJson(['encontrado' => false, 'inactivo' => true])
            ->assertJsonMissing(['nombre_completo' => $empleado->nombre_completo]);
    }

    public function test_la_busqueda_solo_acepta_cedulas_numericas_de_hasta_10_digitos(): void
    {
        $this->actingAs(Usuario::factory()->create());

        $this->getJson('/solicitudes/empleado/12345678901')->assertNotFound();
        $this->getJson('/solicitudes/empleado/abc')->assertNotFound();
    }

    public function test_la_busqueda_exige_sesion_y_permiso_para_registrar_solicitudes(): void
    {
        $empleado = Empleado::factory()->create();

        $this->getJson(route('solicitudes.buscar-empleado', $empleado->cedula))->assertUnauthorized();

        $this->actingAs(Usuario::factory()->create(['habilitado' => false]))
            ->get(route('solicitudes.buscar-empleado', $empleado->cedula))
            ->assertRedirect(route('login'));
    }

    public function test_registrar_solicitud_enviando_la_cedula_escrita_crea_la_solicitud(): void
    {
        $funcionario = Usuario::factory()->create();
        $empleado = Empleado::factory()->create(['cedula' => '1098765432', 'estado' => 'Activo']);
        $tipoPrestamo = TipoPrestamo::factory()->create();

        $this->actingAs($funcionario)->post(route('solicitudes.store'), [
            'cedula_empleado' => $empleado->cedula,
            'id_tipo' => $tipoPrestamo->id_tipo,
            'monto_solicitado' => 2_000_000,
            'plazo_solicitado' => 24,
            'fecha_solicitud' => '2026-01-15',
        ])->assertRedirect();

        $this->assertDatabaseHas('solicitudes_credito', ['cedula_empleado' => '1098765432', 'id_tipo' => $tipoPrestamo->id_tipo]);
    }

    public function test_un_envio_manual_con_la_cedula_de_un_retirado_se_rechaza_y_no_crea_la_solicitud(): void
    {
        $funcionario = Usuario::factory()->create();
        $retirado = Empleado::factory()->create(['cedula' => '1098700001', 'estado' => 'Retirado']);
        $tipoPrestamo = TipoPrestamo::factory()->create();

        $this->actingAs($funcionario)->post(route('solicitudes.store'), [
            'cedula_empleado' => $retirado->cedula,
            'id_tipo' => $tipoPrestamo->id_tipo,
            'monto_solicitado' => 2_000_000,
            'plazo_solicitado' => 24,
            'fecha_solicitud' => '2026-01-15',
        ])->assertSessionHasErrors([
            'cedula_empleado' => 'El empleado con la cédula 1098700001 no está activo y no puede solicitar un crédito.',
        ]);

        $this->assertDatabaseMissing('solicitudes_credito', ['cedula_empleado' => $retirado->cedula]);
        $this->assertDatabaseCount('solicitudes_credito', 0);
    }

    public function test_un_envio_manual_con_una_cedula_inexistente_se_rechaza_con_mensaje(): void
    {
        $funcionario = Usuario::factory()->create();
        $tipoPrestamo = TipoPrestamo::factory()->create();

        $this->actingAs($funcionario)->post(route('solicitudes.store'), [
            'cedula_empleado' => '5555555555',
            'id_tipo' => $tipoPrestamo->id_tipo,
            'monto_solicitado' => 2_000_000,
            'plazo_solicitado' => 24,
            'fecha_solicitud' => '2026-01-15',
        ])->assertSessionHasErrors(['cedula_empleado' => 'No existe un empleado con la cédula 5555555555.']);

        $this->assertDatabaseCount('solicitudes_credito', 0);
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
