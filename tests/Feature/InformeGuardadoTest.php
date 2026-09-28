<?php

namespace Tests\Feature;

use App\Enums\EstadoCuota;
use App\Enums\TipoInforme;
use App\Models\Auditoria;
use App\Models\Empleado;
use App\Models\InformeGuardado;
use App\Models\Prestamo;
use App\Models\PresupuestoAnual;
use App\Models\TipoPrestamo;
use App\Models\Usuario;
use App\Services\AuditoriaService;
use App\Services\InformeGuardadoService;
use App\Services\ReporteService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class InformeGuardadoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Datos de origen con centavos y proporciones que no dan porcentajes exactos.
     */
    private function sembrarDatosDeOrigen(): void
    {
        $usuario = Usuario::factory()->create();
        $vivienda = TipoPrestamo::factory()->create(['nombre' => 'Vivienda — compra de inmueble']);
        $educacion = TipoPrestamo::factory()->create(['nombre' => 'Educación']);
        $calamidad = TipoPrestamo::factory()->create(['nombre' => 'Calamidad doméstica']);

        PresupuestoAnual::factory()->create(['id_tipo' => $vivienda->id_tipo, 'anio' => 2026, 'monto_asignado' => 1_000_000, 'monto_comprometido' => 333_333.33]);
        PresupuestoAnual::factory()->create(['id_tipo' => $educacion->id_tipo, 'anio' => 2026, 'monto_asignado' => 2_000_000.10, 'monto_comprometido' => 1_000_000.05]);
        PresupuestoAnual::factory()->create(['id_tipo' => $calamidad->id_tipo, 'anio' => 2026, 'monto_asignado' => 0, 'monto_comprometido' => 0]);

        $empleado = Empleado::factory()->create(['nombre_completo' => 'Empleado En Mora']);
        $prestamoVivienda = Prestamo::factory()->create(['id_tipo' => $vivienda->id_tipo, 'cedula_empleado' => $empleado->cedula, 'monto_aprobado' => 1_000_000.10, 'fecha_autorizacion' => '2026-01-10']);
        Prestamo::factory()->create(['id_tipo' => $vivienda->id_tipo, 'monto_aprobado' => 2_000_000.20, 'fecha_autorizacion' => '2026-01-12']);
        $prestamoEducacion = Prestamo::factory()->create(['id_tipo' => $educacion->id_tipo, 'monto_aprobado' => 500_000.30, 'fecha_autorizacion' => '2026-01-15']);

        foreach ([[$prestamoVivienda, 100_000.10, 10_000.03, 90_000.07], [$prestamoEducacion, 50_000.20, 5_000.10, 45_000.10]] as [$prestamo, $monto, $interes, $capital]) {
            $prestamo->pagos()->create([
                'fecha_pago' => '2026-02-10',
                'monto' => $monto,
                'tipo' => 'Regular',
                'monto_aplicado_interes' => $interes,
                'monto_aplicado_capital' => $capital,
                'cedula_usu' => $usuario->cedula,
            ]);
        }

        $prestamoVivienda->cuotas()->create([
            'numero_cuota' => 1,
            'fecha_vencimiento' => Carbon::today()->subDays(10)->toDateString(),
            'valor_capital' => 80_000.10,
            'valor_interes' => 5_000.05,
            'valor_total' => 85_000.15,
            'estado' => EstadoCuota::Pendiente,
        ]);
        $prestamoEducacion->cuotas()->create([
            'numero_cuota' => 2,
            'fecha_vencimiento' => Carbon::today()->subDays(3)->toDateString(),
            'valor_capital' => 40_000.20,
            'valor_interes' => 2_000.10,
            'valor_total' => 42_000.30,
            'estado' => EstadoCuota::Pendiente,
        ]);
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function tiposConParametros(): array
    {
        return [
            'préstamos' => ['prestamos', ['desde' => '2026-01-01', 'hasta' => '2026-12-31']],
            'pagos' => ['pagos', ['desde' => '2026-02-01', 'hasta' => '2026-02-28']],
            'cartera' => ['cartera', []],
            'presupuesto' => ['presupuesto', ['anio' => 2026]],
        ];
    }

    /**
     * @param  array<string, mixed>  $parametros
     */
    #[DataProvider('tiposConParametros')]
    public function test_genera_y_guarda_cada_tipo_de_informe(string $tipo, array $parametros): void
    {
        $this->sembrarDatosDeOrigen();
        $funcionario = Usuario::factory()->create();

        $response = $this->actingAs($funcionario)->post(route('informes.store'), ['tipo' => $tipo] + $parametros);

        $informe = InformeGuardado::firstOrFail();
        $response->assertRedirect(route('informes.show', $informe));
        $this->assertSame(TipoInforme::from($tipo), $informe->tipo);
        $this->assertSame($funcionario->cedula, $informe->generado_por);
        $this->assertSame(Carbon::today()->toDateString(), $informe->fecha_corte->toDateString());
        $this->assertSame($parametros, array_filter($informe->parametros, fn ($valor) => $valor !== null));
        $this->assertNotEmpty($informe->datos['filas']);
    }

    /**
     * @param  array<string, mixed>  $parametros
     */
    #[DataProvider('tiposConParametros')]
    public function test_los_montos_se_guardan_como_numeros_redondeados_a_dos_decimales(string $tipo, array $parametros): void
    {
        $this->sembrarDatosDeOrigen();
        $administrador = Usuario::factory()->administrador()->create();

        $this->actingAs($administrador)->post(route('informes.store'), ['tipo' => $tipo] + $parametros);

        $json = DB::table('informes_guardados')->value('datos');
        $this->assertDoesNotMatchRegularExpression('/"(monto|valor|porcentaje)_[a-z_]+":"/', $json, 'Un monto se guardó como texto.');

        $datos = json_decode($json, true);
        $numeros = 0;

        foreach (array_merge($datos['filas'], [$datos['totales']]) as $fila) {
            foreach ($fila as $campo => $valor) {
                if (preg_match('/^(monto|valor)_/', $campo)) {
                    $this->assertTrue(is_int($valor) || is_float($valor), "{$campo} debe ser numérico.");
                    $this->assertEquals(round($valor, 2), $valor, "{$campo} debe tener máximo 2 decimales.");
                    $numeros++;
                }
            }
        }

        $this->assertGreaterThan(0, $numeros);
    }

    /**
     * @param  array<string, mixed>  $parametros
     */
    #[DataProvider('tiposConParametros')]
    public function test_los_totales_guardados_coinciden_con_la_suma_de_sus_partes(string $tipo, array $parametros): void
    {
        $this->sembrarDatosDeOrigen();
        $administrador = Usuario::factory()->administrador()->create();

        $this->actingAs($administrador)->post(route('informes.store'), ['tipo' => $tipo] + $parametros);

        $datos = InformeGuardado::firstOrFail()->datos;
        $verificados = 0;

        foreach ($datos['totales'] as $campo => $total) {
            if ($campo === 'total_cuotas') {
                $this->assertSame(count($datos['filas']), $total);
                $verificados++;

                continue;
            }

            $this->assertEqualsWithDelta(
                array_sum(array_column($datos['filas'], $campo)),
                $total,
                0.001,
                "El total de {$campo} no coincide con la suma de las filas.",
            );
            $verificados++;
        }

        $this->assertGreaterThan(0, $verificados);
    }

    public function test_los_valores_de_presupuesto_guardados_coinciden_con_los_que_calcula_la_vista_en_vivo(): void
    {
        $this->sembrarDatosDeOrigen();
        $administrador = Usuario::factory()->administrador()->create();

        $this->actingAs($administrador)->post(route('informes.store'), ['tipo' => 'presupuesto', 'anio' => 2026]);
        $datos = InformeGuardado::firstOrFail()->datos;

        $esperado = [];
        foreach ($datos['filas'] as $fila) {
            array_push(
                $esperado,
                $fila['modalidad'],
                number_format($fila['monto_asignado'], 2),
                number_format($fila['monto_comprometido'], 2),
                number_format($fila['monto_disponible'], 2),
                number_format($fila['porcentaje_ejecutado'], 1).'%',
            );
        }
        array_push(
            $esperado,
            'Total',
            number_format($datos['totales']['monto_asignado'], 2),
            number_format($datos['totales']['monto_comprometido'], 2),
            number_format($datos['totales']['monto_disponible'], 2),
        );

        $this->assertCount(3, $datos['filas']);
        $this->actingAs($administrador)
            ->get(route('reportes.presupuesto', ['anio' => 2026]))
            ->assertOk()
            ->assertSeeInOrder($esperado);
    }

    public function test_un_informe_guardado_no_cambia_cuando_cambian_los_datos_de_origen(): void
    {
        $this->sembrarDatosDeOrigen();
        $administrador = Usuario::factory()->administrador()->create();

        foreach (self::tiposConParametros() as [$tipo, $parametros]) {
            $this->actingAs($administrador)->post(route('informes.store'), ['tipo' => $tipo] + $parametros);
        }

        $datosOriginales = InformeGuardado::orderBy('id_informe')->get()->map->datos->all();
        $this->assertCount(4, $datosOriginales);

        $modalidadNueva = TipoPrestamo::factory()->create(['nombre' => 'Modalidad Posterior']);
        $empleadoNuevo = Empleado::factory()->create(['nombre_completo' => 'Empleado Posterior']);
        $prestamoNuevo = Prestamo::factory()->create(['id_tipo' => $modalidadNueva->id_tipo, 'cedula_empleado' => $empleadoNuevo->cedula, 'monto_aprobado' => 9_999_999, 'fecha_autorizacion' => '2026-03-01']);
        $prestamoNuevo->pagos()->create([
            'fecha_pago' => '2026-02-15',
            'monto' => 777_777,
            'tipo' => 'Regular',
            'monto_aplicado_interes' => 7_777,
            'monto_aplicado_capital' => 770_000,
            'cedula_usu' => $administrador->cedula,
        ]);
        $prestamoNuevo->cuotas()->create([
            'numero_cuota' => 1,
            'fecha_vencimiento' => Carbon::today()->subDays(30)->toDateString(),
            'valor_capital' => 100,
            'valor_interes' => 10,
            'valor_total' => 110,
            'estado' => EstadoCuota::Pendiente,
        ]);
        PresupuestoAnual::query()->update(['monto_comprometido' => 1, 'monto_disponible' => 999]);

        $this->assertSame($datosOriginales, InformeGuardado::orderBy('id_informe')->get()->map->datos->all());

        foreach (InformeGuardado::all() as $informe) {
            $this->actingAs($administrador)
                ->get(route('informes.show', $informe))
                ->assertOk()
                ->assertDontSee('Empleado Posterior')
                ->assertDontSee('Modalidad Posterior')
                ->assertDontSee('9,999,999.00')
                ->assertDontSee('777,777.00');
        }

        $this->actingAs($administrador)->get(route('reportes.prestamos'))->assertSee('9,999,999.00');
        $this->actingAs($administrador)->get(route('reportes.cartera'))->assertSee('Empleado Posterior');
    }

    public function test_el_servicio_reutiliza_reporte_service_en_lugar_de_recalcular(): void
    {
        $this->mock(ReporteService::class, function ($mock): void {
            $mock->shouldReceive('pagosPorPeriodo')
                ->once()
                ->with('2026-02-01', null)
                ->andReturn(collect([(object) [
                    'modalidad' => 'Modalidad simulada',
                    'total_pagos' => '4',
                    'monto_total' => '1000.50',
                    'monto_interes' => '100.25',
                    'monto_capital' => '900.25',
                ]]));
        });
        $administrador = Usuario::factory()->administrador()->create();

        $informe = app(InformeGuardadoService::class)->generar(TipoInforme::Pagos, ['desde' => '2026-02-01'], $administrador);

        $this->assertSame([
            'modalidad' => 'Modalidad simulada',
            'total_pagos' => 4,
            'monto_total' => 1000.5,
            'monto_interes' => 100.25,
            'monto_capital' => 900.25,
        ], $informe->datos['filas'][0]);
    }

    public function test_el_informe_y_su_auditoria_se_guardan_en_la_misma_transaccion(): void
    {
        $administrador = Usuario::factory()->administrador()->create();

        app(InformeGuardadoService::class)->generar(TipoInforme::Cartera, [], $administrador);

        $informe = InformeGuardado::firstOrFail();
        $auditoria = Auditoria::where('funcion_realizada', 'Generación de informe guardado')->firstOrFail();
        $this->assertSame($administrador->cedula, $auditoria->cedula_usu);
        $this->assertStringContainsString("#{$informe->id_informe}", $auditoria->detalle);
    }

    public function test_si_falla_la_auditoria_no_se_guarda_el_informe(): void
    {
        $this->mock(AuditoriaService::class, function ($mock): void {
            $mock->shouldReceive('registrar')->once()->andThrow(new RuntimeException('Fallo de auditoría'));
        });
        $administrador = Usuario::factory()->administrador()->create();

        try {
            app(InformeGuardadoService::class)->generar(TipoInforme::Cartera, [], $administrador);
            $this->fail('Se esperaba la excepción de auditoría.');
        } catch (RuntimeException) {
            //
        }

        $this->assertSame(0, InformeGuardado::count());
    }

    public function test_administrador_y_funcionario_pueden_guardar_y_consultar_informes(): void
    {
        $informe = InformeGuardado::factory()->create();

        foreach ([Usuario::factory()->administrador()->create(), Usuario::factory()->create()] as $usuario) {
            $this->actingAs($usuario)->get(route('informes.index'))->assertOk();
            $this->actingAs($usuario)->get(route('informes.show', $informe))->assertOk();
            $this->actingAs($usuario)->post(route('informes.store'), ['tipo' => 'cartera'])->assertRedirect();
        }

        $this->assertSame(3, InformeGuardado::count());
    }

    public function test_el_menu_muestra_el_enlace_al_historial(): void
    {
        $funcionario = Usuario::factory()->create();

        $this->actingAs($funcionario)
            ->get(route('reportes.cartera'))
            ->assertSee('href="'.route('informes.index').'"', false)
            ->assertSee('Generar y guardar informe');
    }

    public function test_un_usuario_deshabilitado_o_invitado_no_accede_a_los_informes(): void
    {
        $informe = InformeGuardado::factory()->create();
        $deshabilitado = Usuario::factory()->administrador()->create(['habilitado' => false]);

        $this->actingAs($deshabilitado)->get(route('informes.index'))->assertRedirect(route('login'));
        $this->actingAs($deshabilitado)->get(route('informes.show', $informe))->assertRedirect(route('login'));
        $this->actingAs($deshabilitado)->post(route('informes.store'), ['tipo' => 'cartera'])->assertRedirect(route('login'));

        auth()->logout();
        $this->get(route('informes.index'))->assertRedirect(route('login'));
        $this->get(route('informes.show', $informe))->assertRedirect(route('login'));
        $this->post(route('informes.store'), ['tipo' => 'cartera'])->assertRedirect(route('login'));

        $this->assertSame(1, InformeGuardado::count());
    }

    public function test_no_existen_rutas_para_editar_ni_borrar_informes(): void
    {
        $informe = InformeGuardado::factory()->create();
        $administrador = Usuario::factory()->administrador()->create();
        $url = route('informes.show', $informe);

        $this->actingAs($administrador)->put($url, [])->assertStatus(405);
        $this->actingAs($administrador)->patch($url, [])->assertStatus(405);
        $this->actingAs($administrador)->delete($url)->assertStatus(405);
        $this->actingAs($administrador)->get($url.'/editar')->assertNotFound();
        $this->assertSame(1, InformeGuardado::count());
    }

    public function test_el_modelo_no_permite_modificar_ni_eliminar_un_informe(): void
    {
        $informe = InformeGuardado::factory()->create();

        try {
            $informe->update(['datos' => ['filas' => [], 'totales' => []]]);
            $this->fail('Se esperaba que la actualización fuera rechazada.');
        } catch (LogicException) {
            //
        }

        try {
            $informe->delete();
            $this->fail('Se esperaba que el borrado fuera rechazado.');
        } catch (LogicException) {
            //
        }

        $this->assertSame(1, InformeGuardado::count());
        $this->assertEquals(10_000_000, $informe->fresh()->datos['totales']['monto_asignado']);
    }

    public function test_no_se_puede_eliminar_un_usuario_con_informes_generados(): void
    {
        $informe = InformeGuardado::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('usuarios')->where('cedula', $informe->generado_por)->delete();
    }

    public function test_el_historial_filtra_por_tipo_y_fecha_de_corte(): void
    {
        $administrador = Usuario::factory()->administrador()->create();
        $cartera = InformeGuardado::factory()->cartera()->create(['fecha_corte' => '2026-03-10']);
        $presupuestoMarzo = InformeGuardado::factory()->create(['fecha_corte' => '2026-03-20']);
        $presupuestoAbril = InformeGuardado::factory()->create(['fecha_corte' => '2026-04-05']);

        $ids = fn (array $consulta) => $this->actingAs($administrador)
            ->get(route('informes.index', $consulta))
            ->assertOk()
            ->viewData('informes')
            ->pluck('id_informe')
            ->all();

        $this->assertSame([$cartera->id_informe], $ids(['tipo' => 'cartera']));
        $this->assertSame([$presupuestoAbril->id_informe, $presupuestoMarzo->id_informe], $ids(['tipo' => 'presupuesto']));
        $this->assertSame([$presupuestoMarzo->id_informe, $cartera->id_informe], $ids(['desde' => '2026-03-01', 'hasta' => '2026-03-31']));
        $this->assertSame([$presupuestoMarzo->id_informe], $ids(['tipo' => 'presupuesto', 'hasta' => '2026-03-31']));
        $this->assertCount(3, $ids([]));
    }

    public function test_el_informe_guardado_muestra_el_aviso_de_copia_y_sus_datos(): void
    {
        $administrador = Usuario::factory()->administrador()->create();

        foreach (['prestamos' => '4,000,000.00', 'pagos' => '300,000.00', 'cartera' => '150,000.00', 'presupuesto' => '10,000,000.00'] as $estado => $montoVisible) {
            $informe = InformeGuardado::factory()->{$estado}()->create();

            $this->actingAs($administrador)
                ->get(route('informes.show', $informe))
                ->assertOk()
                ->assertSee('Esta es una copia del informe tal como se generó el '.$informe->created_at->format('Y-m-d H:i'))
                ->assertSee($montoVisible)
                ->assertSee($informe->generadoPor->nombre_completo);
        }
    }

    public function test_rechaza_un_tipo_de_informe_invalido_o_un_rango_de_fechas_incoherente(): void
    {
        $administrador = Usuario::factory()->administrador()->create();

        $this->actingAs($administrador)->post(route('informes.store'), ['tipo' => 'inventado'])->assertSessionHasErrors('tipo');
        $this->actingAs($administrador)->post(route('informes.store'), ['tipo' => 'pagos', 'desde' => '2026-05-01', 'hasta' => '2026-04-01'])->assertSessionHasErrors('hasta');
        $this->actingAs($administrador)->post(route('informes.store'), ['tipo' => 'prestamos', 'estado' => 'Inexistente'])->assertSessionHasErrors('estado');

        $this->assertSame(0, InformeGuardado::count());
        $this->assertSame(0, Auditoria::count());
    }

    public function test_guarda_los_filtros_aplicados_en_el_reporte_de_prestamos(): void
    {
        $this->sembrarDatosDeOrigen();
        $administrador = Usuario::factory()->administrador()->create();
        $educacion = TipoPrestamo::where('nombre', 'Educación')->firstOrFail();

        $this->actingAs($administrador)->post(route('informes.store'), [
            'tipo' => 'prestamos',
            'id_tipo' => $educacion->id_tipo,
            'estado' => 'Vigente',
        ]);

        $informe = InformeGuardado::firstOrFail();
        $this->assertSame(['id_tipo' => $educacion->id_tipo, 'estado' => 'Vigente', 'desde' => null, 'hasta' => null], $informe->parametros);
        $this->assertSame(['Educación'], array_column($informe->datos['filas'], 'modalidad'));
    }
}
