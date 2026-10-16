<?php

namespace Tests\Feature;

use App\Enums\EstadoCuota;
use App\Enums\EstadoPrestamo;
use App\Models\CuantiaSmmlv;
use App\Models\Cuota;
use App\Models\Desembolso;
use App\Models\Empleado;
use App\Models\Prestamo;
use App\Models\PresupuestoAnual;
use App\Models\SmmlvAnual;
use App\Models\TasaInt;
use App\Models\TipoPrestamo;
use App\Models\Usuario;
use App\Services\PrestamoService;
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

    public function test_la_pantalla_no_pide_monto_ni_plazo_y_esta_en_el_menu_de_creditos(): void
    {
        $this->get(route('simulador.index'))
            ->assertOk()
            ->assertSee('Simulador de crédito')
            ->assertSee('Cédula del empleado')
            ->assertSee('id="cedula_empleado"', false)
            ->assertSee('maxlength="10"', false)
            ->assertSee('Selecciona una modalidad')
            ->assertSee(route('simulador.index'), false)
            ->assertDontSee('Monto solicitado')
            ->assertDontSee('Plazo (quincenas)');
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
    }

    public function test_un_usuario_deshabilitado_no_puede_usar_el_simulador(): void
    {
        $this->actingAs(Usuario::factory()->create(['habilitado' => false]));

        $this->get(route('simulador.index'))->assertRedirect(route('login'));
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
}
