<?php

namespace Tests\Feature;

use App\Enums\EstadoPrestamo;
use App\Models\Auditoria;
use App\Models\CuantiaSmmlv;
use App\Models\Empleado;
use App\Models\Prestamo;
use App\Models\PresupuestoAnual;
use App\Models\SmmlvAnual;
use App\Models\SolicitudCredito;
use App\Models\TasaInt;
use App\Models\TasaVehiculo;
use App\Models\TipoPrestamo;
use App\Services\CalculoFinancieroService;
use App\Services\PrestamoService;
use App\Services\SimuladorCreditoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SimuladorCreditoServiceTest extends TestCase
{
    use RefreshDatabase;

    private const SALARIO = 3_000_000;

    private const SMMLV = 1_300_000;

    private int $anio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->anio = now()->year;
        SmmlvAnual::factory()->create(['anio' => $this->anio, 'valor' => self::SMMLV]);
    }

    /**
     * @param  list<string>  $omitir  piezas que no se crean: tasa, cuantia, presupuesto
     */
    private function modalidad(string $nombre, int $plazoMaximo, float $cuantiaMaximaSmmlv, float $tasaIpc = 4.85, array $omitir = []): TipoPrestamo
    {
        $modalidad = TipoPrestamo::factory()->create(['nombre' => $nombre, 'plazo_maximo' => $plazoMaximo]);

        if (! in_array('tasa', $omitir, true)) {
            TasaInt::factory()->create(['id_tipo' => $modalidad->id_tipo, 'anio' => $this->anio, 'tasa_anual' => $tasaIpc]);
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

    private function empleado(float $salario = self::SALARIO, ?float $capacidad = null): Empleado
    {
        return Empleado::factory()->create([
            'salario' => $salario,
            'capacidad_endeudamiento' => $capacidad ?? $salario * 0.5,
            'fecha_vinculacion' => now()->subYears(5)->toDateString(),
        ]);
    }

    private function simulador(): SimuladorCreditoService
    {
        return app(SimuladorCreditoService::class);
    }

    private function tasaPeriodica(float $tasaEfectivaAnual): float
    {
        return app(CalculoFinancieroService::class)->tasaPeriodicaQuincenal($tasaEfectivaAnual);
    }

    /**
     * Monto cuya cuota mensual cae entre la capacidad estimada (1.380.000) y la
     * guardada (1.500.000) de un empleado con salario de 3.000.000.
     */
    private function montoEntreLasDosCapacidades(float $tasaEfectivaAnual, int $plazo): float
    {
        return floor(app(CalculoFinancieroService::class)->montoMaximoParaCuota(720_000, $this->tasaPeriodica($tasaEfectivaAnual), $plazo));
    }

    public function test_la_cuota_simulada_es_la_del_prestamo_real_y_la_tabla_cierra_en_cero(): void
    {
        $vivienda = $this->modalidad('Vivienda — compra de inmueble', 480, 130);
        $empleado = $this->empleado();

        $resultado = $this->simulador()->simular($empleado, $vivienda, 100_000_000, 480);

        $this->assertSame([], $resultado['errores']);
        $this->assertCount(480, $resultado['tabla_amortizacion']);
        $this->assertEqualsWithDelta(0.0, $resultado['tabla_amortizacion'][479]['saldo'], 0.01);
        $this->assertEqualsWithDelta(100_000_000 + $resultado['total_intereses'], $resultado['total_a_pagar'], 0.01);

        $solicitud = SolicitudCredito::factory()->create([
            'cedula_empleado' => $empleado->cedula,
            'id_tipo' => $vivienda->id_tipo,
            'monto_solicitado' => 100_000_000,
            'plazo_solicitado' => 480,
            'fecha_solicitud' => now()->toDateString(),
        ]);
        $prestamo = app(PrestamoService::class)->crearDesdeSolicitud($solicitud);

        $this->assertEqualsWithDelta((float) $prestamo->cuota_periodica, $resultado['cuota_quincenal'], 0.001);

        $fechasReales = $prestamo->cuotas()->orderBy('numero_cuota')->get()->map(fn ($cuota) => $cuota->fecha_vencimiento->toDateString())->all();
        $this->assertSame($fechasReales, array_column($resultado['tabla_amortizacion'], 'fecha'));
    }

    public function test_el_encabezado_trae_la_tasa_total_la_periodica_quincenal_y_los_periodos(): void
    {
        $educacion = $this->modalidad('Educación', 72, 17.5, 5.1);

        $resultado = $this->simulador()->simular($this->empleado(), $educacion, 4_000_000, 72);

        $this->assertSame(4_000_000.0, $resultado['encabezado']['monto']);
        $this->assertSame(5.1, $resultado['encabezado']['tasa_efectiva_anual']);
        $this->assertSame(0.0, $resultado['encabezado']['puntos']);
        $this->assertSame(5.1, $resultado['encabezado']['tasa_total']);
        $this->assertSame(72, $resultado['encabezado']['periodos']);
        $this->assertEqualsWithDelta((1.051 ** (1 / 24) - 1) * 100, $resultado['encabezado']['tasa_periodica_quincenal'], 1e-9);
        $this->assertEqualsWithDelta(59_865.80, $resultado['cuota_quincenal'], 0.005);
        $this->assertFalse($resultado['tasa']['editada']);
    }

    public function test_el_seguro_es_el_monto_por_la_tasa_configurada_y_se_suma_a_la_cuota(): void
    {
        $educacion = $this->modalidad('Educación', 72, 17.5);

        $resultado = $this->simulador()->simular($this->empleado(), $educacion, 4_000_000, 72);

        $this->assertSame(1_292.4, $resultado['seguro']);
        $this->assertEqualsWithDelta($resultado['cuota_quincenal'] + 1_292.4, $resultado['cuota_mas_seguro'], 0.001);

        config(['prestamos.seguro.tasa_sobre_monto' => 0.001]);

        $this->assertSame(4_000.0, $this->simulador()->simular($this->empleado(), $educacion, 4_000_000, 72)['seguro']);
    }

    public function test_el_seguro_no_entra_en_la_comparacion_con_la_capacidad(): void
    {
        config(['prestamos.simulador.restar_descuentos_ley' => false]);
        $educacion = $this->modalidad('Educación', 72, 17.5);
        $empleado = $this->empleado();

        $resultado = $this->simulador()->simular($empleado, $educacion, 4_000_000, 72);
        config(['prestamos.seguro.tasa_sobre_monto' => 0.5]);
        $conSeguroEnorme = $this->simulador()->simular($empleado, $educacion, 4_000_000, 72);

        $this->assertSame($resultado['capacidad']['cuota_mensual'], $conSeguroEnorme['capacidad']['cuota_mensual']);
        $this->assertSame($resultado['capacidad']['margen'], $conSeguroEnorme['capacidad']['margen']);
        $this->assertTrue($conSeguroEnorme['capacidad']['cabe']);
    }

    public function test_la_tasa_oficial_se_prellena_y_la_editada_se_usa_y_se_marca(): void
    {
        $educacion = $this->modalidad('Educación', 72, 17.5, 4.85);
        $empleado = $this->empleado();

        $oficial = $this->simulador()->tasaOficial($empleado, $educacion, $this->anio, 72);
        $this->assertSame(4.85, $oficial['base']);
        $this->assertSame(0.0, $oficial['puntos']);

        $editada = $this->simulador()->simular($empleado, $educacion, 4_000_000, 72, tasaEfectivaBase: 8.2, puntos: 1.5);

        $this->assertTrue($editada['tasa']['editada']);
        $this->assertSame(9.7, $editada['encabezado']['tasa_total']);
        $this->assertSame(4.85, $editada['tasa']['oficial_total']);
        $this->assertEqualsWithDelta(
            app(CalculoFinancieroService::class)->cuotaFija(4_000_000, $this->tasaPeriodica(9.7), 72),
            $editada['cuota_quincenal'],
            0.001,
        );

        $igual = $this->simulador()->simular($empleado, $educacion, 4_000_000, 72, tasaEfectivaBase: 4.85, puntos: 0.0);
        $this->assertFalse($igual['tasa']['editada']);
    }

    public function test_vehiculo_suma_los_puntos_segun_primera_o_posterior_solicitud(): void
    {
        $vehiculo = $this->modalidad('Vehículo', 168, 50, 3.0);
        TasaVehiculo::factory()->create(['plazo_anios' => 4, 'es_primera_solicitud' => true, 'puntos_adicionales_ipc' => 1]);
        TasaVehiculo::factory()->create(['plazo_anios' => 4, 'es_primera_solicitud' => false, 'puntos_adicionales_ipc' => 3]);
        $empleado = $this->empleado();

        $primera = $this->simulador()->simular($empleado, $vehiculo, 20_000_000, 96);

        $this->assertSame(3.0, $primera['encabezado']['tasa_efectiva_anual']);
        $this->assertSame(1.0, $primera['encabezado']['puntos']);
        $this->assertSame(4.0, $primera['encabezado']['tasa_total']);
        $this->assertFalse($primera['tasa']['editada']);
        $this->assertSame([], array_filter($primera['avisos'], fn (string $aviso): bool => str_contains($aviso, 'puntos adicionales registrados')));

        Prestamo::factory()->create(['cedula_empleado' => $empleado->cedula, 'id_tipo' => $vehiculo->id_tipo, 'estado' => EstadoPrestamo::Cancelado]);
        $segunda = $this->simulador()->simular($empleado, $vehiculo, 20_000_000, 96);

        $this->assertSame(3.0, $segunda['encabezado']['puntos']);
        $this->assertSame(6.0, $segunda['encabezado']['tasa_total']);
        $this->assertGreaterThan($primera['cuota_quincenal'], $segunda['cuota_quincenal']);
    }

    public function test_vehiculo_sin_fila_de_puntos_para_el_plazo_avisa_explicitamente(): void
    {
        $vehiculo = $this->modalidad('Vehículo', 168, 50, 3.0);
        TasaVehiculo::factory()->create(['plazo_anios' => 4, 'es_primera_solicitud' => true, 'puntos_adicionales_ipc' => 1]);
        $empleado = $this->empleado();

        // 120 quincenas = 5 años, y no hay fila de 5 años.
        $oficial = $this->simulador()->tasaOficial($empleado, $vehiculo, $this->anio, 120);
        $this->assertSame(3.0, $oficial['base']);
        $this->assertSame(0.0, $oficial['puntos']);
        $this->assertCount(1, $oficial['avisos']);
        $this->assertStringContainsString('No hay puntos adicionales registrados para vehículo a 5 años', $oficial['avisos'][0]);

        $resultado = $this->simulador()->simular($empleado, $vehiculo, 20_000_000, 120);
        $this->assertSame([], $resultado['errores']);
        $this->assertContains($oficial['avisos'][0], $resultado['avisos']);
    }

    public function test_vehiculo_avisa_que_los_puntos_se_toman_por_anios_completos(): void
    {
        $vehiculo = $this->modalidad('Vehículo', 168, 50, 3.0);
        TasaVehiculo::factory()->create(['plazo_anios' => 4, 'es_primera_solicitud' => true, 'puntos_adicionales_ipc' => 1]);

        $oficial = $this->simulador()->tasaOficial($this->empleado(), $vehiculo, $this->anio, 100);

        $this->assertSame(1.0, $oficial['puntos']);
        $this->assertCount(1, $oficial['avisos']);
        $this->assertStringContainsString('100 quincenas se tratan como 4 años', $oficial['avisos'][0]);
    }

    public function test_rechaza_un_plazo_mayor_al_maximo_de_la_modalidad(): void
    {
        $educacion = $this->modalidad('Educación', 72, 17.5);

        $resultado = $this->simulador()->simular($this->empleado(), $educacion, 4_000_000, 73);

        $this->assertSame(['El plazo (73 quincenas) supera el máximo de la modalidad Educación: 72 quincenas.'], $resultado['errores']);
        $this->assertArrayNotHasKey('cuota_quincenal', $resultado);
    }

    public function test_el_limite_tecnico_configurable_tambien_topa_el_plazo(): void
    {
        $vivienda = $this->modalidad('Vivienda — compra de inmueble', 600, 130);
        $empleado = $this->empleado();

        $this->assertSame(480, $this->simulador()->plazoMaximoSimulable($vivienda));
        $this->assertSame([], $this->simulador()->simular($empleado, $vivienda, 50_000_000, 480)['errores']);
        $this->assertSame(
            ['El plazo (481 quincenas) supera el límite técnico del simulador: 480 quincenas.'],
            $this->simulador()->simular($empleado, $vivienda, 50_000_000, 481)['errores'],
        );

        config(['prestamos.simulador.plazo_tecnico_maximo' => 100]);

        $this->assertSame(100, $this->simulador()->plazoMaximoSimulable($vivienda));
        $this->assertSame(
            ['El plazo (101 quincenas) supera el límite técnico del simulador: 100 quincenas.'],
            $this->simulador()->simular($empleado, $vivienda, 50_000_000, 101)['errores'],
        );
    }

    public function test_valida_monto_minimo_smmlv_modalidad_deshabilitada_y_empleado_retirado(): void
    {
        $educacion = $this->modalidad('Educación', 72, 17.5);

        $this->assertSame(['El monto debe ser de al menos $1.000.'], $this->simulador()->simular($this->empleado(), $educacion, 999, 12)['errores']);

        $educacion->update(['habilitada' => false]);
        $this->assertSame(['La modalidad está deshabilitada.'], $this->simulador()->simular($this->empleado(), $educacion, 2_000_000, 12)['errores']);
        $educacion->update(['habilitada' => true]);

        $retirado = Empleado::factory()->retirado()->create();
        $this->assertSame(['El empleado está retirado y no puede solicitar un crédito.'], $this->simulador()->simular($retirado, $educacion, 2_000_000, 12)['errores']);

        SmmlvAnual::where('anio', $this->anio)->delete();
        $this->assertSame(
            ["No se ha registrado el valor del SMMLV para el año {$this->anio}."],
            $this->simulador()->simular($this->empleado(), $educacion, 2_000_000, 12)['errores'],
        );
    }

    public function test_sin_tasa_oficial_exige_digitar_la_tasa_y_con_ella_simula_con_aviso(): void
    {
        $educacion = $this->modalidad('Educación', 72, 17.5, omitir: ['tasa']);
        $empleado = $this->empleado();

        $sinTasa = $this->simulador()->simular($empleado, $educacion, 2_000_000, 24);
        $this->assertSame(["No hay una tasa de interés registrada para Educación en {$this->anio}. Digita la tasa para simular."], $sinTasa['errores']);

        $conTasa = $this->simulador()->simular($empleado, $educacion, 2_397_200, 24, tasaEfectivaBase: 5.1);
        $this->assertSame([], $conTasa['errores']);
        $this->assertTrue($conTasa['tasa']['editada']);
        $this->assertNull($conTasa['tasa']['oficial_total']);
        $this->assertContains('No hay tasa oficial registrada para esta modalidad y año: la solicitud real no podrá aprobarse hasta registrarla.', $conTasa['avisos']);
        $this->assertEqualsWithDelta(102_494.31, $conTasa['cuota_quincenal'], 0.005);
    }

    public function test_muestra_el_desglose_de_la_capacidad_estimada_con_descuentos_de_ley(): void
    {
        $calamidad = $this->modalidad('Calamidad doméstica y necesidades especiales', 72, 5);

        $capacidad = $this->simulador()->simular($this->empleado(), $calamidad, 2_000_000, 72, retencion: 60_000)['capacidad'];
        $estimada = $capacidad['estimada'];

        // 3.000.000 son 2,3 SMMLV: sin solidaridad.
        $this->assertSame(3_000_000.0, $estimada['salario']);
        $this->assertSame(120_000.0, $estimada['salud']['valor']);
        $this->assertSame(120_000.0, $estimada['pension']['valor']);
        $this->assertSame(0.0, $estimada['solidaridad']['valor']);
        $this->assertSame(60_000.0, $estimada['retencion']);
        $this->assertSame(2_700_000.0, $estimada['base']);
        $this->assertSame(1_350_000.0, $estimada['capacidad']);
        $this->assertSame(1_500_000.0, $capacidad['guardada']['capacidad']);
    }

    public function test_el_interruptor_decide_cual_capacidad_gobierna_el_veredicto(): void
    {
        $calamidad = $this->modalidad('Calamidad doméstica y necesidades especiales', 72, 5);
        $empleado = $this->empleado();
        $monto = $this->montoEntreLasDosCapacidades(4.85, 72);

        $encendido = $this->simulador()->simular($empleado, $calamidad, $monto, 72)['capacidad'];

        $this->assertTrue($encendido['restar_descuentos_ley']);
        $this->assertSame('estimada', $encendido['usada']);
        $this->assertSame($encendido['estimada']['disponible'], $encendido['disponible']);
        $this->assertFalse($encendido['cabe']);
        $this->assertFalse($encendido['estimada']['cabe']);
        $this->assertTrue($encendido['guardada']['cabe']);
        $this->assertTrue($encendido['veredictos_distintos']);

        config(['prestamos.simulador.restar_descuentos_ley' => false]);
        $apagado = $this->simulador()->simular($empleado, $calamidad, $monto, 72)['capacidad'];

        $this->assertFalse($apagado['restar_descuentos_ley']);
        $this->assertSame('guardada', $apagado['usada']);
        $this->assertSame($apagado['guardada']['disponible'], $apagado['disponible']);
        $this->assertTrue($apagado['cabe']);
        $this->assertTrue($apagado['veredictos_distintos']);
    }

    public function test_cuando_las_dos_capacidades_coinciden_no_hay_veredictos_distintos(): void
    {
        $calamidad = $this->modalidad('Calamidad doméstica y necesidades especiales', 72, 5);

        $resultado = $this->simulador()->simular($this->empleado(), $calamidad, 2_000_000, 72)['capacidad'];

        $this->assertTrue($resultado['cabe']);
        $this->assertFalse($resultado['veredictos_distintos']);
    }

    public function test_a_la_capacidad_elegida_se_le_resta_lo_comprometido_en_prestamos_vigentes(): void
    {
        $calamidad = $this->modalidad('Calamidad doméstica y necesidades especiales', 72, 5);
        $empleado = $this->empleado();
        Prestamo::factory()->create(['cedula_empleado' => $empleado->cedula, 'id_tipo' => $calamidad->id_tipo, 'cuota_periodica' => 100_000, 'estado' => EstadoPrestamo::Vigente]);
        Prestamo::factory()->create(['cedula_empleado' => $empleado->cedula, 'id_tipo' => $calamidad->id_tipo, 'cuota_periodica' => 900_000, 'estado' => EstadoPrestamo::Cancelado]);

        $capacidad = $this->simulador()->simular($empleado, $calamidad, 2_000_000, 72)['capacidad'];

        $this->assertSame(200_000.0, $capacidad['comprometida']);
        $this->assertSame(1_180_000.0, $capacidad['estimada']['disponible']);
        $this->assertSame(1_300_000.0, $capacidad['guardada']['disponible']);
        $this->assertSame(1_180_000.0, $capacidad['disponible']);
    }

    public function test_compara_la_cuota_por_dos_con_la_capacidad_disponible(): void
    {
        config(['prestamos.simulador.restar_descuentos_ley' => false]);
        $calamidad = $this->modalidad('Calamidad doméstica y necesidades especiales', 72, 5, 5.1);
        $empleado = $this->empleado(self::SALARIO, 100_000);

        $noCabe = $this->simulador()->simular($empleado, $calamidad, 4_000_000, 72);
        $this->assertEqualsWithDelta(59_865.80 * 2, $noCabe['capacidad']['cuota_mensual'], 0.01);
        $this->assertFalse($noCabe['capacidad']['cabe']);
        $this->assertEqualsWithDelta(100_000 - 59_865.80 * 2, $noCabe['capacidad']['margen'], 0.01);

        $cabe = $this->simulador()->simular($this->empleado(self::SALARIO, 119_731.60), $calamidad, 4_000_000, 72);
        $this->assertTrue($cabe['capacidad']['cabe'], 'Una cuota mensual igual a la capacidad disponible cabe.');
    }

    public function test_sugiere_el_plazo_minimo_para_que_quepa_el_monto_pedido(): void
    {
        config(['prestamos.simulador.restar_descuentos_ley' => false]);
        $vivienda = $this->modalidad('Vivienda — compra de inmueble', 480, 130, 4.85);
        $calculo = app(CalculoFinancieroService::class);
        $tasa = $this->tasaPeriodica(4.85);
        $empleado = $this->empleado(self::SALARIO, 1_500_000);

        $resultado = $this->simulador()->simular($empleado, $vivienda, 100_000_000, 72);
        $plazoMinimo = $resultado['sugerencias']['plazo_minimo'];

        $this->assertFalse($resultado['capacidad']['cabe']);
        $this->assertTrue($plazoMinimo['posible']);
        $this->assertGreaterThan(72, $plazoMinimo['quincenas']);
        $this->assertLessThanOrEqual(1_500_000, $calculo->cuotaFija(100_000_000, $tasa, $plazoMinimo['quincenas']) * 2);
        $this->assertGreaterThan(1_500_000, $calculo->cuotaFija(100_000_000, $tasa, $plazoMinimo['quincenas'] - 1) * 2);
        $this->assertSame($calculo->cuotaFija(100_000_000, $tasa, $plazoMinimo['quincenas']), $plazoMinimo['cuota_quincenal']);
    }

    public function test_el_plazo_minimo_respeta_el_plazo_maximo_de_la_modalidad(): void
    {
        config(['prestamos.simulador.restar_descuentos_ley' => false]);
        $vivienda = $this->modalidad('Vivienda — compra de inmueble', 100, 130);

        $plazoMinimo = $this->simulador()->simular($this->empleado(self::SALARIO, 1_500_000), $vivienda, 100_000_000, 72)['sugerencias']['plazo_minimo'];

        $this->assertFalse($plazoMinimo['posible']);
        $this->assertGreaterThan(100, $plazoMinimo['quincenas']);
        $this->assertStringContainsString('Aun con el plazo máximo (100 quincenas)', $plazoMinimo['motivo']);
    }

    public function test_si_la_cuota_no_cubre_ni_el_interes_ningun_plazo_sirve(): void
    {
        config(['prestamos.simulador.restar_descuentos_ley' => false]);
        $vivienda = $this->modalidad('Vivienda — compra de inmueble', 480, 130);

        // Cuota máxima de 10.000 por quincena frente a un interés de unos 197.000.
        $sugerencias = $this->simulador()->simular($this->empleado(self::SALARIO, 20_000), $vivienda, 100_000_000, 480)['sugerencias'];

        $this->assertFalse($sugerencias['plazo_minimo']['posible']);
        $this->assertNull($sugerencias['plazo_minimo']['quincenas']);
        $this->assertStringContainsString('no cubre ni el interés de la primera quincena', $sugerencias['plazo_minimo']['motivo']);
        $this->assertStringContainsString('ningún plazo sirve', $sugerencias['plazo_minimo']['motivo']);
    }

    public function test_sugiere_el_monto_maximo_que_cabe_con_el_plazo_elegido(): void
    {
        config(['prestamos.simulador.restar_descuentos_ley' => false]);
        $vivienda = $this->modalidad('Vivienda — compra de inmueble', 480, 130);
        $calculo = app(CalculoFinancieroService::class);
        $tasa = $this->tasaPeriodica(4.85);

        $resultado = $this->simulador()->simular($this->empleado(self::SALARIO, 1_500_000), $vivienda, 100_000_000, 72);
        $montoMaximo = $resultado['sugerencias']['monto_maximo'];

        $this->assertTrue($montoMaximo['posible']);
        $this->assertSame(72, $montoMaximo['plazo_quincenas']);
        $this->assertLessThanOrEqual(1_500_000, $calculo->cuotaFija($montoMaximo['monto'], $tasa, 72) * 2);
        $this->assertGreaterThan(1_500_000, $calculo->cuotaFija($montoMaximo['monto'] + 500, $tasa, 72) * 2);
        $this->assertSame(floor(130 * self::SMMLV), $montoMaximo['cuantia_maxima']);
    }

    public function test_sin_capacidad_disponible_no_hay_sugerencias(): void
    {
        config(['prestamos.simulador.restar_descuentos_ley' => false]);
        $vivienda = $this->modalidad('Vivienda — compra de inmueble', 480, 130);
        $empleado = $this->empleado(self::SALARIO, 100_000);
        Prestamo::factory()->create(['cedula_empleado' => $empleado->cedula, 'id_tipo' => $vivienda->id_tipo, 'cuota_periodica' => 100_000, 'estado' => EstadoPrestamo::Vigente]);

        $sugerencias = $this->simulador()->simular($empleado, $vivienda, 10_000_000, 72)['sugerencias'];

        $this->assertFalse($sugerencias['plazo_minimo']['posible']);
        $this->assertFalse($sugerencias['monto_maximo']['posible']);
        $this->assertSame(0.0, $sugerencias['cuota_maxima_quincenal']);
    }

    public function test_avisa_sin_bloquear_cuantia_antiguedad_y_presupuesto(): void
    {
        $vivienda = $this->modalidad('Vivienda — compra de inmueble', 480, 5, omitir: ['presupuesto']);
        $empleado = $this->empleado();
        $empleado->update(['fecha_vinculacion' => now()->subMonths(6)->toDateString()]);

        // 5 SMMLV de cuantía = 6.500.000; se pide más, sin presupuesto y con 6 meses de servicio.
        $resultado = $this->simulador()->simular($empleado, $vivienda, 10_000_000, 480);

        $this->assertSame([], $resultado['errores']);
        $textos = implode(' | ', $resultado['avisos']);
        $this->assertStringContainsString('un año de servicio', $textos);
        $this->assertStringContainsString('cuantía máxima', $textos);
        $this->assertStringContainsString('disponibilidad presupuestal', $textos);
        $this->assertStringNotContainsString('capacidad', $textos);
        $this->assertCount(3, $resultado['avisos']);
    }

    public function test_no_hay_avisos_cuando_todo_se_cumple(): void
    {
        $educacion = $this->modalidad('Educación', 72, 17.5);

        $this->assertSame([], $this->simulador()->simular($this->empleado(), $educacion, 4_000_000, 72)['avisos']);
    }

    public function test_simular_no_crea_solicitudes_prestamos_ni_auditoria(): void
    {
        $educacion = $this->modalidad('Educación', 72, 17.5);
        $empleado = $this->empleado();

        $this->simulador()->simular($empleado, $educacion, 4_000_000, 72);
        $this->simulador()->simular($empleado, $educacion, 4_000_000, 500);

        $this->assertSame(0, SolicitudCredito::count());
        $this->assertSame(0, Prestamo::count());
        $this->assertSame(0, Auditoria::count());
    }
}
