<?php

namespace Tests\Feature;

use App\Models\CuantiaSmmlv;
use App\Models\Empleado;
use App\Models\PresupuestoAnual;
use App\Models\SmmlvAnual;
use App\Models\SolicitudCredito;
use App\Models\TasaInt;
use App\Models\TasaVehiculo;
use App\Models\TipoPrestamo;
use App\Models\Usuario;
use App\Services\CalculoFinancieroService;
use App\Services\PrestamoService;
use App\Services\ReliquidacionService;
use App\Services\SimuladorCreditoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Tope de la tasa de vivienda: IPC del año anterior sin superar el 5 %
 * (Acuerdo Directivo 11 de 2023, desde el 15 de noviembre de 2023).
 */
class TopeTasaViviendaTest extends TestCase
{
    use RefreshDatabase;

    private const VIVIENDA = [
        'Vivienda — compra de inmueble',
        'Vivienda — construcción',
        'Vivienda — cancelación total de hipoteca (deshipoteca)',
        'Vivienda — reparaciones o mejoras',
        'Vivienda — cambio de vivienda',
    ];

    private function servicio(): PrestamoService
    {
        return app(PrestamoService::class);
    }

    private function modalidad(string $nombre, float $ipc, ?int $anio = null, int $plazoMaximo = 480): TipoPrestamo
    {
        $modalidad = TipoPrestamo::factory()->create(['nombre' => $nombre, 'plazo_maximo' => $plazoMaximo]);
        TasaInt::factory()->create(['id_tipo' => $modalidad->id_tipo, 'anio' => $anio ?? now()->year, 'tasa_anual' => $ipc]);

        return $modalidad;
    }

    /**
     * Lo necesario para simular y aprobar solicitudes del año actual.
     */
    private function entornoDelAnio(TipoPrestamo $modalidad): void
    {
        if (SmmlvAnual::where('anio', now()->year)->doesntExist()) {
            SmmlvAnual::factory()->create(['anio' => now()->year, 'valor' => 1_300_000]);
        }

        if (CuantiaSmmlv::where('id_tipo', $modalidad->id_tipo)->doesntExist()) {
            CuantiaSmmlv::factory()->create(['id_tipo' => $modalidad->id_tipo, 'tramo_desde_smmlv' => 0, 'tramo_hasta_smmlv' => null, 'cuantia_maxima_smmlv' => 220]);
            PresupuestoAnual::factory()->create(['id_tipo' => $modalidad->id_tipo, 'anio' => now()->year, 'monto_asignado' => 10_000_000_000]);
        }
    }

    private function empleado(): Empleado
    {
        return Empleado::factory()->create(['salario' => 8_000_000, 'capacidad_endeudamiento' => 4_000_000, 'fecha_vinculacion' => now()->subYears(5)]);
    }

    // ───────── La tasa que se lee ─────────

    public function test_un_ipc_de_5_20_queda_en_5_y_el_dato_registrado_no_cambia(): void
    {
        $modalidad = $this->modalidad('Vivienda — compra de inmueble', 5.20);

        $detalle = $this->servicio()->tasaDetallada($modalidad, now()->year, 480, true, now());

        $this->assertSame(5.0, $detalle['tasa']);
        $this->assertSame(5.2, $detalle['registrada']);
        $this->assertTrue($detalle['limitada']);
        $this->assertSame(5.0, $detalle['tope']);
        $this->assertSame([$detalle['id_tasa'], 5.0], $this->servicio()->resolverTasaPara($modalidad, now()->year, 480, true, now()));
        $this->assertEquals(5.2, TasaInt::where('id_tipo', $modalidad->id_tipo)->value('tasa_anual'));
    }

    public function test_un_ipc_de_4_80_queda_en_4_80_y_uno_de_exactamente_5_no_se_limita(): void
    {
        $bajo = $this->modalidad('Vivienda — compra de inmueble', 4.80);
        $exacto = $this->modalidad('Vivienda — construcción', 5.0);

        $detalleBajo = $this->servicio()->tasaDetallada($bajo, now()->year, 480, true, now());
        $detalleExacto = $this->servicio()->tasaDetallada($exacto, now()->year, 480, true, now());

        $this->assertSame(4.8, $detalleBajo['tasa']);
        $this->assertFalse($detalleBajo['limitada']);
        $this->assertSame(5.0, $detalleExacto['tasa']);
        $this->assertFalse($detalleExacto['limitada']);
    }

    public function test_una_solicitud_del_14_de_noviembre_de_2023_no_tiene_tope_y_la_del_15_si(): void
    {
        $modalidad = $this->modalidad('Vivienda — compra de inmueble', 5.20, 2023);

        $antes = $this->servicio()->tasaDetallada($modalidad, 2023, 480, true, '2023-11-14');
        $desde = $this->servicio()->tasaDetallada($modalidad, 2023, 480, true, '2023-11-15');
        $despues = $this->servicio()->tasaDetallada($modalidad, 2023, 480, true, '2023-12-20');
        $muyAntes = $this->servicio()->tasaDetallada($modalidad, 2023, 480, true, '2023-03-01');

        $this->assertSame(5.2, $antes['tasa']);
        $this->assertFalse($antes['limitada']);
        $this->assertSame(5.2, $muyAntes['tasa']);
        $this->assertSame(5.0, $desde['tasa']);
        $this->assertTrue($desde['limitada']);
        $this->assertSame(5.0, $despues['tasa']);
    }

    #[DataProvider('modalidadesDeVivienda')]
    public function test_cada_modalidad_de_vivienda_tiene_el_tope(string $nombre): void
    {
        $modalidad = $this->modalidad($nombre, 6.3);

        $this->assertTrue($modalidad->esVivienda());
        $this->assertSame(5.0, $this->servicio()->tasaDetallada($modalidad, now()->year, 480, true, now())['tasa']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function modalidadesDeVivienda(): array
    {
        return array_combine(self::VIVIENDA, array_map(fn (string $nombre) => [$nombre], self::VIVIENDA));
    }

    public function test_educacion_y_calamidad_no_tienen_tope(): void
    {
        foreach (['Educación', 'Calamidad doméstica y necesidades especiales'] as $nombre) {
            $modalidad = $this->modalidad($nombre, 6.3, null, 72);
            $detalle = $this->servicio()->tasaDetallada($modalidad, now()->year, 72, true, now());

            $this->assertFalse($modalidad->esVivienda(), $nombre);
            $this->assertSame(6.3, $detalle['tasa'], $nombre);
            $this->assertFalse($detalle['limitada'], $nombre);
            $this->assertNull($this->servicio()->topeTasaVivienda($modalidad, now()), $nombre);
        }
    }

    public function test_vehiculo_con_una_tasa_mayor_al_5_no_se_limita(): void
    {
        $vehiculo = $this->modalidad('Vehículo', 6.0, null, 168);
        TasaVehiculo::factory()->create(['plazo_anios' => 4, 'es_primera_solicitud' => true, 'puntos_adicionales_ipc' => 1]);

        $detalle = $this->servicio()->tasaDetallada($vehiculo, now()->year, 96, true, now());

        $this->assertSame(7.0, $detalle['tasa']);
        $this->assertFalse($detalle['limitada']);
        $this->assertNull($detalle['tope']);
    }

    public function test_el_tope_y_la_vigencia_se_pueden_cambiar_por_configuracion(): void
    {
        $modalidad = $this->modalidad('Vivienda — compra de inmueble', 5.20);

        config(['prestamos.tasa_vivienda.tope' => 0.045]);
        $this->assertSame(4.5, $this->servicio()->tasaDetallada($modalidad, now()->year, 480, true, now())['tasa']);

        config(['prestamos.tasa_vivienda.tope' => 0.06]);
        $this->assertSame(5.2, $this->servicio()->tasaDetallada($modalidad, now()->year, 480, true, now())['tasa']);

        config(['prestamos.tasa_vivienda.tope' => 0.05, 'prestamos.tasa_vivienda.vigente_desde' => '2099-01-01']);
        $this->assertSame(5.2, $this->servicio()->tasaDetallada($modalidad, now()->year, 480, true, now())['tasa']);
    }

    public function test_la_configuracion_trae_el_tope_y_la_vigencia_del_acuerdo(): void
    {
        $this->assertSame(0.05, config('prestamos.tasa_vivienda.tope'));
        $this->assertSame('2023-11-15', config('prestamos.tasa_vivienda.vigente_desde'));
    }

    public function test_la_nota_dice_el_tope_y_el_ipc_registrado(): void
    {
        $this->assertSame('Tasa limitada al 5 % según el Acuerdo 11 de 2023; IPC registrado: 5,20 %.', $this->servicio()->notaTopeVivienda(5.0, 5.2));
        $this->assertSame('Tasa limitada al 5 % según el Acuerdo 11 de 2023; IPC registrado: 6,3512 %.', $this->servicio()->notaTopeVivienda(5.0, 6.3512));
        $this->assertSame('Tasa limitada al 4,50 % según el Acuerdo 11 de 2023; IPC registrado: 5,20 %.', $this->servicio()->notaTopeVivienda(4.5, 5.2));
    }

    // ───────── Solicitud, préstamo y reliquidación ─────────

    public function test_el_prestamo_aprobado_guarda_la_tasa_limitada_y_la_cuota_es_coherente(): void
    {
        $modalidad = $this->modalidad('Vivienda — compra de inmueble', 5.20);
        $empleado = $this->empleado();
        $solicitud = SolicitudCredito::factory()->create([
            'cedula_empleado' => $empleado->cedula,
            'id_tipo' => $modalidad->id_tipo,
            'monto_solicitado' => 100_000_000,
            'plazo_solicitado' => 480,
            'fecha_solicitud' => now()->toDateString(),
        ]);

        $prestamo = $this->servicio()->crearDesdeSolicitud($solicitud);
        $calculo = new CalculoFinancieroService;
        $con5 = $calculo->cuotaFija(100_000_000, $calculo->tasaPeriodicaQuincenal(5.0), 480);
        $con520 = $calculo->cuotaFija(100_000_000, $calculo->tasaPeriodicaQuincenal(5.2), 480);

        $this->assertEquals(5.0, $prestamo->tasa_efectiva_anual);
        $this->assertSame(TasaInt::where('id_tipo', $modalidad->id_tipo)->value('id_tasa'), $prestamo->id_tasa);
        $this->assertEqualsWithDelta($con5, (float) $prestamo->cuota_periodica, 0.001);
        $this->assertNotEqualsWithDelta($con520, (float) $prestamo->cuota_periodica, 1.0);
        $this->assertEqualsWithDelta($con5, (float) $prestamo->cuotas()->orderBy('numero_cuota')->first()->valor_total, 0.011);
        $this->assertEquals(5.2, TasaInt::where('id_tipo', $modalidad->id_tipo)->value('tasa_anual'));
    }

    public function test_la_reliquidacion_usa_la_tasa_limitada_del_prestamo(): void
    {
        $modalidad = $this->modalidad('Vivienda — compra de inmueble', 5.20);
        $solicitud = SolicitudCredito::factory()->create([
            'cedula_empleado' => $this->empleado()->cedula,
            'id_tipo' => $modalidad->id_tipo,
            'monto_solicitado' => 100_000_000,
            'plazo_solicitado' => 480,
            'fecha_solicitud' => now()->toDateString(),
        ]);
        $prestamo = $this->servicio()->crearDesdeSolicitud($solicitud);

        $plan = app(ReliquidacionService::class)->calcular($prestamo, 5_000_000, 'plazo', null, true);

        $this->assertEqualsWithDelta((new CalculoFinancieroService)->tasaPeriodicaQuincenal(5.0), $plan['tasa_periodica'], 1e-12);
    }

    public function test_la_validacion_de_la_solicitud_usa_la_tasa_limitada(): void
    {
        $modalidad = $this->modalidad('Vivienda — compra de inmueble', 5.20);
        $solicitud = SolicitudCredito::factory()->create([
            'cedula_empleado' => $this->empleado()->cedula,
            'id_tipo' => $modalidad->id_tipo,
            'monto_solicitado' => 100_000_000,
            'plazo_solicitado' => 480,
            'fecha_solicitud' => now()->toDateString(),
        ]);

        [$idTasa, $tasa] = $this->servicio()->resolverTasa($solicitud, $modalidad, now()->year);

        $this->assertNotNull($idTasa);
        $this->assertSame(5.0, $tasa);
    }

    public function test_el_detalle_de_la_solicitud_y_del_prestamo_muestran_la_nota(): void
    {
        $modalidad = $this->modalidad('Vivienda — compra de inmueble', 5.20);
        $this->entornoDelAnio($modalidad);
        $solicitud = SolicitudCredito::factory()->create([
            'cedula_empleado' => $this->empleado()->cedula,
            'id_tipo' => $modalidad->id_tipo,
            'monto_solicitado' => 100_000_000,
            'plazo_solicitado' => 480,
            'fecha_solicitud' => now()->toDateString(),
        ]);
        $usuario = Usuario::factory()->create();
        $nota = 'Tasa limitada al 5 % según el Acuerdo 11 de 2023; IPC registrado: 5,20 %.';

        $this->actingAs($usuario)->get(route('solicitudes.show', $solicitud))
            ->assertOk()
            ->assertSee('5,0000 %')
            ->assertSee($nota);

        $prestamo = $this->servicio()->crearDesdeSolicitud($solicitud);

        $this->actingAs($usuario)->get(route('prestamos.show', $prestamo))
            ->assertOk()
            ->assertSee('5,0000 %')
            ->assertSee($nota);
        $this->actingAs($usuario)->get(route('solicitudes.show', $solicitud->fresh()))
            ->assertOk()
            ->assertSee($nota);
    }

    public function test_sin_tope_no_hay_nota_en_el_detalle(): void
    {
        $modalidad = $this->modalidad('Vivienda — compra de inmueble', 4.80);
        $solicitud = SolicitudCredito::factory()->create([
            'cedula_empleado' => $this->empleado()->cedula,
            'id_tipo' => $modalidad->id_tipo,
            'fecha_solicitud' => now()->toDateString(),
        ]);
        $usuario = Usuario::factory()->create();

        $this->actingAs($usuario)->get(route('solicitudes.show', $solicitud))
            ->assertOk()
            ->assertSee('4,8000 %')
            ->assertDontSee('Tasa limitada');

        $prestamo = $this->servicio()->crearDesdeSolicitud($solicitud);

        $this->actingAs($usuario)->get(route('prestamos.show', $prestamo))->assertOk()->assertDontSee('Tasa limitada');
    }

    // ───────── Simulador ─────────

    private function simular(TipoPrestamo $modalidad, array $extra = []): TestResponse
    {
        $this->entornoDelAnio($modalidad);
        $empleado = $this->empleado();

        return $this->actingAs(Usuario::factory()->create())->getJson(route('simulador.calcular', [
            'cedula' => $empleado->cedula,
            'id_tipo' => $modalidad->id_tipo,
            'monto' => '50.000.000',
            'plazo' => 240,
            ...$extra,
        ]));
    }

    public function test_el_simulador_usa_la_tasa_limitada_y_muestra_la_nota_en_pantalla_y_en_la_hoja(): void
    {
        $modalidad = $this->modalidad('Vivienda — compra de inmueble', 5.20);
        $nota = 'Tasa limitada al 5 % según el Acuerdo 11 de 2023; IPC registrado: 5,20 %.';

        $respuesta = $this->simular($modalidad)
            ->assertOk()
            ->assertJsonPath('encabezado.tasa_efectiva_anual', 5)
            ->assertJsonPath('tasa.limitada', true)
            ->assertJsonPath('tasa.registrada', 5.2)
            ->assertJsonPath('tasa.nota', $nota)
            ->assertJsonPath('tasa.editada', false);

        $this->assertStringContainsString($nota, $respuesta->json('html'));
        $this->assertStringContainsString($nota, $respuesta->json('html_impresion'));

        $calculo = new CalculoFinancieroService;
        $this->assertEqualsWithDelta($calculo->cuotaFija(50_000_000, $calculo->tasaPeriodicaQuincenal(5.0), 240), $respuesta->json('cuota_quincenal'), 0.001);
    }

    public function test_el_simulador_misma_tasa_que_la_solicitud_y_el_prestamo(): void
    {
        $modalidad = $this->modalidad('Vivienda — compra de inmueble', 5.20);
        $this->entornoDelAnio($modalidad);
        $empleado = $this->empleado();

        $simulacion = app(SimuladorCreditoService::class)->simular($empleado, $modalidad, 50_000_000, 240);
        $prestamo = $this->servicio()->crearDesdeSolicitud(SolicitudCredito::factory()->create([
            'cedula_empleado' => $empleado->cedula,
            'id_tipo' => $modalidad->id_tipo,
            'monto_solicitado' => 50_000_000,
            'plazo_solicitado' => 240,
            'fecha_solicitud' => now()->toDateString(),
        ]));

        $this->assertEquals($simulacion['encabezado']['tasa_total'], $prestamo->tasa_efectiva_anual);
        $this->assertEqualsWithDelta($simulacion['cuota_quincenal'], (float) $prestamo->cuota_periodica, 0.001);
    }

    public function test_la_tasa_oficial_del_simulador_viene_limitada_con_su_nota(): void
    {
        $modalidad = $this->modalidad('Vivienda — compra de inmueble', 5.20);
        $empleado = $this->empleado();

        $this->actingAs(Usuario::factory()->create())
            ->getJson(route('simulador.tasa', ['cedula' => $empleado->cedula, 'id_tipo' => $modalidad->id_tipo, 'plazo' => 240]))
            ->assertOk()
            ->assertJsonPath('base', 5)
            ->assertJsonPath('total', 5)
            ->assertJsonPath('limitada', true)
            ->assertJsonPath('nota', 'Tasa limitada al 5 % según el Acuerdo 11 de 2023; IPC registrado: 5,20 %.');
    }

    public function test_en_vivienda_el_override_manual_sobre_el_tope_se_rechaza_con_un_mensaje_claro(): void
    {
        $modalidad = $this->modalidad('Vivienda — compra de inmueble', 4.80);

        $this->simular($modalidad, ['tasa_efectiva' => '5,2'])
            ->assertStatus(422)
            ->assertJsonPath('errores.0', 'En vivienda la tasa no puede superar el 5 % (Acuerdo 11 de 2023): escribiste 5,2 % entre la tasa y los puntos. Corrígela o usa la tasa oficial.');

        $this->simular($modalidad, ['tasa_efectiva' => '4,5', 'puntos' => '1'])
            ->assertStatus(422)
            ->assertJsonPath('errores.0', 'En vivienda la tasa no puede superar el 5 % (Acuerdo 11 de 2023): escribiste 5,5 % entre la tasa y los puntos. Corrígela o usa la tasa oficial.');
    }

    public function test_en_vivienda_un_override_igual_o_menor_al_tope_se_permite(): void
    {
        $modalidad = $this->modalidad('Vivienda — compra de inmueble', 4.80);

        $this->simular($modalidad, ['tasa_efectiva' => '5'])->assertOk()->assertJsonPath('tasa.editada', true)->assertJsonPath('tasa.nota', null);
        $this->simular($modalidad, ['tasa_efectiva' => '3,5'])->assertOk()->assertJsonPath('encabezado.tasa_total', 3.5);
    }

    public function test_en_las_otras_modalidades_el_override_no_tiene_tope(): void
    {
        $educacion = $this->modalidad('Educación', 4.80, null, 72);
        $respuesta = $this->simular($educacion, ['tasa_efectiva' => '8,2', 'plazo' => 60])->assertOk();
        $this->assertSame(8.2, (float) $respuesta->json('encabezado.tasa_total'));
        $respuesta->assertJsonPath('tasa.nota', null);

        $vehiculo = $this->modalidad('Vehículo', 4.80, null, 168);
        $this->simular($vehiculo, ['tasa_efectiva' => '9', 'puntos' => '3', 'plazo' => 96])->assertOk()->assertJsonPath('encabezado.tasa_total', 12);
    }

    public function test_el_override_se_rechaza_en_las_cinco_modalidades_de_vivienda(): void
    {
        foreach (self::VIVIENDA as $nombre) {
            $modalidad = $this->modalidad($nombre, 4.80);

            $this->simular($modalidad, ['tasa_efectiva' => '5,01'])->assertStatus(422);
        }
    }

    public function test_la_hoja_impresa_no_trae_la_nota_si_no_se_limito(): void
    {
        $modalidad = $this->modalidad('Vivienda — compra de inmueble', 4.80);

        $respuesta = $this->simular($modalidad)->assertOk()->assertJsonPath('tasa.limitada', false);

        $this->assertStringNotContainsString('Tasa limitada', $respuesta->json('html'));
        $this->assertStringNotContainsString('Tasa limitada', $respuesta->json('html_impresion'));
    }

    public function test_los_datos_viejos_de_tasas_int_se_dejan_intactos(): void
    {
        $modalidad = $this->modalidad('Vivienda — compra de inmueble', 5.20);

        $this->simular($modalidad)->assertOk();

        $this->assertEquals(5.2, TasaInt::where('id_tipo', $modalidad->id_tipo)->value('tasa_anual'));
        $this->assertSame(1, TasaInt::where('id_tipo', $modalidad->id_tipo)->count());
    }

    // ───────── Flujo de la pantalla del simulador con el IPC por encima del tope ─────────

    private const NOTA_6_50 = 'Tasa limitada al 5 % según el Acuerdo 11 de 2023; IPC registrado: 6,50 %.';

    public function test_la_pantalla_trae_el_espacio_de_la_nota_del_tope(): void
    {
        $this->actingAs(Usuario::factory()->create())
            ->get(route('simulador.index'))
            ->assertOk()
            ->assertSee('id="nota-tope-tasa-previa"', false);
    }

    public function test_la_ruta_de_modalidad_trae_la_tasa_limitada_y_la_nota(): void
    {
        $modalidad = $this->modalidad('Vivienda — compra de inmueble', 6.50);
        $this->entornoDelAnio($modalidad);
        $empleado = $this->empleado();

        $this->actingAs(Usuario::factory()->create())
            ->getJson(route('simulador.modalidad', [$empleado->cedula, $modalidad->id_tipo]))
            ->assertOk()
            ->assertJsonPath('puede', true)
            ->assertJsonPath('tasa_efectiva_anual', 5)
            ->assertJsonPath('tasa_registrada', 6.5)
            ->assertJsonPath('tasa_limitada', true)
            ->assertJsonPath('nota_tasa', self::NOTA_6_50);
    }

    public function test_la_ruta_de_modalidad_de_educacion_con_6_5_no_limita_ni_trae_nota(): void
    {
        $modalidad = $this->modalidad('Educación', 6.50, null, 72);
        $this->entornoDelAnio($modalidad);
        $empleado = $this->empleado();

        $this->actingAs(Usuario::factory()->create())
            ->getJson(route('simulador.modalidad', [$empleado->cedula, $modalidad->id_tipo]))
            ->assertOk()
            ->assertJsonPath('tasa_efectiva_anual', 6.5)
            ->assertJsonPath('tasa_limitada', false)
            ->assertJsonPath('nota_tasa', null);
    }

    public function test_calcular_con_el_valor_prellenado_por_la_pantalla_funciona_y_muestra_la_nota(): void
    {
        $modalidad = $this->modalidad('Vivienda — compra de inmueble', 6.50);
        $this->entornoDelAnio($modalidad);
        $empleado = $this->empleado();
        $usuario = Usuario::factory()->create();

        $oficial = $this->actingAs($usuario)
            ->getJson(route('simulador.tasa', ['cedula' => $empleado->cedula, 'id_tipo' => $modalidad->id_tipo, 'plazo' => 240]))
            ->assertOk()
            ->assertJsonPath('base', 5)
            ->assertJsonPath('nota', self::NOTA_6_50);

        // La pantalla escribe estos valores en los campos (coma decimal) y los envía tal cual al calcular.
        $respuesta = $this->actingAs($usuario)->getJson(route('simulador.calcular', [
            'cedula' => $empleado->cedula,
            'id_tipo' => $modalidad->id_tipo,
            'monto' => '50.000.000',
            'plazo' => 240,
            'tasa_efectiva' => str_replace('.', ',', (string) $oficial->json('base')),
            'puntos' => str_replace('.', ',', (string) $oficial->json('puntos')),
        ]))->assertOk()
            ->assertJsonPath('encabezado.tasa_total', 5)
            ->assertJsonPath('tasa.editada', false)
            ->assertJsonPath('tasa.limitada', true)
            ->assertJsonPath('tasa.nota', self::NOTA_6_50);

        $this->assertStringContainsString(self::NOTA_6_50, $respuesta->json('html'));
    }

    public function test_una_correccion_manual_por_encima_del_tope_se_rechaza_aunque_el_ipc_sea_6_5(): void
    {
        $modalidad = $this->modalidad('Vivienda — compra de inmueble', 6.50);

        $this->simular($modalidad, ['tasa_efectiva' => '6,5'])
            ->assertStatus(422)
            ->assertJsonPath('errores.0', 'En vivienda la tasa no puede superar el 5 % (Acuerdo 11 de 2023): escribiste 6,5 % entre la tasa y los puntos. Corrígela o usa la tasa oficial.');

        $this->simular($modalidad, ['tasa_efectiva' => '5', 'puntos' => '1,5'])->assertStatus(422);
    }

    public function test_una_correccion_manual_por_debajo_del_tope_funciona_con_el_ipc_en_6_5(): void
    {
        $modalidad = $this->modalidad('Vivienda — compra de inmueble', 6.50);

        $this->simular($modalidad, ['tasa_efectiva' => '4,2'])
            ->assertOk()
            ->assertJsonPath('tasa.editada', true)
            ->assertJsonPath('encabezado.tasa_total', 4.2);
    }

    public function test_educacion_con_6_5_no_se_limita_en_el_simulador(): void
    {
        $modalidad = $this->modalidad('Educación', 6.50, null, 72);

        $this->simular($modalidad, ['plazo' => 60])
            ->assertOk()
            ->assertJsonPath('encabezado.tasa_total', 6.5)
            ->assertJsonPath('tasa.limitada', false)
            ->assertJsonPath('tasa.nota', null);

        $this->simular($modalidad, ['plazo' => 60, 'tasa_efectiva' => '6,5'])
            ->assertOk()
            ->assertJsonPath('tasa.editada', false);
    }
}
