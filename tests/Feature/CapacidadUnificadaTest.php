<?php

namespace Tests\Feature;

use App\Enums\ConceptoSeguro;
use App\Enums\EstadoPrestamo;
use App\Models\CuantiaSmmlv;
use App\Models\Empleado;
use App\Models\Prestamo;
use App\Models\PresupuestoAnual;
use App\Models\PrimaSeguro;
use App\Models\SmmlvAnual;
use App\Models\SolicitudCredito;
use App\Models\TasaInt;
use App\Models\TipoPrestamo;
use App\Models\Usuario;
use App\Services\CapacidadEndeudamientoService;
use App\Services\SimuladorCreditoService;
use App\Services\SolicitudCreditoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Una sola capacidad de endeudamiento para el simulador y la aprobación
 * (CapacidadEndeudamientoService): 50 % de (salario − descuentos de ley), menos las
 * cuotas vigentes; en vivienda la cuota comparada incluye los seguros. La capacidad
 * guardada es solo referencia salvo que capacidad.fuente sea «guardada».
 */
class CapacidadUnificadaTest extends TestCase
{
    use RefreshDatabase;

    private const SMMLV = 1_300_000;

    private int $anio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->anio = now()->year;
        SmmlvAnual::factory()->create(['anio' => $this->anio, 'valor' => self::SMMLV]);
    }

    private function modalidad(string $nombre, int $plazoMaximo = 120): TipoPrestamo
    {
        $modalidad = TipoPrestamo::factory()->create(['nombre' => $nombre, 'plazo_maximo' => $plazoMaximo]);
        TasaInt::factory()->create(['id_tipo' => $modalidad->id_tipo, 'anio' => $this->anio, 'tasa_anual' => 4.85]);
        CuantiaSmmlv::factory()->create(['id_tipo' => $modalidad->id_tipo, 'tramo_desde_smmlv' => 0, 'tramo_hasta_smmlv' => null, 'cuantia_maxima_smmlv' => 400]);
        PresupuestoAnual::factory()->create(['id_tipo' => $modalidad->id_tipo, 'anio' => $this->anio, 'monto_asignado' => 100_000_000_000]);

        return $modalidad;
    }

    private function empleado(float $salario = 8_000_000, ?float $guardada = null): Empleado
    {
        return Empleado::factory()->create([
            'salario' => $salario,
            'capacidad_endeudamiento' => $guardada ?? $salario * 0.5,
            'fecha_vinculacion' => now()->subYears(5),
        ]);
    }

    private function solicitud(Empleado $empleado, TipoPrestamo $modalidad, float $monto, int $plazo): SolicitudCredito
    {
        return SolicitudCredito::factory()->create([
            'cedula_empleado' => $empleado->cedula,
            'id_tipo' => $modalidad->id_tipo,
            'monto_solicitado' => $monto,
            'plazo_solicitado' => $plazo,
            'fecha_solicitud' => now()->toDateString(),
        ]);
    }

    /**
     * @return list<string> códigos de incumplimiento de la aprobación
     */
    private function codigos(SolicitudCredito $solicitud): array
    {
        return array_column(app(SolicitudCreditoService::class)->incumplimientos($solicitud), 'codigo');
    }

    private function conPrestamoVigente(Empleado $empleado, TipoPrestamo $modalidad, float $cuota): void
    {
        Prestamo::factory()->create([
            'cedula_empleado' => $empleado->cedula,
            'id_tipo' => $modalidad->id_tipo,
            'cuota_periodica' => $cuota,
            'estado' => EstadoPrestamo::Vigente,
        ]);
    }

    /**
     * Capacidad esperada a mano: 50 % de (salario − 4 % − 4 % − solidaridad − retención).
     */
    private function capacidadEsperada(float $salario, float $porcentajeSolidaridad, float $retencion = 0): float
    {
        return round(0.5 * ($salario - $salario * 0.08 - $salario * $porcentajeSolidaridad / 100 - $retencion), 2);
    }

    // ───────── Configuración ─────────

    public function test_la_fuente_por_defecto_es_la_calculada(): void
    {
        $this->assertSame('calculada', config('prestamos.capacidad.fuente'));
        $this->assertSame('calculada', app(CapacidadEndeudamientoService::class)->fuente());
    }

    // ───────── Mismo cálculo en el simulador y en la aprobación ─────────

    /**
     * @return array<string, array{0: float, 1: float}>
     */
    public static function salariosPorTramoDelFondo(): array
    {
        return [
            'menos de 4 SMMLV (0 %)' => [3_000_000, 0.0],
            'desde 4 SMMLV (1 %)' => [6_000_000, 1.0],
            'desde 16 SMMLV (1,2 %)' => [22_000_000, 1.2],
            'desde 20 SMMLV (2 %)' => [30_000_000, 2.0],
        ];
    }

    #[DataProvider('salariosPorTramoDelFondo')]
    public function test_el_simulador_y_la_aprobacion_dan_la_misma_capacidad_disponible_y_el_mismo_veredicto(float $salario, float $solidaridad): void
    {
        $educacion = $this->modalidad('Educación', 72);
        $empleado = $this->empleado($salario, 123_456);
        $this->conPrestamoVigente($empleado, $educacion, 100_000);

        $esperada = $this->capacidadEsperada($salario, $solidaridad);
        $disponibleEsperado = round($esperada - 200_000, 2);

        $this->assertSame($disponibleEsperado, app(CapacidadEndeudamientoService::class)->paraEmpleado($empleado)['disponible']);

        foreach ([1_000_000, 20_000_000, 60_000_000, 200_000_000] as $monto) {
            $simulacion = app(SimuladorCreditoService::class)->simular($empleado, $educacion, $monto, 72);
            $aprobacionRechaza = in_array('capacidad_excedida', $this->codigos($this->solicitud($empleado, $educacion, $monto, 72)), true);

            $this->assertSame('estimada', $simulacion['capacidad']['usada']);
            $this->assertSame($disponibleEsperado, $simulacion['capacidad']['disponible'], "salario {$salario}, monto {$monto}");
            $this->assertSame($aprobacionRechaza, ! $simulacion['capacidad']['cabe'], "salario {$salario}, monto {$monto}: el veredicto debe coincidir");
        }
    }

    public function test_la_retencion_manual_resta_del_calculo_compartido_y_sin_retencion_coincide_con_la_aprobacion(): void
    {
        $educacion = $this->modalidad('Educación', 72);
        $empleado = $this->empleado(6_000_000);
        $servicio = app(CapacidadEndeudamientoService::class);

        $sin = app(SimuladorCreditoService::class)->simular($empleado, $educacion, 20_000_000, 72, retencion: 0);
        $con = app(SimuladorCreditoService::class)->simular($empleado, $educacion, 20_000_000, 72, retencion: 400_000);

        $this->assertSame($servicio->paraEmpleado($empleado)['disponible'], $sin['capacidad']['disponible']);
        $this->assertSame($servicio->paraEmpleado($empleado, $this->anio, 400_000)['disponible'], $con['capacidad']['disponible']);
        $this->assertSame(round($sin['capacidad']['disponible'] - 200_000, 2), $con['capacidad']['disponible']);
        $this->assertSame($this->capacidadEsperada(6_000_000, 1.0, 400_000), $con['capacidad']['estimada']['capacidad']);
    }

    // ───────── Fuente calculada o guardada ─────────

    public function test_con_fuente_calculada_manda_la_calculada_aunque_la_guardada_sea_distinta(): void
    {
        $educacion = $this->modalidad('Educación', 72);
        $empleado = $this->empleado(8_000_000, 1_000_000);
        $solicitud = $this->solicitud($empleado, $educacion, 40_000_000, 72);

        $capacidad = app(CapacidadEndeudamientoService::class)->paraEmpleado($empleado);

        $this->assertSame('calculada', $capacidad['fuente']);
        $this->assertSame($this->capacidadEsperada(8_000_000, 1.0), $capacidad['total']);
        $this->assertSame(1_000_000.0, $capacidad['guardada']['capacidad']);
        $this->assertNotContains('capacidad_excedida', $this->codigos($solicitud), 'Con la guardada (1.000.000) no cabría; con la calculada sí.');
        $this->assertTrue(app(SimuladorCreditoService::class)->simular($empleado, $educacion, 40_000_000, 72)['capacidad']['cabe']);
    }

    public function test_con_fuente_guardada_manda_la_guardada_como_antes(): void
    {
        config(['prestamos.capacidad.fuente' => 'guardada']);
        $educacion = $this->modalidad('Educación', 72);
        $empleado = $this->empleado(8_000_000, 1_000_000);
        $solicitud = $this->solicitud($empleado, $educacion, 40_000_000, 72);

        $capacidad = app(CapacidadEndeudamientoService::class)->paraEmpleado($empleado);

        $this->assertSame('guardada', $capacidad['fuente']);
        $this->assertSame(1_000_000.0, $capacidad['total']);
        $this->assertContains('capacidad_excedida', $this->codigos($solicitud));

        $simulacion = app(SimuladorCreditoService::class)->simular($empleado, $educacion, 40_000_000, 72);
        $this->assertSame('guardada', $simulacion['capacidad']['usada']);
        $this->assertFalse($simulacion['capacidad']['cabe']);
        $this->assertTrue($simulacion['capacidad']['veredictos_distintos']);
    }

    public function test_con_fuente_guardada_y_capacidad_nula_se_conserva_el_comportamiento_actual(): void
    {
        config(['prestamos.capacidad.fuente' => 'guardada']);
        $educacion = $this->modalidad('Educación', 72);
        $empleado = $this->empleado(8_000_000);
        $empleado->forceFill(['capacidad_endeudamiento' => null])->save();
        $solicitud = $this->solicitud($empleado->fresh(), $educacion, 1_000_000, 72);

        $capacidad = app(CapacidadEndeudamientoService::class)->paraEmpleado($empleado->fresh());

        $this->assertTrue($capacidad['calculable']);
        $this->assertSame(0.0, $capacidad['total']);
        $this->assertContains('capacidad_excedida', $this->codigos($solicitud));
        $this->assertNotContains('capacidad_no_calculable', $this->codigos($solicitud));
    }

    public function test_la_capacidad_guardada_nula_no_afecta_a_la_fuente_calculada(): void
    {
        $educacion = $this->modalidad('Educación', 72);
        $empleado = $this->empleado(8_000_000);
        $empleado->forceFill(['capacidad_endeudamiento' => null])->save();

        $this->assertNotContains('capacidad_excedida', $this->codigos($this->solicitud($empleado->fresh(), $educacion, 1_000_000, 72)));
    }

    // ───────── Sin salario ─────────

    public function test_sin_salario_no_se_puede_calcular_y_la_aprobacion_no_pasa(): void
    {
        $educacion = $this->modalidad('Educación', 72);
        $empleado = $this->empleado(0, 500_000);
        $solicitud = $this->solicitud($empleado, $educacion, 1_000_000, 72);

        $capacidad = app(CapacidadEndeudamientoService::class)->paraEmpleado($empleado);
        $this->assertFalse($capacidad['calculable']);
        $this->assertSame(0.0, $capacidad['disponible']);

        $this->assertContains('capacidad_no_calculable', $this->codigos($solicitud));
        $this->assertNotContains('capacidad_excedida', $this->codigos($solicitud));
        $this->assertContains(
            'No se puede calcular la capacidad de endeudamiento: el empleado no tiene salario registrado.',
            app(SolicitudCreditoService::class)->validar($solicitud),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No se puede calcular la capacidad de endeudamiento');
        app(SolicitudCreditoService::class)->aprobar($solicitud, Usuario::factory()->create());
    }

    public function test_con_fuente_guardada_el_salario_cero_no_agrega_el_incumplimiento_de_calculo(): void
    {
        config(['prestamos.capacidad.fuente' => 'guardada']);
        $educacion = $this->modalidad('Educación', 72);
        $empleado = $this->empleado(0, 500_000);

        $this->assertNotContains('capacidad_no_calculable', $this->codigos($this->solicitud($empleado, $educacion, 1_000_000, 72)));
    }

    public function test_el_simulador_no_evalua_la_modalidad_de_quien_no_tiene_salario(): void
    {
        $educacion = $this->modalidad('Educación', 72);
        $empleado = $this->empleado(0, 500_000);

        $resultado = app(SimuladorCreditoService::class)->evaluarModalidad($empleado, $educacion);

        $this->assertFalse($resultado['puede']);
    }

    // ───────── Vivienda con seguros / otras modalidades ─────────

    public function test_en_vivienda_la_cuota_comparada_incluye_los_seguros_y_pasa_justo_en_el_limite(): void
    {
        $this->fijarPrima(ConceptoSeguro::IncendioTerremoto, 0.0002);
        $vivienda = $this->modalidad('Vivienda — compra de inmueble');
        $empleado = $this->empleado(8_000_000);

        $maximo = app(SimuladorCreditoService::class)->evaluarModalidad($empleado, $vivienda);
        $this->assertTrue($maximo['puede']);
        $monto = $maximo['monto_maximo'];

        $this->assertNotContains('capacidad_excedida', $this->codigos($this->solicitud($empleado, $vivienda, $monto, 120)), 'El monto máximo calculado con seguros debe aprobarse.');
        $this->assertContains('capacidad_excedida', $this->codigos($this->solicitud($empleado, $vivienda, $monto + 2_000, 120)), 'Por poco más ya no cabe.');

        $simulacion = app(SimuladorCreditoService::class)->simular($empleado, $vivienda, $monto, 120);
        $this->assertTrue($simulacion['capacidad']['cabe']);
        $this->assertGreaterThan(0, $simulacion['capacidad']['seguro_mensual']);
        $this->assertSame(
            round($simulacion['cuota_quincenal'] * 2 + $simulacion['seguro'] * 2, 2),
            $simulacion['capacidad']['cuota_mensual'],
        );
        $this->assertFalse(app(SimuladorCreditoService::class)->simular($empleado, $vivienda, $monto + 2_000, 120)['capacidad']['cabe']);
    }

    public function test_un_monto_que_cabe_sin_seguros_pero_no_con_ellos_se_rechaza_en_vivienda_y_pasa_en_educacion(): void
    {
        $vivienda = $this->modalidad('Vivienda — compra de inmueble');
        $educacion = $this->modalidad('Educación', 120);
        $empleado = $this->empleado(8_000_000);

        $monto = app(SimuladorCreditoService::class)->evaluarModalidad($empleado, $vivienda)['monto_maximo'] + 2_000;

        $this->assertContains('capacidad_excedida', $this->codigos($this->solicitud($empleado, $vivienda, $monto, 120)));
        $this->assertNotContains('capacidad_excedida', $this->codigos($this->solicitud($empleado, $educacion, $monto, 120)), 'Fuera de vivienda solo se compara la cuota.');

        $simulacion = app(SimuladorCreditoService::class)->simular($empleado, $educacion, $monto, 120);
        $this->assertSame(0.0, $simulacion['capacidad']['seguro_mensual']);
        $this->assertSame(round($simulacion['cuota_quincenal'] * 2, 2), $simulacion['capacidad']['cuota_mensual']);
    }

    public function test_el_incendio_y_terremoto_solo_suma_cuando_su_prima_es_mayor_que_cero(): void
    {
        $vivienda = $this->modalidad('Vivienda — compra de inmueble');
        $servicio = app(CapacidadEndeudamientoService::class);

        $sinIncendio = $servicio->cuotaAComparar(100_000_000, 1_000_000, $this->anio, true);
        $this->fijarPrima(ConceptoSeguro::IncendioTerremoto, 0.0002);
        $conIncendio = $servicio->cuotaAComparar(100_000_000, 1_000_000, $this->anio, true);

        $this->assertSame(round(100_000_000 * 0.0003231 * 2, 2), $sinIncendio['seguro_mensual']);
        $this->assertSame(round($sinIncendio['seguro_mensual'] + 100_000_000 * 0.0002 * 2, 2), $conIncendio['seguro_mensual']);
        $this->assertSame(0.0, $servicio->cuotaAComparar(100_000_000, 1_000_000, $this->anio, false)['seguro_mensual']);
        $this->assertNotNull($vivienda);
    }

    // ───────── Mensaje del incumplimiento ─────────

    public function test_el_mensaje_de_capacidad_en_vivienda_trae_cuota_seguros_y_capacidad_disponible(): void
    {
        $this->fijarPrima(ConceptoSeguro::IncendioTerremoto, 0.0002);
        $vivienda = $this->modalidad('Vivienda — compra de inmueble');
        $empleado = $this->empleado(3_000_000);
        $solicitud = $this->solicitud($empleado, $vivienda, 100_000_000, 120);

        $mensajes = implode(' ', app(SolicitudCreditoService::class)->validar($solicitud));

        $this->assertStringContainsString('La cuota solicitada más los seguros', $mensajes);
        $this->assertMatchesRegularExpression('/Cuota: \$[\d.]+ al mes \(\$[\d.]+ por quincena\); seguros estimados: \$[\d.]+ al mes\./', $mensajes);
        $this->assertStringContainsString('Capacidad total: $1.380.000; comprometida con préstamos vigentes: $0; disponible: $1.380.000.', $mensajes);
    }

    public function test_el_mensaje_fuera_de_vivienda_no_menciona_seguros(): void
    {
        $educacion = $this->modalidad('Educación', 120);
        $empleado = $this->empleado(3_000_000);

        $mensajes = implode(' ', app(SolicitudCreditoService::class)->validar($this->solicitud($empleado, $educacion, 100_000_000, 120)));

        $this->assertStringContainsString('supera la capacidad de endeudamiento disponible del empleado', $mensajes);
        $this->assertStringNotContainsString('seguros', $mensajes);
        $this->assertStringContainsString('Capacidad total: $1.380.000;', $mensajes);
    }

    // ───────── Ficha del empleado ─────────

    public function test_la_ficha_muestra_la_capacidad_calculada_con_la_guardada_como_referencia_si_difieren(): void
    {
        $empleado = $this->empleado(8_000_000, 1_000_000);

        $this->actingAs(Usuario::factory()->administrador()->create())->get(route('empleados.show', $empleado))
            ->assertOk()
            ->assertSee('Capacidad calculada (referencia: la guardada es 1,000,000.00)', false)
            ->assertSee('3,640,000.00');
    }

    public function test_la_ficha_no_marca_referencia_si_coinciden(): void
    {
        $empleado = $this->empleado(8_000_000, 3_640_000);

        $this->actingAs(Usuario::factory()->administrador()->create())->get(route('empleados.show', $empleado))
            ->assertOk()
            ->assertSee('Capacidad calculada')
            ->assertDontSee('referencia: la guardada es');
    }

    public function test_la_ficha_avisa_si_no_se_puede_calcular(): void
    {
        $empleado = $this->empleado(0, 1_000_000);

        $this->actingAs(Usuario::factory()->administrador()->create())->get(route('empleados.show', $empleado))
            ->assertOk()
            ->assertSee('No se puede calcular');
    }

    public function test_la_columna_guardada_no_se_modifica_al_consultar(): void
    {
        $empleado = $this->empleado(8_000_000, 1_000_000);

        $this->actingAs(Usuario::factory()->administrador()->create())->get(route('empleados.show', $empleado))->assertOk();
        app(CapacidadEndeudamientoService::class)->paraEmpleado($empleado);

        $this->assertEquals(1_000_000, $empleado->fresh()->capacidad_endeudamiento);
    }

    private function fijarPrima(ConceptoSeguro $concepto, float $tasa): void
    {
        PrimaSeguro::updateOrCreate(['concepto' => $concepto->value, 'anio' => $this->anio], ['tasa' => $tasa]);
    }
}
