<?php

namespace Tests\Feature;

use App\Enums\EstadoCuota;
use App\Models\Empleado;
use App\Models\Prestamo;
use App\Models\PresupuestoAnual;
use App\Models\TipoPrestamo;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class ReporteTest extends TestCase
{
    use RefreshDatabase;

    public function test_reporte_de_prestamos_agrupa_por_modalidad_y_estado(): void
    {
        $administrador = Usuario::factory()->administrador()->create();

        $vivienda = TipoPrestamo::factory()->create(['nombre' => 'Vivienda — compra de inmueble']);
        Prestamo::factory()->create(['id_tipo' => $vivienda->id_tipo, 'monto_aprobado' => 5_000_000, 'fecha_autorizacion' => '2026-01-10']);
        Prestamo::factory()->create(['id_tipo' => $vivienda->id_tipo, 'monto_aprobado' => 5_000_000, 'fecha_autorizacion' => '2026-01-12']);

        $educacion = TipoPrestamo::factory()->create(['nombre' => 'Educación']);
        Prestamo::factory()->create(['id_tipo' => $educacion->id_tipo, 'monto_aprobado' => 2_000_000, 'fecha_autorizacion' => '2026-01-15']);

        $response = $this->actingAs($administrador)->get(route('reportes.prestamos'));

        $response->assertOk();
        $response->assertSee('Vivienda — compra de inmueble');
        $response->assertSee('10,000,000.00');
        $response->assertSee('Educación');
        $response->assertSee('2,000,000.00');
    }

    public function test_reporte_de_prestamos_filtra_por_modalidad(): void
    {
        $administrador = Usuario::factory()->administrador()->create();

        $vivienda = TipoPrestamo::factory()->create(['nombre' => 'Vivienda — compra de inmueble']);
        Prestamo::factory()->create(['id_tipo' => $vivienda->id_tipo, 'fecha_autorizacion' => '2026-01-10']);

        $educacion = TipoPrestamo::factory()->create(['nombre' => 'Educación']);
        Prestamo::factory()->create(['id_tipo' => $educacion->id_tipo, 'fecha_autorizacion' => '2026-01-15']);

        $response = $this->actingAs($administrador)->get(route('reportes.prestamos', ['id_tipo' => $vivienda->id_tipo]));

        $response->assertOk();
        $filas = $response->viewData('filas');
        $this->assertCount(1, $filas);
        $this->assertSame('Vivienda — compra de inmueble', $filas->first()->modalidad);
    }

    public function test_reporte_de_pagos_agrupa_por_modalidad_en_el_periodo(): void
    {
        $administrador = Usuario::factory()->administrador()->create();
        $usuario = Usuario::factory()->create();
        $tipoPrestamo = TipoPrestamo::factory()->create(['nombre' => 'Educación']);
        $prestamo = Prestamo::factory()->create(['id_tipo' => $tipoPrestamo->id_tipo]);

        $prestamo->pagos()->create([
            'fecha_pago' => '2026-01-10',
            'monto' => 100_000,
            'tipo' => 'Regular',
            'monto_aplicado_interes' => 10_000,
            'monto_aplicado_capital' => 90_000,
            'cedula_usu' => $usuario->cedula,
        ]);
        $prestamo->pagos()->create([
            'fecha_pago' => '2026-01-20',
            'monto' => 100_000,
            'tipo' => 'Regular',
            'monto_aplicado_interes' => 8_000,
            'monto_aplicado_capital' => 92_000,
            'cedula_usu' => $usuario->cedula,
        ]);

        $response = $this->actingAs($administrador)->get(route('reportes.pagos', ['desde' => '2026-01-01', 'hasta' => '2026-01-31']));

        $response->assertOk();
        $response->assertSee('Educación');
        $response->assertSee('200,000.00');
        $response->assertSee('18,000.00');
        $response->assertSee('182,000.00');
    }

    public function test_reporte_de_cartera_solo_muestra_cuotas_pendientes_ya_vencidas(): void
    {
        $administrador = Usuario::factory()->administrador()->create();
        $empleado = Empleado::factory()->create(['nombre_completo' => 'Empleado En Mora']);
        $tipoPrestamo = TipoPrestamo::factory()->create(['nombre' => 'Vehículo']);
        $prestamo = Prestamo::factory()->create(['id_tipo' => $tipoPrestamo->id_tipo, 'cedula_empleado' => $empleado->cedula]);

        $prestamo->cuotas()->create([
            'numero_cuota' => 1,
            'fecha_vencimiento' => Carbon::today()->subDays(10)->toDateString(),
            'valor_capital' => 80_000,
            'valor_interes' => 5_000,
            'valor_total' => 85_000,
            'estado' => EstadoCuota::Pendiente,
        ]);
        $prestamo->cuotas()->create([
            'numero_cuota' => 2,
            'fecha_vencimiento' => Carbon::today()->addDays(15)->toDateString(),
            'valor_capital' => 80_000,
            'valor_interes' => 5_000,
            'valor_total' => 85_000,
            'estado' => EstadoCuota::Pendiente,
        ]);
        $prestamo->cuotas()->create([
            'numero_cuota' => 3,
            'fecha_vencimiento' => Carbon::today()->subDays(20)->toDateString(),
            'valor_capital' => 80_000,
            'valor_interes' => 5_000,
            'valor_total' => 85_000,
            'estado' => EstadoCuota::Pagada,
        ]);

        $response = $this->actingAs($administrador)->get(route('reportes.cartera'));

        $response->assertOk();
        $response->assertSee('Empleado En Mora');
        $filas = $response->viewData('filas');
        $this->assertCount(1, $filas);
        $this->assertSame(10, $filas->first()->dias_mora);
    }

    public function test_reporte_de_presupuesto_muestra_la_ejecucion_por_modalidad(): void
    {
        $administrador = Usuario::factory()->administrador()->create();
        $tipoPrestamo = TipoPrestamo::factory()->create(['nombre' => 'Calamidad doméstica']);
        PresupuestoAnual::factory()->create([
            'id_tipo' => $tipoPrestamo->id_tipo,
            'anio' => 2026,
            'monto_asignado' => 1_000_000,
            'monto_comprometido' => 400_000,
        ]);

        $response = $this->actingAs($administrador)->get(route('reportes.presupuesto', ['anio' => 2026]));

        $response->assertOk();
        $response->assertSee('Calamidad doméstica');
        $response->assertSee('1,000,000.00');
        $response->assertSee('400,000.00');
        $response->assertSee('600,000.00');
    }

    public function test_un_funcionario_puede_ver_los_reportes(): void
    {
        $funcionario = Usuario::factory()->create();

        $rutas = [
            route('reportes.prestamos'),
            route('reportes.pagos'),
            route('reportes.cartera'),
            route('reportes.presupuesto'),
        ];

        foreach ($rutas as $ruta) {
            $this->actingAs($funcionario)->get($ruta)->assertOk();
        }
    }

    public function test_el_menu_muestra_el_enlace_de_reportes_al_funcionario(): void
    {
        $funcionario = Usuario::factory()->create();

        $this->actingAs($funcionario)
            ->get(route('empleados.index'))
            ->assertSee('href="'.route('reportes.historial-pagos').'"', false);
    }

    public function test_el_resumen_de_pagos_se_llama_asi_en_el_menu_pagos_y_en_su_titulo(): void
    {
        $funcionario = Usuario::factory()->create();

        $respuesta = $this->actingAs($funcionario)->get(route('reportes.pagos'))
            ->assertOk()
            ->assertSee('<h1 class="h3 mb-4">Resumen de pagos</h1>', false)
            ->assertDontSee('Reporte de pagos');

        preg_match('/id="grupo-pagos".*?<\/div>/s', $respuesta->getContent(), $grupo);

        $this->assertStringContainsString('Resumen de pagos', $grupo[0]);
        $this->assertStringContainsString('href="'.route('reportes.pagos').'"', $grupo[0]);
        $this->assertStringNotContainsString('Historial de pagos', $grupo[0]);
    }

    public function test_un_usuario_deshabilitado_con_sesion_activa_no_puede_ver_los_reportes(): void
    {
        $administradorDeshabilitado = Usuario::factory()->administrador()->create(['habilitado' => false]);
        $funcionarioDeshabilitado = Usuario::factory()->create(['habilitado' => false]);

        $this->actingAs($administradorDeshabilitado)->get(route('reportes.prestamos'))->assertRedirect(route('login'));
        $this->actingAs($funcionarioDeshabilitado)->get(route('reportes.prestamos'))->assertRedirect(route('login'));

        $this->assertFalse(Gate::forUser($administradorDeshabilitado)->allows('reportes.ver'));
        $this->assertFalse(Gate::forUser($funcionarioDeshabilitado)->allows('reportes.ver'));
    }

    public function test_invitado_es_redirigido_a_login(): void
    {
        $this->get(route('reportes.prestamos'))->assertRedirect(route('login'));
    }
}
