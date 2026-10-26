<?php

namespace Tests\Feature;

use App\Models\CuantiaSmmlv;
use App\Models\Empleado;
use App\Models\PresupuestoAnual;
use App\Models\SmmlvAnual;
use App\Models\TasaInt;
use App\Models\TipoPrestamo;
use App\Models\Usuario;
use App\Services\CalendarioQuincenalService;
use App\Services\SimuladorCreditoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * «Fecha estimada de desembolso» del simulador: opcional; si se diligencia, la primera
 * cuota estimada sigue la misma regla del desembolso real y solo cambian las fechas.
 */
class SimuladorFechaDesembolsoTest extends TestCase
{
    use RefreshDatabase;

    private const PLAZO = 24;

    private const NOTA_VACIA = 'La fecha real de la primera cuota se define al desembolsar';

    private const NOTA_CON_FECHA = 'Estimación según la fecha de desembolso indicada; la fecha real se define al desembolsar';

    private TipoPrestamo $modalidad;

    private Empleado $empleado;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-26 10:00:00');
        SmmlvAnual::factory()->create(['anio' => 2026, 'valor' => 1_300_000]);
        $this->modalidad = TipoPrestamo::factory()->create(['nombre' => 'Educación', 'plazo_maximo' => 480]);
        TasaInt::factory()->create(['id_tipo' => $this->modalidad->id_tipo, 'anio' => 2026, 'tasa_anual' => 4.85]);
        CuantiaSmmlv::factory()->create(['id_tipo' => $this->modalidad->id_tipo, 'tramo_desde_smmlv' => 0, 'tramo_hasta_smmlv' => null, 'cuantia_maxima_smmlv' => 220]);
        PresupuestoAnual::factory()->create(['id_tipo' => $this->modalidad->id_tipo, 'anio' => 2026, 'monto_asignado' => 10_000_000_000]);
        $this->empleado = Empleado::factory()->create(['salario' => 8_000_000, 'fecha_vinculacion' => '2020-01-15']);
    }

    /**
     * @return array<string, mixed>
     */
    private function simular(?string $fecha = null): array
    {
        return app(SimuladorCreditoService::class)->simular($this->empleado, $this->modalidad, 5_000_000, self::PLAZO, fechaDesembolso: $fecha);
    }

    private function calcular(?string $fecha): TestResponse
    {
        $parametros = ['cedula' => $this->empleado->cedula, 'id_tipo' => $this->modalidad->id_tipo, 'monto' => '5.000.000', 'plazo' => self::PLAZO];

        if ($fecha !== null) {
            $parametros['fecha_desembolso'] = $fecha;
        }

        return $this->actingAs(Usuario::factory()->create())->getJson(route('simulador.calcular', $parametros));
    }

    /**
     * @param  array<string, mixed>  $resultado
     * @return list<string>
     */
    private function fechas(array $resultado): array
    {
        return array_column($resultado['tabla_amortizacion'], 'fecha');
    }

    // ───────── Sin fecha: como hoy ─────────

    public function test_sin_fecha_todo_queda_como_hoy_con_la_nota_de_la_fecha_real(): void
    {
        $resultado = $this->simular();

        $this->assertNull($resultado['primera_cuota']['fecha_desembolso']);
        $this->assertSame(app(CalendarioQuincenalService::class)->vencimientosDesde('2026-11-10', self::PLAZO), $this->fechas($resultado));

        $respuesta = $this->calcular(null)->assertOk();
        $this->assertStringContainsString(self::NOTA_VACIA, $respuesta->json('html'));
        $this->assertStringNotContainsString('Primera cuota estimada:', $respuesta->json('html'));
        $this->assertStringNotContainsString('Primera cuota estimada:', $respuesta->json('html_impresion'));
        $this->assertStringNotContainsString(self::NOTA_CON_FECHA, $respuesta->json('html_impresion'));
    }

    public function test_una_fecha_vacia_se_trata_como_sin_fecha(): void
    {
        $respuesta = $this->calcular('')->assertOk();

        $this->assertStringContainsString(self::NOTA_VACIA, $respuesta->json('html'));
        $this->assertStringNotContainsString('Primera cuota estimada:', $respuesta->json('html'));
    }

    // ───────── Con fecha ─────────

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function fechasYPrimeraCuota(): array
    {
        return [
            'el 3 → 15' => ['2026-11-03', '2026-11-15'],
            'el 15 exacto → último día' => ['2026-11-15', '2026-11-30'],
            'el 20 → último día' => ['2026-11-20', '2026-11-30'],
            'el último día exacto → 15 del mes siguiente' => ['2026-11-30', '2026-12-15'],
            'febrero: el 15 → 28' => ['2027-02-15', '2027-02-28'],
            'febrero: el 28 → 15 de marzo' => ['2027-02-28', '2027-03-15'],
            'febrero bisiesto: el 15 → 29' => ['2028-02-15', '2028-02-29'],
            'fin de año: el 20 de diciembre → 31' => ['2026-12-20', '2026-12-31'],
            'fin de año: el 31 de diciembre → 15 de enero' => ['2026-12-31', '2027-01-15'],
            'una fecha pasada también vale' => ['2020-01-10', '2020-01-15'],
        ];
    }

    #[DataProvider('fechasYPrimeraCuota')]
    public function test_la_primera_cuota_estimada_sigue_la_regla_del_desembolso_real(string $desembolso, string $esperada): void
    {
        $resultado = $this->simular($desembolso);

        $this->assertSame($desembolso, $resultado['primera_cuota']['fecha_desembolso']);
        $this->assertSame($esperada, $resultado['primera_cuota']['fecha']);
        $this->assertSame($esperada, app(CalendarioQuincenalService::class)->primerVencimientoPosterior($desembolso));
        $this->assertSame(app(CalendarioQuincenalService::class)->vencimientosDesde($esperada, self::PLAZO), $this->fechas($resultado), 'Las demás cuotas siguen el calendario quincenal.');
    }

    public function test_la_pantalla_muestra_la_primera_cuota_estimada_con_su_nota(): void
    {
        $html = $this->calcular('2026-11-03')->assertOk()->json('html');

        $this->assertStringContainsString('Primera cuota estimada: 15/11/2026', $html);
        $this->assertStringContainsString(self::NOTA_CON_FECHA, $html);
        $this->assertStringNotContainsString(self::NOTA_VACIA, $html);
    }

    public function test_la_hoja_impresa_muestra_la_linea_solo_si_hay_fecha(): void
    {
        $con = $this->calcular('2026-11-20')->assertOk()->json('html_impresion');
        $sin = $this->calcular(null)->assertOk()->json('html_impresion');

        $this->assertStringContainsString('Primera cuota estimada: 30/11/2026', $con);
        $this->assertStringContainsString(self::NOTA_CON_FECHA, $con);
        $this->assertStringNotContainsString('Primera cuota estimada:', $sin);
        $this->assertStringContainsString(self::NOTA_VACIA, $sin);
    }

    public function test_la_fecha_solo_cambia_las_fechas_no_los_valores(): void
    {
        $sin = $this->simular();
        $con = $this->simular('2027-02-28');

        $sinFechas = fn (array $r): array => array_map(fn (array $fila): array => array_diff_key($fila, ['fecha' => true]), $r['tabla_amortizacion']);

        $this->assertSame($sinFechas($sin), $sinFechas($con));
        $this->assertNotSame($this->fechas($sin), $this->fechas($con));

        foreach (['cuota_quincenal', 'seguro', 'cuota_mas_seguro', 'total_intereses', 'total_a_pagar', 'capacidad', 'encabezado', 'sugerencias'] as $clave) {
            $this->assertSame($sin[$clave], $con[$clave], "«{$clave}» no debe cambiar con la fecha.");
        }
    }

    // ───────── Validación ─────────

    /**
     * @return array<string, array{0: string}>
     */
    public static function fechasInvalidas(): array
    {
        return [
            'texto' => ['abc'],
            'día inexistente' => ['2026-02-30'],
            'formato con barras' => ['03/03/2026'],
            'mes 13' => ['2026-13-01'],
        ];
    }

    #[DataProvider('fechasInvalidas')]
    public function test_una_fecha_invalida_se_rechaza_con_un_mensaje_en_espanol(string $fecha): void
    {
        $this->calcular($fecha)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fecha_desembolso')
            ->assertJsonPath('errors.fecha_desembolso.0', 'La fecha estimada de desembolso no es una fecha válida: usa el formato AAAA-MM-DD (por ejemplo 2026-03-03).');
    }

    public function test_el_campo_del_formulario_existe_y_es_opcional(): void
    {
        $this->actingAs(Usuario::factory()->create())->get(route('simulador.index'))
            ->assertOk()
            ->assertSee('id="fecha_desembolso"', false)
            ->assertSee('Fecha estimada de desembolso')
            ->assertSee('Opcional');
    }
}
