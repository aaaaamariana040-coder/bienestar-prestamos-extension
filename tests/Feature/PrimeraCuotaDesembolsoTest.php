<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\CuantiaSmmlv;
use App\Models\Cuota;
use App\Models\Empleado;
use App\Models\Prestamo;
use App\Models\PresupuestoAnual;
use App\Models\SmmlvAnual;
use App\Models\SolicitudCredito;
use App\Models\TasaInt;
use App\Models\TipoPrestamo;
use App\Models\Usuario;
use App\Services\CalendarioQuincenalService;
use App\Services\CapacidadEndeudamientoService;
use App\Services\DesembolsoService;
use App\Services\QuincenaService;
use App\Services\ReporteService;
use App\Services\SolicitudCreditoService;
use Database\Seeders\SistemaUsuarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * La cuota empieza a descontarse desde el desembolso: la primera vence en el primer
 * 15 o último día de mes estrictamente posterior a la fecha del desembolso y las demás
 * siguen el calendario quincenal. Antes del desembolso las cuotas no se cobran.
 */
class PrimeraCuotaDesembolsoTest extends TestCase
{
    use RefreshDatabase;

    private const PLAZO = 24;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-03-01 10:00:00');
        $this->seed(SistemaUsuarioSeeder::class);
        config(['quincena.regla_vencimiento' => 'calendario', 'quincena.modo_marcado' => 'confirmar']);
    }

    private function modalidad(string $nombre = 'Educación'): TipoPrestamo
    {
        $modalidad = TipoPrestamo::factory()->create(['nombre' => $nombre, 'plazo_maximo' => 480]);

        SmmlvAnual::firstOrCreate(['anio' => 2026], ['valor' => 1_300_000]);
        CuantiaSmmlv::factory()->create(['id_tipo' => $modalidad->id_tipo, 'tramo_desde_smmlv' => 0, 'tramo_hasta_smmlv' => null, 'cuantia_maxima_smmlv' => 100]);
        TasaInt::factory()->create(['id_tipo' => $modalidad->id_tipo, 'anio' => 2026, 'tasa_anual' => 4.85]);
        PresupuestoAnual::factory()->create(['id_tipo' => $modalidad->id_tipo, 'anio' => 2026, 'monto_asignado' => 500_000_000]);

        return $modalidad;
    }

    private function aprobarPrestamo(?TipoPrestamo $modalidad = null): Prestamo
    {
        $modalidad ??= $this->modalidad();
        $empleado = Empleado::factory()->create(['salario' => 3_000_000, 'fecha_vinculacion' => '2020-01-15']);
        $solicitud = SolicitudCredito::factory()->create([
            'cedula_empleado' => $empleado->cedula,
            'id_tipo' => $modalidad->id_tipo,
            'monto_solicitado' => 2_000_000,
            'plazo_solicitado' => self::PLAZO,
            'fecha_solicitud' => '2026-03-01',
        ]);

        return app(SolicitudCreditoService::class)->aprobar($solicitud, Usuario::factory()->administrador()->create());
    }

    private function desembolsar(Prestamo $prestamo, string $fecha, int $tramo = 1, bool $inspeccion = false): void
    {
        app(DesembolsoService::class)->registrar($prestamo, ['numero_tramo' => $tramo, 'fecha' => $fecha, 'inspeccion_aprobada' => $inspeccion], Usuario::factory()->create());
    }

    // ───────── Fecha de la primera cuota ─────────

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function desembolsosYPrimeraCuota(): array
    {
        return [
            'el 3 → el 15 del mismo mes' => ['2026-03-03', '2026-03-15'],
            'el 20 → último día del mes' => ['2026-03-20', '2026-03-31'],
            'el 15 exacto → último día' => ['2026-03-15', '2026-03-31'],
            'el último día exacto → 15 del mes siguiente' => ['2026-03-31', '2026-04-15'],
            'febrero: el 10 → 15' => ['2026-02-10', '2026-02-15'],
            'febrero: el 15 → 28' => ['2026-02-15', '2026-02-28'],
            'febrero: el 28 → 15 de marzo' => ['2026-02-28', '2026-03-15'],
            'febrero bisiesto: el 15 → 29' => ['2028-02-15', '2028-02-29'],
            'febrero bisiesto: el 29 → 15 de marzo' => ['2028-02-29', '2028-03-15'],
            'mes de 30 días: el 15 → 30' => ['2026-04-15', '2026-04-30'],
            'mes de 30 días: el 30 → 15 de mayo' => ['2026-04-30', '2026-05-15'],
            'mes de 31 días: el 16 → 31' => ['2026-05-16', '2026-05-31'],
            'fin de año: el 15 de diciembre → 31' => ['2026-12-15', '2026-12-31'],
            'fin de año: el 20 de diciembre → 31' => ['2026-12-20', '2026-12-31'],
            'fin de año: el 31 de diciembre → 15 de enero' => ['2026-12-31', '2027-01-15'],
        ];
    }

    #[DataProvider('desembolsosYPrimeraCuota')]
    public function test_la_primera_cuota_es_el_primer_vencimiento_posterior_al_desembolso(string $desembolso, string $esperada): void
    {
        $prestamo = $this->aprobarPrestamo();
        $this->desembolsar($prestamo, $desembolso);

        $prestamo->refresh();

        $this->assertSame($esperada, $prestamo->inicio_primera_cuota->toDateString());
        $this->assertSame($esperada, $prestamo->cuotas()->first()->fecha_vencimiento->toDateString());
        $this->assertFalse($prestamo->pendiente_desembolso);
    }

    public function test_las_demas_cuotas_siguen_el_calendario_quincenal_sin_saltos(): void
    {
        $prestamo = $this->aprobarPrestamo();
        $this->desembolsar($prestamo, '2026-03-03');

        $fechas = $prestamo->cuotas()->get()->map(fn (Cuota $cuota) => $cuota->fecha_vencimiento->toDateString())->all();

        $this->assertCount(self::PLAZO, $fechas);
        $this->assertSame(['2026-03-15', '2026-03-31', '2026-04-15', '2026-04-30', '2026-05-15', '2026-05-31'], array_slice($fechas, 0, 6));
        $this->assertSame(app(CalendarioQuincenalService::class)->vencimientosDesde('2026-03-15', self::PLAZO), $fechas);
        $this->assertSame('2027-02-28', $fechas[23], 'febrero de 2027 cierra el 28');
    }

    public function test_el_desembolso_solo_cambia_las_fechas_no_los_valores_de_las_cuotas(): void
    {
        $prestamo = $this->aprobarPrestamo();
        $antes = $prestamo->cuotas()->get()->map(fn (Cuota $c) => [$c->numero_cuota, $c->valor_capital, $c->valor_interes, $c->valor_total])->all();

        $this->desembolsar($prestamo, '2026-03-03');

        $this->assertSame($antes, $prestamo->cuotas()->get()->map(fn (Cuota $c) => [$c->numero_cuota, $c->valor_capital, $c->valor_interes, $c->valor_total])->all());
    }

    public function test_el_calendario_da_el_primer_vencimiento_estrictamente_posterior(): void
    {
        $calendario = app(CalendarioQuincenalService::class);

        $this->assertSame('2026-03-15', $calendario->primerVencimientoPosterior('2026-03-14'));
        $this->assertSame('2026-03-31', $calendario->primerVencimientoPosterior('2026-03-15'));
        $this->assertSame('2026-04-15', $calendario->primerVencimientoPosterior('2026-03-31'));
        $this->assertSame('2027-01-15', $calendario->primerVencimientoPosterior('2026-12-31'));
    }

    // ───────── Antes del desembolso ─────────

    public function test_un_prestamo_aprobado_queda_pendiente_de_desembolso_con_sus_cuotas_provisionales(): void
    {
        $prestamo = $this->aprobarPrestamo();

        $this->assertTrue($prestamo->pendiente_desembolso);
        $this->assertNull($prestamo->inicio_primera_cuota);
        $this->assertSame(self::PLAZO, $prestamo->cuotas()->count());
    }

    public function test_las_cuotas_de_un_prestamo_sin_desembolsar_no_entran_en_el_informe_de_quincena(): void
    {
        $prestamo = $this->aprobarPrestamo();
        $provisional = $prestamo->cuotas()->first()->fecha_vencimiento;
        $periodo = app(CalendarioQuincenalService::class)->quincenaDeFecha($provisional);
        $this->travelTo($periodo['corte']->addDay()->setTime(10, 0));

        $resultado = app(QuincenaService::class)->procesar($periodo['anio'], $periodo['numero']);
        $this->assertSame([], $resultado['cuotas']);
        $this->assertSame([], $resultado['excluidos']);
        $this->assertSame(0.0, $resultado['total']);
        $this->assertSame([], app(QuincenaService::class)->quincenasPendientes());

        $this->desembolsar($prestamo, $periodo['inicio']->subDays(2)->toDateString());
        $despues = app(QuincenaService::class)->procesar($periodo['anio'], $periodo['numero']);

        $this->assertNotSame([], $despues['cuotas'], 'Ya desembolsado, su cuota sí entra en la quincena que le toca.');
    }

    public function test_las_cuotas_sin_desembolsar_no_cuentan_como_cartera_vencida(): void
    {
        $prestamo = $this->aprobarPrestamo();
        $this->travelTo('2027-06-01 10:00:00');

        $this->assertCount(0, app(ReporteService::class)->carteraVencida());

        $this->desembolsar($prestamo, '2026-03-03');

        $this->assertGreaterThan(0, app(ReporteService::class)->carteraVencida()->count());
    }

    public function test_la_capacidad_sigue_contando_el_prestamo_aprobado_aunque_no_se_haya_desembolsado(): void
    {
        $prestamo = $this->aprobarPrestamo();

        $capacidad = app(CapacidadEndeudamientoService::class)->paraEmpleado($prestamo->empleado, 2026);

        $this->assertSame(round((float) $prestamo->cuota_periodica * 2, 2), $capacidad['comprometida']);
    }

    // ───────── Préstamos anteriores y otros casos ─────────

    public function test_un_prestamo_anterior_no_cambia_al_registrar_su_desembolso(): void
    {
        $prestamo = Prestamo::factory()->create(['monto_aprobado' => 2_000_000]);
        Cuota::factory()->create(['id_prestamo' => $prestamo->id_prestamo, 'numero_cuota' => 1, 'fecha_vencimiento' => '2026-01-15']);
        Cuota::factory()->create(['id_prestamo' => $prestamo->id_prestamo, 'numero_cuota' => 2, 'fecha_vencimiento' => '2026-01-31']);

        $this->desembolsar($prestamo, '2026-03-03');

        $prestamo->refresh();
        $this->assertNull($prestamo->inicio_primera_cuota);
        $this->assertSame(['2026-01-15', '2026-01-31'], $prestamo->cuotas()->get()->map(fn (Cuota $c) => $c->fecha_vencimiento->toDateString())->all());
        $this->assertStringNotContainsString('primera cuota', Auditoria::where('funcion_realizada', 'Registro de desembolso')->sole()->detalle);
    }

    public function test_con_inicio_desde_aprobacion_se_conserva_la_regla_anterior(): void
    {
        config(['quincena.inicio_primera_cuota' => 'desde_aprobacion']);

        $prestamo = $this->aprobarPrestamo();
        $fechas = $prestamo->cuotas()->get()->map(fn (Cuota $c) => $c->fecha_vencimiento->toDateString())->all();
        $this->desembolsar($prestamo, '2026-05-03');

        $this->assertFalse($prestamo->pendiente_desembolso);
        $this->assertSame($fechas, $prestamo->cuotas()->get()->map(fn (Cuota $c) => $c->fecha_vencimiento->toDateString())->all());
        $this->assertNull($prestamo->fresh()->inicio_primera_cuota);
    }

    public function test_el_segundo_tramo_de_construccion_no_mueve_la_primera_cuota(): void
    {
        $prestamo = $this->aprobarPrestamo($this->modalidad('Vivienda — construcción'));

        $this->desembolsar($prestamo, '2026-03-03');
        $this->desembolsar($prestamo, '2026-06-20', 2, true);

        $this->assertSame('2026-03-15', $prestamo->fresh()->inicio_primera_cuota->toDateString());
        $this->assertSame('2026-03-15', $prestamo->cuotas()->first()->fecha_vencimiento->toDateString());

        $auditorias = Auditoria::where('funcion_realizada', 'Registro de desembolso')->orderBy('id_auditoria')->get();
        $this->assertStringContainsString('primera cuota: 2026-03-15', $auditorias[0]->detalle);
        $this->assertStringNotContainsString('primera cuota', $auditorias[1]->detalle);
    }

    // ───────── Auditoría y pantallas ─────────

    public function test_la_auditoria_del_desembolso_trae_la_fecha_de_la_primera_cuota(): void
    {
        $prestamo = $this->aprobarPrestamo();
        $this->desembolsar($prestamo, '2026-03-20');

        $detalle = Auditoria::where('funcion_realizada', 'Registro de desembolso')->sole()->detalle;

        $this->assertStringContainsString("Préstamo #{$prestamo->id_prestamo} — tramo 1", $detalle);
        $this->assertStringContainsString('primera cuota: 2026-03-31', $detalle);
    }

    public function test_el_desembolso_por_pantalla_fija_la_primera_cuota_y_el_prestamo_la_muestra(): void
    {
        $prestamo = $this->aprobarPrestamo();
        $funcionario = Usuario::factory()->create();

        $this->actingAs($funcionario)->get(route('prestamos.show', $prestamo))
            ->assertOk()
            ->assertSee('Las cuotas empiezan a descontarse a partir del desembolso.')
            ->assertDontSee('Primera cuota: 20');

        $this->actingAs($funcionario)->post(route('desembolsos.store', $prestamo), ['numero_tramo' => 1, 'fecha' => '2026-03-03'])
            ->assertRedirect(route('prestamos.show', $prestamo));

        $this->actingAs($funcionario)->get(route('prestamos.show', $prestamo))
            ->assertOk()
            ->assertSee('Primera cuota: 2026-03-15')
            ->assertDontSee('Las cuotas empiezan a descontarse a partir del desembolso.');
    }

    public function test_un_prestamo_anterior_muestra_la_fecha_de_su_primera_cuota(): void
    {
        $prestamo = Prestamo::factory()->create();
        Cuota::factory()->create(['id_prestamo' => $prestamo->id_prestamo, 'numero_cuota' => 1, 'fecha_vencimiento' => '2026-01-15']);

        $this->actingAs(Usuario::factory()->create())->get(route('prestamos.show', $prestamo))
            ->assertOk()
            ->assertSee('Primera cuota: 2026-01-15');
    }

    public function test_la_simulacion_conserva_la_estimacion_con_la_nota_de_la_fecha_real(): void
    {
        $modalidad = $this->modalidad();
        $empleado = Empleado::factory()->create(['salario' => 8_000_000, 'fecha_vinculacion' => '2020-01-15']);

        $respuesta = $this->actingAs(Usuario::factory()->create())
            ->getJson(route('simulador.calcular', ['cedula' => $empleado->cedula, 'id_tipo' => $modalidad->id_tipo, 'monto' => '2.000.000', 'plazo' => 24]))
            ->assertOk();

        $this->assertStringContainsString('La fecha real de la primera cuota se define al desembolsar', $respuesta->json('html'));
        $this->assertStringContainsString('La fecha real de la primera cuota se define al desembolsar', $respuesta->json('html_impresion'));
        $this->assertStringContainsString('fechas estimadas', $respuesta->json('html'));
    }
}
