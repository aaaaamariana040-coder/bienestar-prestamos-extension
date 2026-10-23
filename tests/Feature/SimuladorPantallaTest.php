<?php

namespace Tests\Feature;

use App\Enums\EstadoCuota;
use App\Enums\EstadoPrestamo;
use App\Models\Auditoria;
use App\Models\CuantiaSmmlv;
use App\Models\Cuota;
use App\Models\Desembolso;
use App\Models\Empleado;
use App\Models\Prestamo;
use App\Models\PresupuestoAnual;
use App\Models\SmmlvAnual;
use App\Models\SolicitudCredito;
use App\Models\TasaInt;
use App\Models\TasaVehiculo;
use App\Models\TipoPrestamo;
use App\Models\Usuario;
use App\Services\PrestamoService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SimuladorPantallaTest extends TestCase
{
    use RefreshDatabase;

    private const SMMLV = 1_300_000;

    private int $anio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->anio = now()->year;
        $this->actingAs(Usuario::factory()->create());
    }

    /**
     * Crea una modalidad con todo lo necesario para simular; cada clave de
     * $omitir quita una pieza: smmlv, tasa, cuantia, presupuesto.
     *
     * @param  list<string>  $omitir
     */
    private function modalidad(string $nombre, int $plazoMaximo, float $cuantiaMaximaSmmlv, array $omitir = [], bool $habilitada = true): TipoPrestamo
    {
        $modalidad = TipoPrestamo::factory()->create(['nombre' => $nombre, 'plazo_maximo' => $plazoMaximo, 'habilitada' => $habilitada]);

        if (! in_array('smmlv', $omitir, true) && SmmlvAnual::where('anio', $this->anio)->doesntExist()) {
            SmmlvAnual::factory()->create(['anio' => $this->anio, 'valor' => self::SMMLV]);
        }

        if (! in_array('tasa', $omitir, true)) {
            TasaInt::factory()->create(['id_tipo' => $modalidad->id_tipo, 'anio' => $this->anio, 'tasa_anual' => 4.85]);
        }

        if (! in_array('cuantia', $omitir, true)) {
            CuantiaSmmlv::factory()->create([
                'id_tipo' => $modalidad->id_tipo,
                'tramo_desde_smmlv' => 0,
                'tramo_hasta_smmlv' => null,
                'cuantia_maxima_smmlv' => $cuantiaMaximaSmmlv,
            ]);
        }

        if (! in_array('presupuesto', $omitir, true)) {
            PresupuestoAnual::factory()->create(['id_tipo' => $modalidad->id_tipo, 'anio' => $this->anio, 'monto_asignado' => 10_000_000_000]);
        }

        return $modalidad;
    }

    private function empleado(float $salario = 3_000_000, ?float $capacidad = null): Empleado
    {
        return Empleado::factory()->create([
            'salario' => $salario,
            'capacidad_endeudamiento' => $capacidad ?? $salario * 0.5,
        ]);
    }

    private function prestamoVigente(Empleado $empleado, TipoPrestamo $modalidad, float $cuotaQuincenal, float $monto = 10_000_000): Prestamo
    {
        return Prestamo::factory()->create([
            'cedula_empleado' => $empleado->cedula,
            'id_tipo' => $modalidad->id_tipo,
            'monto_aprobado' => $monto,
            'num_cuotas' => 4,
            'cuota_periodica' => $cuotaQuincenal,
            'estado' => EstadoPrestamo::Vigente,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function evaluar(Empleado $empleado, TipoPrestamo $modalidad): array
    {
        return $this->getJson(route('simulador.modalidad', [$empleado->cedula, $modalidad->id_tipo]))
            ->assertOk()
            ->json();
    }

    public function test_la_pantalla_pide_los_datos_de_la_simulacion_y_esta_en_el_menu_de_creditos(): void
    {
        $this->get(route('simulador.index'))
            ->assertOk()
            ->assertSee('Simulador de crédito')
            ->assertSee('Cédula del empleado')
            ->assertSee('id="cedula_empleado"', false)
            ->assertSee('maxlength="10"', false)
            ->assertSee('Selecciona una modalidad')
            ->assertSee('Monto')
            ->assertSee('Plazo (quincenas)')
            ->assertSee('Tasa efectiva anual (%)')
            ->assertSee('Puntos adicionales (%)')
            ->assertSee('Retención en la fuente (mensual)')
            ->assertSee('Calcular')
            ->assertSee('id="form-simulador"', false)
            ->assertSee(json_encode(route('simulador.calcular')), false)
            ->assertSee(json_encode(route('simulador.tasa')), false)
            ->assertSee(route('simulador.index'), false);
    }

    public function test_el_simulador_esta_disponible_para_administrador_y_funcionario(): void
    {
        foreach ([Usuario::factory()->administrador()->create(), Usuario::factory()->create()] as $usuario) {
            $this->actingAs($usuario)->get(route('simulador.index'))->assertOk();
        }
    }

    public function test_sin_sesion_se_redirige_al_login(): void
    {
        $this->app['auth']->logout();
        $empleado = $this->empleado();
        $modalidad = $this->modalidad('Educación', 72, 17.5);

        $this->get(route('simulador.index'))->assertRedirect(route('login'));
        $this->get(route('simulador.empleado', $empleado->cedula))->assertRedirect(route('login'));
        $this->get(route('simulador.modalidad', [$empleado->cedula, $modalidad->id_tipo]))->assertRedirect(route('login'));
        $this->getJson(route('simulador.calcular'))->assertUnauthorized();
        $this->getJson(route('simulador.tasa'))->assertUnauthorized();
    }

    public function test_un_usuario_deshabilitado_no_puede_usar_el_simulador(): void
    {
        $this->actingAs(Usuario::factory()->create(['habilitado' => false]));

        $this->get(route('simulador.index'))->assertRedirect(route('login'));
        $this->getJson(route('simulador.calcular'))->assertUnauthorized();
    }

    public function test_cedula_inexistente_devuelve_un_mensaje_claro(): void
    {
        $this->getJson(route('simulador.empleado', '5555555555'))
            ->assertNotFound()
            ->assertJson(['encontrado' => false, 'mensaje' => 'No existe un empleado con la cédula 5555555555.']);
    }

    public function test_la_busqueda_solo_acepta_cedulas_numericas_de_hasta_10_digitos(): void
    {
        $this->getJson('/simulador/empleado/12345678901')->assertNotFound();
        $this->getJson('/simulador/empleado/abc123')->assertNotFound();
    }

    public function test_un_empleado_retirado_no_puede_continuar(): void
    {
        $retirado = Empleado::factory()->retirado()->create();
        $modalidad = $this->modalidad('Educación', 72, 17.5);

        $this->getJson(route('simulador.empleado', $retirado->cedula))
            ->assertOk()
            ->assertJson([
                'encontrado' => true,
                'estado' => 'Retirado',
                'elegible' => false,
                'mensaje' => 'El empleado está retirado y no puede solicitar un crédito.',
                'prestamos' => [],
                'capacidad' => null,
                'modalidades' => [],
            ]);

        $this->getJson(route('simulador.modalidad', [$retirado->cedula, $modalidad->id_tipo]))
            ->assertStatus(422)
            ->assertJson(['mensaje' => 'El empleado está retirado y no puede solicitar un crédito.']);
    }

    public function test_un_empleado_sin_prestamos_ve_toda_su_capacidad_y_las_modalidades_habilitadas(): void
    {
        $empleado = $this->empleado(4_000_000);
        $this->modalidad('Educación', 72, 17.5);
        $this->modalidad('Calamidad doméstica y necesidades especiales', 72, 5);
        $this->modalidad('Modalidad apagada', 72, 5, habilitada: false);

        $datos = $this->getJson(route('simulador.empleado', $empleado->cedula))->assertOk()->json();

        $this->assertTrue($datos['elegible']);
        $this->assertSame([], $datos['prestamos']);
        $this->assertSame(['total' => 2_000_000, 'comprometida' => 0, 'disponible' => 2_000_000], $datos['capacidad']);
        $this->assertSame(
            ['Calamidad doméstica y necesidades especiales', 'Educación'],
            array_column($datos['modalidades'], 'nombre'),
        );
    }

    public function test_un_empleado_con_prestamos_muestra_la_tabla_y_la_capacidad_disponible(): void
    {
        $empleado = $this->empleado(3_000_000);
        $educacion = $this->modalidad('Educación', 72, 17.5);
        $calamidad = $this->modalidad('Calamidad doméstica y necesidades especiales', 72, 5);

        $educacionVigente = $this->prestamoVigente($empleado, $educacion, 100_000);
        $calamidadVigente = $this->prestamoVigente($empleado, $calamidad, 50_000);
        $this->prestamoVigente($empleado, $calamidad, 999_999)->update(['estado' => EstadoPrestamo::Cancelado]);

        Desembolso::factory()->create(['id_prestamo' => $educacionVigente->id_prestamo, 'valor' => 8_000_000]);
        Cuota::factory()->create(['id_prestamo' => $educacionVigente->id_prestamo, 'numero_cuota' => 1, 'estado' => EstadoCuota::Pagada, 'valor_capital' => 1_000_000]);
        Cuota::factory()->create(['id_prestamo' => $educacionVigente->id_prestamo, 'numero_cuota' => 2, 'estado' => EstadoCuota::Pendiente, 'valor_capital' => 2_000_000]);
        Cuota::factory()->create(['id_prestamo' => $educacionVigente->id_prestamo, 'numero_cuota' => 3, 'estado' => EstadoCuota::Pendiente, 'valor_capital' => 3_000_000]);

        $datos = $this->getJson(route('simulador.empleado', $empleado->cedula))->assertOk()->json();

        $this->assertCount(2, $datos['prestamos']);
        $this->assertSame(
            ['modalidad' => 'Educación', 'monto_desembolsado' => 8_000_000, 'saldo_pendiente' => 5_000_000, 'cuota_quincenal' => 100_000, 'progreso_cuotas' => 'Cuota 1 de 4'],
            $datos['prestamos'][0],
        );
        $this->assertSame('Calamidad doméstica y necesidades especiales', $datos['prestamos'][1]['modalidad']);
        $this->assertSame($calamidadVigente->id_prestamo > 0, true);

        // Capacidad total 1.500.000; comprometida (100.000 + 50.000) × 2 = 300.000.
        $this->assertSame(['total' => 1_500_000, 'comprometida' => 300_000, 'disponible' => 1_200_000], $datos['capacidad']);
    }

    public function test_modalidad_deshabilitada(): void
    {
        $empleado = $this->empleado();
        $modalidad = $this->modalidad('Educación', 72, 17.5, habilitada: false);

        $resultado = $this->evaluar($empleado, $modalidad);

        $this->assertFalse($resultado['puede']);
        $this->assertSame('modalidad_deshabilitada', $resultado['motivo']);
        $this->assertSame('La modalidad está deshabilitada.', $resultado['mensaje']);
    }

    public function test_sin_tasa_registrada_para_el_anio(): void
    {
        $resultado = $this->evaluar($this->empleado(), $this->modalidad('Educación', 72, 17.5, ['tasa']));

        $this->assertFalse($resultado['puede']);
        $this->assertSame('sin_tasa', $resultado['motivo']);
        $this->assertSame('No hay una tasa de interés registrada para esta modalidad y año.', $resultado['mensaje']);
    }

    public function test_sin_smmlv_registrado_para_el_anio(): void
    {
        $resultado = $this->evaluar($this->empleado(), $this->modalidad('Educación', 72, 17.5, ['smmlv']));

        $this->assertFalse($resultado['puede']);
        $this->assertSame('sin_smmlv', $resultado['motivo']);
        $this->assertSame("No se ha registrado el valor del SMMLV para el año {$this->anio}.", $resultado['mensaje']);
    }

    public function test_sin_cuantia_para_el_tramo_salarial(): void
    {
        $resultado = $this->evaluar($this->empleado(), $this->modalidad('Educación', 72, 17.5, ['cuantia']));

        $this->assertFalse($resultado['puede']);
        $this->assertSame('sin_cuantia', $resultado['motivo']);
    }

    public function test_presupuesto_sin_disponibilidad(): void
    {
        $resultado = $this->evaluar($this->empleado(), $this->modalidad('Educación', 72, 17.5, ['presupuesto']));

        $this->assertFalse($resultado['puede']);
        $this->assertSame('sin_presupuesto', $resultado['motivo']);
        $this->assertSame('No hay disponibilidad presupuestal suficiente para esta modalidad en el año de la solicitud.', $resultado['mensaje']);
    }

    public function test_presupuesto_menor_al_monto_maximo_tambien_se_informa(): void
    {
        $empleado = $this->empleado();
        $modalidad = $this->modalidad('Educación', 72, 17.5, ['presupuesto']);
        PresupuestoAnual::factory()->create(['id_tipo' => $modalidad->id_tipo, 'anio' => $this->anio, 'monto_asignado' => 500_000]);

        $resultado = $this->evaluar($empleado, $modalidad);

        $this->assertFalse($resultado['puede']);
        $this->assertSame('sin_presupuesto', $resultado['motivo']);
    }

    public function test_capacidad_de_endeudamiento_agotada(): void
    {
        $empleado = $this->empleado(3_000_000);
        $modalidad = $this->modalidad('Educación', 72, 17.5);
        $this->prestamoVigente($empleado, $modalidad, 750_000);

        $resultado = $this->evaluar($empleado, $modalidad);

        $this->assertFalse($resultado['puede']);
        $this->assertSame('capacidad_agotada', $resultado['motivo']);
        $this->assertSame('La capacidad de endeudamiento está agotada: las cuotas vigentes ya la comprometen por completo.', $resultado['mensaje']);
    }

    public function test_capacidad_de_endeudamiento_insuficiente_para_el_monto_minimo(): void
    {
        $empleado = $this->empleado(3_000_000, 20);
        $modalidad = $this->modalidad('Educación', 72, 17.5);

        $resultado = $this->evaluar($empleado, $modalidad);

        $this->assertFalse($resultado['puede']);
        $this->assertSame('capacidad_insuficiente', $resultado['motivo']);
        $this->assertStringContainsString('no alcanza para el monto mínimo', $resultado['mensaje']);
    }

    public function test_vivienda_limitada_por_la_cuantia_del_tramo_salarial(): void
    {
        $empleado = $this->empleado(3_000_000);
        $vivienda = $this->modalidad('Vivienda — compra de inmueble', 480, 130);

        $resultado = $this->evaluar($empleado, $vivienda);

        $this->assertTrue($resultado['puede']);
        $this->assertSame('cuantia', $resultado['limitado_por']);
        $this->assertSame(130 * self::SMMLV, $resultado['monto_maximo']);
        $this->assertSame(480, $resultado['plazo_quincenas']);
        $this->assertSame(4.85, $resultado['tasa_efectiva_anual']);

        $prestamoService = app(PrestamoService::class);
        $cuotaEsperada = $prestamoService->calcularCuotaFrancesa(130 * self::SMMLV, $prestamoService->tasaPeriodica(4.85), 480);
        $this->assertEqualsWithDelta($cuotaEsperada, $resultado['cuota_quincenal'], 0.001);
        $this->assertLessThanOrEqual(1_500_000, $resultado['cuota_quincenal'] * 2);
    }

    public function test_calamidad_limitada_por_la_capacidad_disponible(): void
    {
        $empleado = $this->empleado(3_000_000);
        $calamidad = $this->modalidad('Calamidad doméstica y necesidades especiales', 72, 5);
        $educacion = $this->modalidad('Educación', 72, 17.5);
        $this->prestamoVigente($empleado, $educacion, 700_000);

        $resultado = $this->evaluar($empleado, $calamidad);

        // Disponible = 1.500.000 − 700.000 × 2 = 100.000, o sea una cuota de 50.000 por quincena.
        $tasaPeriodica = 1.0485 ** (1 / 24) - 1;
        $esperado = floor(50_000 * (1 - (1 + $tasaPeriodica) ** -72) / $tasaPeriodica);

        $this->assertTrue($resultado['puede']);
        $this->assertSame('capacidad', $resultado['limitado_por']);
        $this->assertLessThan(5 * self::SMMLV, $resultado['monto_maximo']);
        $this->assertSame((float) $esperado, (float) $resultado['monto_maximo']);
        $this->assertLessThanOrEqual(50_000.01, $resultado['cuota_quincenal']);
        $this->assertSame(72, $resultado['plazo_quincenas']);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function parametros(Empleado $empleado, TipoPrestamo $modalidad, array $extra = []): array
    {
        return ['cedula' => $empleado->cedula, 'id_tipo' => $modalidad->id_tipo, 'monto' => '4.000.000', 'plazo' => 72, ...$extra];
    }

    public function test_calcular_devuelve_los_datos_y_el_resultado_ya_armado(): void
    {
        $empleado = $this->empleado();
        $educacion = $this->modalidad('Educación', 72, 17.5);

        $respuesta = $this->getJson(route('simulador.calcular', $this->parametros($empleado, $educacion)))
            ->assertOk()
            ->assertJsonPath('errores', [])
            ->assertJsonPath('encabezado.periodos', 72)
            ->assertJsonPath('encabezado.tasa_total', 4.85)
            ->assertJsonPath('capacidad.usada', 'estimada')
            ->assertJsonMissingPath('tabla_amortizacion');

        $prestamoService = app(PrestamoService::class);
        $this->assertEqualsWithDelta(
            $prestamoService->calcularCuotaFrancesa(4_000_000, $prestamoService->tasaPeriodica(4.85), 72),
            $respuesta->json('cuota_quincenal'),
            0.001,
        );

        $html = $respuesta->json('html');
        $this->assertStringContainsString('Cuota fija quincenal', $html);
        $this->assertStringContainsString('Seguro (aparte)', $html);
        $this->assertStringContainsString('Cuota + seguro', $html);
        $this->assertStringContainsString('Tabla de amortización (72 cuotas)', $html);
        $this->assertStringContainsString('fechas estimadas', $html);
        $this->assertStringContainsString('Capacidad estimada con descuentos de ley', $html);
        $this->assertStringContainsString('Capacidad guardada del empleado', $html);
        $this->assertStringContainsString('Sugerencias para que quepa', $html);
    }

    public function test_el_boton_de_solicitud_lleva_cedula_modalidad_monto_y_plazo(): void
    {
        $empleado = $this->empleado();
        $educacion = $this->modalidad('Educación', 72, 17.5);

        $html = $this->getJson(route('simulador.calcular', $this->parametros($empleado, $educacion)))->assertOk()->json('html');

        $enlace = route('solicitudes.create', [
            'cedula_empleado' => $empleado->cedula,
            'id_tipo' => $educacion->id_tipo,
            'monto_solicitado' => '4000000',
            'plazo_solicitado' => 72,
        ]);
        $this->assertStringContainsString('Crear solicitud con estos datos', $html);
        $this->assertStringContainsString(e($enlace), $html);
        $this->assertStringNotContainsString('Editaste la tasa', $html);
    }

    public function test_si_se_edita_la_tasa_el_boton_avisa_que_la_solicitud_usara_la_oficial(): void
    {
        $empleado = $this->empleado();
        $educacion = $this->modalidad('Educación', 72, 17.5);

        $respuesta = $this->getJson(route('simulador.calcular', $this->parametros($empleado, $educacion, ['tasa_efectiva' => '8,2', 'puntos' => '1'])))
            ->assertOk()
            ->assertJsonPath('tasa.editada', true)
            ->assertJsonPath('encabezado.tasa_total', 9.2);

        $this->assertStringContainsString('Editaste la tasa', $respuesta->json('html'));
        $this->assertStringContainsString('oficial de tasas_int', $respuesta->json('html'));
    }

    public function test_el_formulario_de_solicitud_acepta_valores_previos_del_simulador(): void
    {
        $educacion = $this->modalidad('Educación', 72, 17.5);

        $respuesta = $this->get(route('solicitudes.create', [
            'cedula_empleado' => '1234567890',
            'id_tipo' => $educacion->id_tipo,
            'monto_solicitado' => '4000000',
            'plazo_solicitado' => 72,
        ]))->assertOk();

        $respuesta->assertSee('value="1234567890"', false);
        $respuesta->assertSee('value="4000000"', false);
        $respuesta->assertSee('id="plazo_solicitado"', false);
        $respuesta->assertSee('value="72"', false);
        $respuesta->assertSeeInOrder(['<option value="'.$educacion->id_tipo.'" selected', 'Educación'], false);
    }

    public function test_el_formulario_de_solicitud_ignora_valores_previos_invalidos(): void
    {
        $this->get(route('solicitudes.create', ['cedula_empleado' => 'abc<script>', 'monto_solicitado' => 'x', 'plazo_solicitado' => 'y']))
            ->assertOk()
            ->assertDontSee('<script>alert', false)
            ->assertDontSee('value="abc', false)
            ->assertSee('id="plazo_solicitado"', false);
    }

    public function test_calcular_valida_los_datos_de_entrada(): void
    {
        $empleado = $this->empleado();
        $educacion = $this->modalidad('Educación', 72, 17.5);

        $this->getJson(route('simulador.calcular'))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cedula', 'id_tipo', 'monto', 'plazo']);

        $this->getJson(route('simulador.calcular', $this->parametros($empleado, $educacion, ['cedula' => 'abc'])))->assertJsonValidationErrors('cedula');
        $this->getJson(route('simulador.calcular', $this->parametros($empleado, $educacion, ['id_tipo' => 9999])))->assertJsonValidationErrors('id_tipo');
        $this->getJson(route('simulador.calcular', $this->parametros($empleado, $educacion, ['monto' => '999'])))->assertJsonValidationErrors('monto');
        $this->getJson(route('simulador.calcular', $this->parametros($empleado, $educacion, ['plazo' => 0])))->assertJsonValidationErrors('plazo');
        $this->getJson(route('simulador.calcular', $this->parametros($empleado, $educacion, ['tasa_efectiva' => '120'])))->assertJsonValidationErrors('tasa_efectiva');
        $this->getJson(route('simulador.calcular', $this->parametros($empleado, $educacion, ['puntos' => '-1'])))->assertJsonValidationErrors('puntos');
        $this->getJson(route('simulador.calcular', $this->parametros($empleado, $educacion, ['retencion' => '-5'])))->assertJsonValidationErrors('retencion');
    }

    public function test_calcular_con_un_plazo_mayor_al_maximo_devuelve_el_error(): void
    {
        $empleado = $this->empleado();
        $educacion = $this->modalidad('Educación', 72, 17.5);

        $this->getJson(route('simulador.calcular', $this->parametros($empleado, $educacion, ['plazo' => 73])))
            ->assertStatus(422)
            ->assertJsonPath('errores', ['El plazo (73 quincenas) supera el máximo de la modalidad Educación: 72 quincenas.']);
    }

    public function test_calcular_con_cedula_inexistente_o_empleado_retirado(): void
    {
        $educacion = $this->modalidad('Educación', 72, 17.5);
        $retirado = Empleado::factory()->retirado()->create();

        $this->getJson(route('simulador.calcular', ['cedula' => '5555555555', 'id_tipo' => $educacion->id_tipo, 'monto' => 4_000_000, 'plazo' => 72]))
            ->assertNotFound()
            ->assertJson(['mensaje' => 'No existe un empleado con la cédula 5555555555.']);

        $this->getJson(route('simulador.calcular', $this->parametros($retirado, $educacion)))
            ->assertStatus(422)
            ->assertJson(['mensaje' => 'El empleado está retirado y no puede solicitar un crédito.']);
    }

    public function test_calcular_no_crea_solicitudes_prestamos_ni_auditoria(): void
    {
        $empleado = $this->empleado();
        $educacion = $this->modalidad('Educación', 72, 17.5);

        $this->getJson(route('simulador.calcular', $this->parametros($empleado, $educacion)))->assertOk();
        $this->getJson(route('simulador.calcular', $this->parametros($empleado, $educacion, ['plazo' => 500])))->assertStatus(422);
        $this->getJson(route('simulador.tasa', ['cedula' => $empleado->cedula, 'id_tipo' => $educacion->id_tipo, 'plazo' => 72]))->assertOk();

        $this->assertSame(0, SolicitudCredito::count());
        $this->assertSame(0, Prestamo::count());
        $this->assertSame(0, Auditoria::count());
    }

    public function test_la_tasa_oficial_prellena_base_puntos_y_plazo_maximo(): void
    {
        $empleado = $this->empleado();
        $educacion = $this->modalidad('Educación', 72, 17.5);

        $this->getJson(route('simulador.tasa', ['cedula' => $empleado->cedula, 'id_tipo' => $educacion->id_tipo, 'plazo' => 72]))
            ->assertOk()
            ->assertJsonPath('base', 4.85)
            ->assertJsonPath('puntos', 0)
            ->assertJsonPath('total', 4.85)
            ->assertJsonPath('plazo_maximo', 72)
            ->assertJsonPath('avisos', []);

        $this->getJson(route('simulador.tasa', ['cedula' => '5555555555', 'id_tipo' => $educacion->id_tipo]))->assertNotFound();
        $this->getJson(route('simulador.tasa', ['cedula' => $empleado->cedula]))->assertJsonValidationErrors('id_tipo');
    }

    public function test_la_tasa_de_vehiculo_trae_puntos_y_avisa_si_falta_la_fila(): void
    {
        $empleado = $this->empleado();
        $vehiculo = $this->modalidad('Vehículo', 168, 50);
        TasaVehiculo::factory()->create(['plazo_anios' => 4, 'es_primera_solicitud' => true, 'puntos_adicionales_ipc' => 1]);

        $this->getJson(route('simulador.tasa', ['cedula' => $empleado->cedula, 'id_tipo' => $vehiculo->id_tipo, 'plazo' => 96]))
            ->assertOk()
            ->assertJsonPath('base', 4.85)
            ->assertJsonPath('puntos', 1)
            ->assertJsonPath('total', 5.85)
            ->assertJsonPath('avisos', []);

        $this->getJson(route('simulador.tasa', ['cedula' => $empleado->cedula, 'id_tipo' => $vehiculo->id_tipo, 'plazo' => 144]))
            ->assertOk()
            ->assertJsonPath('puntos', 0)
            ->assertJsonPath('total', 4.85)
            ->assertJsonCount(1, 'avisos');
    }

    public function test_calcular_con_el_interruptor_apagado_usa_la_capacidad_guardada(): void
    {
        config(['prestamos.simulador.restar_descuentos_ley' => false]);
        $empleado = $this->empleado();
        $educacion = $this->modalidad('Educación', 72, 17.5);

        $this->getJson(route('simulador.calcular', $this->parametros($empleado, $educacion)))
            ->assertOk()
            ->assertJsonPath('capacidad.usada', 'guardada')
            ->assertJsonPath('capacidad.restar_descuentos_ley', false);
    }

    public function test_la_pantalla_trae_el_contenedor_de_la_hoja_impresa_y_oculta_el_resto_al_imprimir(): void
    {
        $this->get(route('simulador.index'))
            ->assertOk()
            ->assertSee('id="hoja-impresion"', false)
            ->assertSee('class="no-imprimir"', false)
            ->assertSee('solo-impresion', false)
            ->assertDontSee('id="imprimir-simulacion"', false);
    }

    public function test_el_boton_imprimir_solo_viene_con_un_resultado_calculado(): void
    {
        $empleado = $this->empleado();
        $educacion = $this->modalidad('Educación', 72, 17.5);

        $respuesta = $this->getJson(route('simulador.calcular', $this->parametros($empleado, $educacion)))->assertOk();

        $this->assertStringContainsString('id="imprimir-simulacion"', $respuesta->json('html'));
        $this->assertStringContainsString('>Imprimir</button>', $respuesta->json('html'));
        $this->assertStringNotContainsString('hoja-simulacion', $respuesta->json('html'));

        $this->getJson(route('simulador.calcular', $this->parametros($empleado, $educacion, ['plazo' => 500])))
            ->assertStatus(422)
            ->assertJsonMissingPath('html')
            ->assertJsonMissingPath('html_impresion');
    }

    public function test_la_hoja_impresa_trae_todos_los_bloques_para_archivar(): void
    {
        Carbon::setTestNow(now()->setTime(15, 30));
        $empleado = $this->empleado();
        $empleado->update(['nombre_completo' => 'Ana María Pérez', 'tipo_vinculacion' => 'Docente']);
        $educacion = $this->modalidad('Educación', 72, 17.5);

        $hoja = $this->getJson(route('simulador.calcular', $this->parametros($empleado, $educacion)))->assertOk()->json('html_impresion');

        foreach ([
            'Politécnico Colombiano Jaime Isaza Cadavid',
            'alt="Politécnico Colombiano Jaime Isaza Cadavid"',
            'Simulación de crédito',
            'Ana María Pérez',
            $empleado->cedula,
            'Tipo de vinculación',
            'Docente',
            'Educación',
            '$4.000.000',
            '72 quincenas',
            'Tasa efectiva anual',
            'Puntos adicionales',
            'Cuota fija quincenal',
            'Seguro estimado (aparte)',
            'Cuota + seguro',
            'Veredicto:',
            'Capacidad estimada con descuentos de ley',
            'Capacidad guardada del empleado',
            'Tabla de amortización (72 cuotas)',
            'Simulación informativa; no constituye aprobación del crédito.',
            'por '.auth()->user()->nombre_completo,
            'Simulación realizada el '.now()->format('d/m/Y').' 10:30',
        ] as $esperado) {
            $this->assertStringContainsString($esperado, $hoja);
        }

        $this->assertStringContainsString('<thead>', $hoja);
        $this->assertSame(72, preg_match_all('#<tr>\s*<td>\d+</td>\s*<td>\d{2}/\d{2}/\d{4}</td>#', $hoja));
        $this->assertStringNotContainsString('Crear solicitud', $hoja);
        $this->assertStringNotContainsString('Sugerencias para que quepa', $hoja);
        $this->assertStringNotContainsString('<details', $hoja);
        $this->assertStringNotContainsString('<button', $hoja);

        Carbon::setTestNow();
    }

    public function test_las_cifras_de_la_hoja_impresa_son_las_de_la_pantalla(): void
    {
        $empleado = $this->empleado(salario: 4_200_000);
        $educacion = $this->modalidad('Educación', 72, 17.5);

        $respuesta = $this->getJson(route('simulador.calcular', $this->parametros($empleado, $educacion, ['retencion' => '150000'])))->assertOk();
        $pantalla = $respuesta->json('html');
        $hoja = $respuesta->json('html_impresion');
        $pesos = fn (float $valor): string => '$'.number_format($valor, 2, ',', '.');
        $estimada = $respuesta->json('capacidad.estimada');

        foreach ([
            $pesos($respuesta->json('cuota_quincenal')),
            $pesos($respuesta->json('seguro')),
            $pesos($respuesta->json('cuota_mas_seguro')),
            $pesos($respuesta->json('total_intereses')),
            $pesos($respuesta->json('total_a_pagar')),
            $pesos($respuesta->json('capacidad.cuota_mensual')),
            $pesos(abs($respuesta->json('capacidad.margen'))),
            $pesos($respuesta->json('capacidad.comprometida')),
            $pesos($estimada['salud']['valor']),
            $pesos($estimada['pension']['valor']),
            $pesos($estimada['solidaridad']['valor']),
            $pesos($estimada['retencion']),
            $pesos($estimada['base']),
            $pesos($estimada['capacidad']),
            $pesos($estimada['disponible']),
            $pesos($respuesta->json('capacidad.guardada.capacidad')),
            $pesos($respuesta->json('capacidad.guardada.disponible')),
        ] as $cifra) {
            $this->assertStringContainsString($cifra, $pantalla, "Falta {$cifra} en la pantalla");
            $this->assertStringContainsString($cifra, $hoja, "Falta {$cifra} en la hoja impresa");
        }

        $this->assertStringContainsString('$150.000,00', $hoja);
    }

    public function test_la_tasa_corregida_a_mano_se_ve_claramente_en_la_hoja_impresa(): void
    {
        $empleado = $this->empleado();
        $educacion = $this->modalidad('Educación', 72, 17.5);

        $corregida = $this->getJson(route('simulador.calcular', $this->parametros($empleado, $educacion, ['tasa_efectiva' => '8,2', 'puntos' => '1'])))
            ->assertOk()
            ->json('html_impresion');

        $this->assertStringContainsString('TASA CORREGIDA A MANO', $corregida);
        $this->assertStringContainsString('4,8500 % E.A. en total', $corregida);
        $this->assertStringContainsString('9,2000 %', $corregida);
        $this->assertStringNotContainsString('sin correcciones', $corregida);

        $oficial = $this->getJson(route('simulador.calcular', $this->parametros($empleado, $educacion)))->assertOk()->json('html_impresion');

        $this->assertStringNotContainsString('TASA CORREGIDA A MANO', $oficial);
        $this->assertStringContainsString('Tasa oficial de la modalidad, sin correcciones.', $oficial);
    }

    public function test_la_hoja_impresa_incluye_los_avisos_de_la_simulacion(): void
    {
        $empleado = $this->empleado();
        $educacion = $this->modalidad('Educación', 72, 17.5);

        $respuesta = $this->getJson(route('simulador.calcular', $this->parametros($empleado, $educacion, ['monto' => '900.000.000'])))->assertOk();

        $this->assertNotEmpty($respuesta->json('avisos'));

        foreach ($respuesta->json('avisos') as $aviso) {
            $this->assertStringContainsString(e($aviso), $respuesta->json('html_impresion'));
        }

        $this->assertStringContainsString('<h2>Avisos</h2>', $respuesta->json('html_impresion'));
    }

    public function test_la_pantalla_define_los_estilos_de_impresion_de_la_hoja(): void
    {
        $pantalla = $this->get(route('simulador.index'))->assertOk()->getContent();

        foreach (['@page simulacion', '.hoja-simulacion', 'table-header-group', '.hoja-pie', 'position: fixed', 'size: A4 portrait'] as $regla) {
            $this->assertStringContainsString($regla, $pantalla);
        }
    }
}
