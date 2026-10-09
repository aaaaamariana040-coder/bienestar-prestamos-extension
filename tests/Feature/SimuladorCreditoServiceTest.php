<?php

namespace Tests\Feature;

use App\Models\CuantiaSmmlv;
use App\Models\Empleado;
use App\Models\SmmlvAnual;
use App\Models\SolicitudCredito;
use App\Models\TasaInt;
use App\Models\TasaVehiculo;
use App\Models\TipoPrestamo;
use App\Services\PrestamoService;
use App\Services\SimuladorCreditoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SimuladorCreditoServiceTest extends TestCase
{
    use RefreshDatabase;

    private const SALARIO = 3_000_000;

    private function crearModalidad(string $nombre, int $plazoMaximo, float $cuantiaMaximaSmmlv, float $tasaIpc): TipoPrestamo
    {
        $modalidad = TipoPrestamo::factory()->create(['nombre' => $nombre, 'plazo_maximo' => $plazoMaximo]);

        CuantiaSmmlv::factory()->create([
            'id_tipo' => $modalidad->id_tipo,
            'tramo_desde_smmlv' => 0,
            'tramo_hasta_smmlv' => null,
            'cuantia_maxima_smmlv' => $cuantiaMaximaSmmlv,
        ]);
        TasaInt::factory()->create(['id_tipo' => $modalidad->id_tipo, 'anio' => 2026, 'tasa_anual' => $tasaIpc]);

        return $modalidad;
    }

    protected function setUp(): void
    {
        parent::setUp();

        SmmlvAnual::factory()->create(['anio' => 2026, 'valor' => 1_300_000]);
    }

    public function test_simula_un_credito_de_vivienda_y_la_cuota_coincide_con_el_prestamo_real(): void
    {
        $vivienda = $this->crearModalidad('Vivienda — compra de inmueble', 480, 130, 4.85);

        $resultado = app(SimuladorCreditoService::class)->simular($vivienda, 100_000_000, 480, self::SALARIO, 2026);

        $this->assertSame([], $resultado['errores']);
        $this->assertSame(4.85, $resultado['tasa_efectiva_anual']);
        $this->assertCount(480, $resultado['tabla_amortizacion']);
        $this->assertEqualsWithDelta(0.0, $resultado['tabla_amortizacion'][479]['saldo'], 0.01);
        $this->assertEqualsWithDelta(100_000_000 + $resultado['total_intereses'], $resultado['total_a_pagar'], 0.01);
        $this->assertEqualsWithDelta(1_500_000 - $resultado['cuota_quincenal'] * 2, $resultado['margen_capacidad'], 0.01);

        $empleado = Empleado::factory()->create();
        $solicitud = SolicitudCredito::factory()->create([
            'cedula_empleado' => $empleado->cedula,
            'id_tipo' => $vivienda->id_tipo,
            'monto_solicitado' => 100_000_000,
            'plazo_solicitado' => 480,
            'fecha_solicitud' => '2026-03-10',
        ]);
        $prestamo = app(PrestamoService::class)->crearDesdeSolicitud($solicitud);

        $this->assertEqualsWithDelta((float) $prestamo->cuota_periodica, $resultado['cuota_quincenal'], 0.001);
    }

    public function test_simula_un_credito_de_calamidad_con_su_plazo_maximo_de_72_quincenas(): void
    {
        $calamidad = $this->crearModalidad('Calamidad doméstica y necesidades especiales', 72, 5, 4.85);

        $resultado = app(SimuladorCreditoService::class)->simular($calamidad, 5_000_000, 72, self::SALARIO, 2026);

        $this->assertSame([], $resultado['errores']);
        $this->assertEqualsWithDelta(
            app(PrestamoService::class)->calcularCuotaFrancesa(5_000_000, app(PrestamoService::class)->tasaPeriodica(4.85), 72),
            $resultado['cuota_quincenal'],
            0.001,
        );
        $this->assertEqualsWithDelta(5_000_000, array_sum(array_column($resultado['tabla_amortizacion'], 'capital')), 0.01);
    }

    public function test_simula_un_vehiculo_sumando_los_puntos_adicionales_segun_primera_o_segunda_solicitud(): void
    {
        $vehiculo = $this->crearModalidad('Vehículo', 168, 50, 3.0);
        TasaVehiculo::factory()->create(['plazo_anios' => 4, 'es_primera_solicitud' => true, 'puntos_adicionales_ipc' => 1]);
        TasaVehiculo::factory()->create(['plazo_anios' => 4, 'es_primera_solicitud' => false, 'puntos_adicionales_ipc' => 3]);
        $simulador = app(SimuladorCreditoService::class);

        $primera = $simulador->simular($vehiculo, 20_000_000, 96, self::SALARIO, 2026, true);
        $segunda = $simulador->simular($vehiculo, 20_000_000, 96, self::SALARIO, 2026, false);

        $this->assertSame(4.0, $primera['tasa_efectiva_anual']);
        $this->assertSame(6.0, $segunda['tasa_efectiva_anual']);
        $this->assertGreaterThan($primera['cuota_quincenal'], $segunda['cuota_quincenal']);

        $empleado = Empleado::factory()->create();
        $solicitud = SolicitudCredito::factory()->create([
            'cedula_empleado' => $empleado->cedula,
            'id_tipo' => $vehiculo->id_tipo,
            'monto_solicitado' => 20_000_000,
            'plazo_solicitado' => 96,
            'fecha_solicitud' => '2026-03-10',
        ]);
        $prestamo = app(PrestamoService::class)->crearDesdeSolicitud($solicitud);

        $this->assertEqualsWithDelta((float) $prestamo->cuota_periodica, $primera['cuota_quincenal'], 0.001);
        $this->assertSame('4.0000', $prestamo->tasa_efectiva_anual);
    }

    public function test_rechaza_un_plazo_mayor_al_maximo_de_la_modalidad(): void
    {
        $vivienda = $this->crearModalidad('Vivienda — compra de inmueble', 480, 130, 4.85);

        $resultado = app(SimuladorCreditoService::class)->simular($vivienda, 100_000_000, 481, self::SALARIO, 2026);

        $this->assertSame(
            ['El plazo (481 quincenas) supera el máximo de la modalidad Vivienda — compra de inmueble: 480 quincenas.'],
            $resultado['errores'],
        );
        $this->assertNull($resultado['cuota_quincenal']);
        $this->assertSame([], $resultado['tabla_amortizacion']);
    }

    public function test_rechaza_un_monto_mayor_a_la_cuantia_maxima(): void
    {
        $calamidad = $this->crearModalidad('Calamidad doméstica y necesidades especiales', 72, 5, 4.85);

        $resultado = app(SimuladorCreditoService::class)->simular($calamidad, 7_000_000, 24, self::SALARIO, 2026);

        $this->assertSame(
            ['El monto solicitado (7.000.000) supera la cuantía máxima permitida para el salario indicado (6.500.000).'],
            $resultado['errores'],
        );
    }

    public function test_avisa_si_no_hay_tasa_registrada_para_el_anio(): void
    {
        $educacion = $this->crearModalidad('Educación', 72, 17.5, 4.85);

        $resultado = app(SimuladorCreditoService::class)->simular($educacion, 2_000_000, 24, self::SALARIO, 2027);

        $this->assertContains('No se ha registrado el valor del SMMLV para el año 2027.', $resultado['errores']);
        $this->assertContains('No hay una tasa de interés registrada para Educación en 2027.', $resultado['errores']);
    }

    public function test_el_margen_es_negativo_cuando_la_cuota_supera_la_capacidad_de_endeudamiento(): void
    {
        $vivienda = $this->crearModalidad('Vivienda — compra de inmueble', 480, 130, 4.85);

        $resultado = app(SimuladorCreditoService::class)->simular($vivienda, 100_000_000, 24, self::SALARIO, 2026);

        $this->assertSame([], $resultado['errores']);
        $this->assertLessThan(0, $resultado['margen_capacidad']);
    }
}
