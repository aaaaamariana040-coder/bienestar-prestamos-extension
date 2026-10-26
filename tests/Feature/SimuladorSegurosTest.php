<?php

namespace Tests\Feature;

use App\Enums\ConceptoSeguro;
use App\Models\CuantiaSmmlv;
use App\Models\Empleado;
use App\Models\PresupuestoAnual;
use App\Models\PrimaSeguro;
use App\Models\SmmlvAnual;
use App\Models\SolicitudCredito;
use App\Models\TasaInt;
use App\Models\TipoPrestamo;
use App\Models\Usuario;
use App\Services\PrestamoService;
use App\Services\SimuladorCreditoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * El seguro del simulador sale de las primas registradas (tabla primas_seguro): la del
 * año de la simulación, la más reciente si falta, o el respaldo de la configuración.
 * Es una estimación aparte de la cuota: no cambia la cuota guardada ni la amortización.
 */
class SimuladorSegurosTest extends TestCase
{
    use RefreshDatabase;

    private const NOTA = 'Estimación; el valor real lo calcula el área de seguros';

    private int $anio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->anio = now()->year;
        SmmlvAnual::factory()->create(['anio' => $this->anio, 'valor' => 1_300_000]);
    }

    private function modalidad(string $nombre, int $plazoMaximo = 480): TipoPrestamo
    {
        $modalidad = TipoPrestamo::factory()->create(['nombre' => $nombre, 'plazo_maximo' => $plazoMaximo]);
        TasaInt::factory()->create(['id_tipo' => $modalidad->id_tipo, 'anio' => $this->anio, 'tasa_anual' => 4.85]);
        CuantiaSmmlv::factory()->create(['id_tipo' => $modalidad->id_tipo, 'tramo_desde_smmlv' => 0, 'tramo_hasta_smmlv' => null, 'cuantia_maxima_smmlv' => 220]);
        PresupuestoAnual::factory()->create(['id_tipo' => $modalidad->id_tipo, 'anio' => $this->anio, 'monto_asignado' => 10_000_000_000]);

        return $modalidad;
    }

    private function empleado(): Empleado
    {
        return Empleado::factory()->create(['salario' => 8_000_000, 'capacidad_endeudamiento' => 4_000_000, 'fecha_vinculacion' => now()->subYears(5)]);
    }

    private function simular(TipoPrestamo $modalidad, float $monto = 50_000_000, int $plazo = 72): array
    {
        return app(SimuladorCreditoService::class)->simular($this->empleado(), $modalidad, $monto, $plazo);
    }

    private function fijarPrima(ConceptoSeguro $concepto, int $anio, float $tasa): void
    {
        PrimaSeguro::updateOrCreate(['concepto' => $concepto->value, 'anio' => $anio], ['tasa' => $tasa]);
    }

    private function calcular(TipoPrestamo $modalidad, Empleado $empleado, int $plazo = 72): TestResponse
    {
        return $this->actingAs(Usuario::factory()->create())
            ->getJson(route('simulador.calcular', ['cedula' => $empleado->cedula, 'id_tipo' => $modalidad->id_tipo, 'monto' => '50.000.000', 'plazo' => $plazo]));
    }

    // ───────── Qué prima se usa ─────────

    public function test_usa_la_prima_de_vida_de_deudores_del_ano_de_la_simulacion(): void
    {
        $this->fijarPrima(ConceptoSeguro::VidaDeudores, $this->anio, 0.001);
        $educacion = $this->modalidad('Educación', 72);

        $resultado = $this->simular($educacion);

        $this->assertSame(50_000.0, $resultado['seguro']);
        $this->assertSame('anio', $resultado['seguro_detalle']['origen_vida']);
        $this->assertSame($this->anio, $resultado['seguro_detalle']['anio_vida']);
        $this->assertEqualsWithDelta($resultado['cuota_quincenal'] + 50_000, $resultado['cuota_mas_seguro'], 0.001);
    }

    public function test_usa_la_prima_del_ano_indicado_y_no_la_de_otro_ano(): void
    {
        $this->fijarPrima(ConceptoSeguro::VidaDeudores, $this->anio, 0.001);
        $this->fijarPrima(ConceptoSeguro::VidaDeudores, $this->anio - 1, 0.009);
        $educacion = $this->modalidad('Educación', 72);

        $this->assertSame(50_000.0, $this->simular($educacion)['seguro']);
    }

    public function test_si_falta_la_prima_del_ano_usa_la_vigente_mas_reciente_y_avisa(): void
    {
        PrimaSeguro::query()->delete();
        $this->fijarPrima(ConceptoSeguro::VidaDeudores, $this->anio - 3, 0.0002);
        $this->fijarPrima(ConceptoSeguro::VidaDeudores, $this->anio - 1, 0.0005);
        $this->fijarPrima(ConceptoSeguro::VidaDeudores, $this->anio + 1, 0.009);
        $educacion = $this->modalidad('Educación', 72);

        $resultado = $this->simular($educacion);

        $this->assertSame(25_000.0, $resultado['seguro']);
        $this->assertSame('reciente', $resultado['seguro_detalle']['origen_vida']);
        $this->assertSame($this->anio - 1, $resultado['seguro_detalle']['anio_vida']);

        $html = $this->calcular($educacion, $this->empleado())->assertOk()->json('html');
        $this->assertStringContainsString('No hay prima de '.$this->anio.': se usó la de '.($this->anio - 1).'.', $html);
    }

    public function test_sin_ninguna_prima_usa_el_respaldo_y_muestra_un_aviso_discreto(): void
    {
        PrimaSeguro::query()->delete();
        $educacion = $this->modalidad('Educación', 72);

        $resultado = $this->simular($educacion);

        $this->assertSame(round(50_000_000 * (float) config('prestamos.seguro.tasa_sobre_monto'), 2), $resultado['seguro']);
        $this->assertSame('respaldo', $resultado['seguro_detalle']['origen_vida']);

        $html = $this->calcular($educacion, $this->empleado())->assertOk()->json('html');
        $this->assertStringContainsString('id="aviso-seguro-respaldo"', $html);
        $this->assertStringContainsString('valor de respaldo (0,0003231)', $html);
    }

    public function test_con_primas_registradas_no_aparece_el_aviso_de_respaldo(): void
    {
        $educacion = $this->modalidad('Educación', 72);

        $html = $this->calcular($educacion, $this->empleado())->assertOk()->json('html');

        $this->assertStringNotContainsString('aviso-seguro-respaldo', $html);
        $this->assertStringNotContainsString('aviso-seguro-reciente', $html);
    }

    public function test_el_respaldo_cambia_con_la_configuracion_solo_si_no_hay_primas(): void
    {
        config(['prestamos.seguro.tasa_sobre_monto' => 0.002]);
        $educacion = $this->modalidad('Educación', 72);

        $this->assertSame(round(50_000_000 * 0.0003231, 2), $this->simular($educacion)['seguro']);

        PrimaSeguro::query()->delete();

        $this->assertSame(100_000.0, $this->simular($educacion)['seguro']);
    }

    // ───────── Incendio y terremoto ─────────

    public function test_incendio_en_cero_no_suma_y_el_desglose_lo_dice(): void
    {
        $vivienda = $this->modalidad('Vivienda — compra de inmueble');
        $empleado = $this->empleado();

        $resultado = app(SimuladorCreditoService::class)->simular($empleado, $vivienda, 50_000_000, 240);

        $this->assertSame(round(50_000_000 * 0.0003231, 2), $resultado['seguro']);
        $this->assertSame(0.0, $resultado['seguro_detalle']['incendio']);

        $html = $this->calcular($vivienda, $empleado, 240)->assertOk()->json('html');
        $this->assertStringContainsString('incendio y terremoto: sin prima definida', $html);
    }

    public function test_incendio_mayor_que_cero_se_suma_en_vivienda_con_su_desglose(): void
    {
        $this->fijarPrima(ConceptoSeguro::VidaDeudores, $this->anio, 0.001);
        $this->fijarPrima(ConceptoSeguro::IncendioTerremoto, $this->anio, 0.0004);
        $vivienda = $this->modalidad('Vivienda — construcción');
        $empleado = $this->empleado();

        $resultado = app(SimuladorCreditoService::class)->simular($empleado, $vivienda, 50_000_000, 240);

        $this->assertSame(70_000.0, $resultado['seguro']);
        $this->assertSame(50_000.0, $resultado['seguro_detalle']['vida']);
        $this->assertSame(20_000.0, $resultado['seguro_detalle']['incendio']);
        $this->assertEqualsWithDelta($resultado['cuota_quincenal'] + 70_000, $resultado['cuota_mas_seguro'], 0.001);

        $html = $this->calcular($vivienda, $empleado, 240)->assertOk()->json('html');
        $this->assertStringContainsString('Vida de deudores $50.000,00', $html);
        $this->assertStringContainsString('incendio y terremoto $20.000,00', $html);
    }

    public function test_el_incendio_no_se_suma_fuera_de_vivienda(): void
    {
        $this->fijarPrima(ConceptoSeguro::IncendioTerremoto, $this->anio, 0.0004);

        $resultado = $this->simular($this->modalidad('Educación', 72));

        $this->assertSame(round(50_000_000 * 0.0003231, 2), $resultado['seguro']);
        $this->assertSame(0.0, $resultado['seguro_detalle']['incendio']);
    }

    // ───────── Cómo se muestra ─────────

    public function test_la_pantalla_muestra_el_seguro_aparte_con_la_nota_y_la_cuota_estimada_con_seguros_en_vivienda(): void
    {
        $vivienda = $this->modalidad('Vivienda — compra de inmueble');
        $empleado = $this->empleado();

        $respuesta = $this->calcular($vivienda, $empleado, 240)->assertOk();
        $html = $respuesta->json('html');

        $this->assertStringContainsString('Seguro (aparte)', $html);
        $this->assertStringContainsString(self::NOTA, $html);
        $this->assertStringContainsString('Cuota estimada con seguros', $html);
        $this->assertStringNotContainsString('Cuota + seguro', $html);
        $this->assertStringContainsString('Cuota fija quincenal', $html);

        $hoja = $respuesta->json('html_impresion');
        $this->assertStringContainsString(self::NOTA, $hoja);
        $this->assertStringContainsString('Cuota estimada con seguros', $hoja);
    }

    public function test_fuera_de_vivienda_se_conserva_cuota_mas_seguro_con_la_nota(): void
    {
        $educacion = $this->modalidad('Educación', 72);

        $respuesta = $this->calcular($educacion, $this->empleado())->assertOk();
        $html = $respuesta->json('html');

        $this->assertStringContainsString('Cuota + seguro', $html);
        $this->assertStringNotContainsString('Cuota estimada con seguros', $html);
        $this->assertStringContainsString(self::NOTA, $html);
        $this->assertStringNotContainsString('id="desglose-seguro"', $html);
        $this->assertStringContainsString('Cuota + seguro', $respuesta->json('html_impresion'));
    }

    // ───────── La cuota guardada y la amortización no cambian ─────────

    public function test_la_prima_no_cambia_la_cuota_ni_la_amortizacion_ni_el_total_a_pagar(): void
    {
        $vivienda = $this->modalidad('Vivienda — compra de inmueble');
        $empleado = $this->empleado();
        $servicio = app(SimuladorCreditoService::class);

        $antes = $servicio->simular($empleado, $vivienda, 50_000_000, 240);

        $this->fijarPrima(ConceptoSeguro::VidaDeudores, $this->anio, 0.01);
        $this->fijarPrima(ConceptoSeguro::IncendioTerremoto, $this->anio, 0.02);
        $despues = $servicio->simular($empleado, $vivienda, 50_000_000, 240);

        $this->assertNotSame($antes['seguro'], $despues['seguro']);
        $this->assertSame($antes['cuota_quincenal'], $despues['cuota_quincenal']);
        $this->assertSame($antes['total_a_pagar'], $despues['total_a_pagar']);
        $this->assertSame($antes['total_intereses'], $despues['total_intereses']);
        $this->assertSame($antes['tabla_amortizacion'], $despues['tabla_amortizacion']);
    }

    public function test_el_prestamo_aprobado_guarda_la_cuota_sin_seguro_y_sin_filas_de_seguro(): void
    {
        $this->fijarPrima(ConceptoSeguro::VidaDeudores, $this->anio, 0.01);
        $vivienda = $this->modalidad('Vivienda — compra de inmueble');
        $empleado = $this->empleado();

        $simulacion = app(SimuladorCreditoService::class)->simular($empleado, $vivienda, 50_000_000, 240);
        $prestamo = app(PrestamoService::class)->crearDesdeSolicitud(SolicitudCredito::factory()->create([
            'cedula_empleado' => $empleado->cedula,
            'id_tipo' => $vivienda->id_tipo,
            'monto_solicitado' => 50_000_000,
            'plazo_solicitado' => 240,
            'fecha_solicitud' => now()->toDateString(),
        ]));

        $this->assertEqualsWithDelta($simulacion['cuota_quincenal'], (float) $prestamo->cuota_periodica, 0.001);
        $this->assertSame(240, $prestamo->cuotas()->count());
        $this->assertEqualsWithDelta(50_000_000, (float) $prestamo->cuotas()->sum('valor_capital'), 0.5);
        $this->assertArrayNotHasKey('seguro', $prestamo->getAttributes());
        $this->assertArrayNotHasKey('seguro', $prestamo->cuotas()->firstOrFail()->getAttributes());
    }
}
