<?php

namespace Tests\Feature;

use App\Enums\EstadoCuota;
use App\Models\CuantiaSmmlv;
use App\Models\Empleado;
use App\Models\Prestamo;
use App\Models\PresupuestoAnual;
use App\Models\SmmlvAnual;
use App\Models\SolicitudCredito;
use App\Models\TasaInt;
use App\Models\TipoPrestamo;
use App\Models\Usuario;
use App\Services\CalendarioQuincenalService;
use App\Services\DesembolsoService;
use App\Services\PagoService;
use App\Services\PrestamoService;
use App\Services\QuincenaService;
use App\Services\SimuladorCreditoService;
use App\Services\SolicitudCreditoService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Vencimientos por calendario (día 15 y último día real de cada mes) y cierre
 * provisional de novedades de nómina.
 */
class VencimientosCalendarioTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function calendario(): CalendarioQuincenalService
    {
        return app(CalendarioQuincenalService::class);
    }

    // ───────── Secuencia de fechas ─────────

    /**
     * @return array<string, array{0: string, 1: int, 2: list<string>}>
     */
    public static function secuencias(): array
    {
        return [
            'febrero normal' => ['2027-02-01', 3, ['2027-02-15', '2027-02-28', '2027-03-15']],
            'febrero bisiesto' => ['2028-02-01', 3, ['2028-02-15', '2028-02-29', '2028-03-15']],
            'meses de 30 y 31 días' => ['2026-04-01', 4, ['2026-04-15', '2026-04-30', '2026-05-15', '2026-05-31']],
            'fecha base el día 14' => ['2026-06-14', 3, ['2026-06-15', '2026-06-30', '2026-07-15']],
            'fecha base el día 15' => ['2026-06-15', 3, ['2026-06-15', '2026-06-30', '2026-07-15']],
            'fecha base el día 16' => ['2026-06-16', 3, ['2026-06-30', '2026-07-15', '2026-07-31']],
            'fecha base el último día de un mes de 30' => ['2026-06-30', 3, ['2026-06-30', '2026-07-15', '2026-07-31']],
            'fecha base el último día de un mes de 31' => ['2026-05-31', 3, ['2026-05-31', '2026-06-15', '2026-06-30']],
            'fecha base el 29 de febrero bisiesto' => ['2028-02-29', 2, ['2028-02-29', '2028-03-15']],
            'fecha base el 28 de febrero normal' => ['2027-02-28', 2, ['2027-02-28', '2027-03-15']],
            'cambio de año' => ['2026-12-16', 4, ['2026-12-31', '2027-01-15', '2027-01-31', '2027-02-15']],
            'cambio de año desde el 15 de diciembre' => ['2026-12-15', 3, ['2026-12-15', '2026-12-31', '2027-01-15']],
            'una sola cuota' => ['2026-10-23', 1, ['2026-10-31']],
        ];
    }

    /**
     * @param  list<string>  $esperadas
     */
    #[DataProvider('secuencias')]
    public function test_las_fechas_de_vencimiento_son_el_15_y_el_ultimo_dia_del_mes(string $base, int $cantidad, array $esperadas): void
    {
        $this->assertSame($esperadas, $this->calendario()->vencimientosDesde($base, $cantidad));
        $this->assertSame($esperadas, app(PrestamoService::class)->fechasDeVencimiento($base, $cantidad));
    }

    public function test_una_secuencia_larga_no_se_corre_con_el_fin_de_mes(): void
    {
        $fechas = $this->calendario()->vencimientosDesde('2026-01-31', 600);
        $anterior = null;

        $this->assertCount(600, $fechas);
        $this->assertSame('2026-01-31', $fechas[0]);

        foreach ($fechas as $i => $fecha) {
            $dia = CarbonImmutable::parse($fecha);

            $this->assertTrue($dia->day === 15 || $dia->day === $dia->daysInMonth, "{$fecha} no es 15 ni fin de mes");
            $this->assertSame($i % 2 === 0 ? $dia->daysInMonth : 15, $dia->day, "Alterna fin de mes y 15: {$fecha}");

            if ($anterior !== null) {
                $this->assertGreaterThan($anterior, $fecha);
                $this->assertContains((int) CarbonImmutable::parse($anterior)->diffInDays($dia), [13, 14, 15, 16], "{$anterior} a {$fecha}");
            }

            $anterior = $fecha;
        }
    }

    public function test_la_fecha_base_de_la_primera_cuota_sigue_siendo_la_autorizacion_mas_15_dias(): void
    {
        $servicio = app(PrestamoService::class);

        $this->assertSame('2026-11-07', $servicio->fechaPrimeraCuota('2026-10-23'));
        $this->assertSame('2026-11-15', $servicio->fechasDeVencimiento($servicio->fechaPrimeraCuota('2026-10-23'), 1)[0]);
        $this->assertSame('2026-11-15', $servicio->fechasDeVencimiento($servicio->fechaPrimeraCuota('2026-10-31'), 1)[0]);
        $this->assertSame('2026-11-30', $servicio->fechasDeVencimiento($servicio->fechaPrimeraCuota('2026-11-01'), 1)[0]);
    }

    // ───────── Cuotas reales, simulador y reliquidación ─────────

    private function prestamoConCuotas(string $fechaAutorizacion, int $numCuotas = 40): Prestamo
    {
        $servicio = app(PrestamoService::class);
        $tasa = $servicio->tasaPeriodica(5.0);
        $cuota = $servicio->calcularCuotaFrancesa(20_000_000, $tasa, $numCuotas);

        $prestamo = Prestamo::factory()->create([
            'monto_aprobado' => 20_000_000,
            'tasa_efectiva_anual' => 5.0,
            'num_cuotas' => $numCuotas,
            'cuota_periodica' => $cuota,
            'fecha_autorizacion' => $fechaAutorizacion,
        ]);
        $servicio->generarCuotasDesde($prestamo, 20_000_000, $tasa, $cuota, $numCuotas, 1, $servicio->fechaPrimeraCuota($fechaAutorizacion));

        return $prestamo->fresh();
    }

    /**
     * @return list<string>
     */
    private function fechasPendientes(Prestamo $prestamo): array
    {
        return $prestamo->cuotas()->where('estado', EstadoCuota::Pendiente)->orderBy('numero_cuota')->get()
            ->map(fn ($cuota) => $cuota->fecha_vencimiento->toDateString())->all();
    }

    public function test_las_cuotas_reales_se_generan_en_el_15_y_fin_de_mes(): void
    {
        $prestamo = $this->prestamoConCuotas('2026-10-23', 6);

        $this->assertSame(
            ['2026-11-15', '2026-11-30', '2026-12-15', '2026-12-31', '2027-01-15', '2027-01-31'],
            $prestamo->cuotas()->orderBy('numero_cuota')->get()->map(fn ($cuota) => $cuota->fecha_vencimiento->toDateString())->all(),
        );
    }

    public function test_la_reliquidacion_mantiene_la_secuencia_de_fechas_al_bajar_el_plazo_o_la_cuota(): void
    {
        $actor = Usuario::factory()->create();

        foreach ([['plazo', null], ['cuota', null], ['plazo', 30]] as [$modo, $numCuotas]) {
            $prestamo = $this->prestamoConCuotas('2026-10-23');
            $prestamo->cuotas()->where('numero_cuota', '<=', 3)->update(['estado' => EstadoCuota::Pagada]);
            $antes = $this->fechasPendientes($prestamo);

            app(PagoService::class)->registrarAbonoExtraordinario($prestamo, 2_000_000, '2026-12-01', $actor, $modo, $numCuotas);

            $despues = $this->fechasPendientes($prestamo);

            $this->assertLessThanOrEqual(count($antes), count($despues));
            $this->assertSame(array_slice($antes, 0, count($despues)), $despues, "Secuencia tras reliquidar ({$modo})");

            foreach ($despues as $fecha) {
                $dia = CarbonImmutable::parse($fecha);
                $this->assertTrue($dia->day === 15 || $dia->day === $dia->daysInMonth, $fecha);
            }
        }
    }

    public function test_el_simulador_y_las_cuotas_reales_usan_las_mismas_fechas(): void
    {
        $anio = now()->year;
        SmmlvAnual::factory()->create(['anio' => $anio, 'valor' => 1_300_000]);
        $modalidad = TipoPrestamo::factory()->create(['nombre' => 'Educación', 'plazo_maximo' => 72]);
        TasaInt::factory()->create(['id_tipo' => $modalidad->id_tipo, 'anio' => $anio, 'tasa_anual' => 4.85]);
        CuantiaSmmlv::factory()->create(['id_tipo' => $modalidad->id_tipo, 'tramo_desde_smmlv' => 0, 'tramo_hasta_smmlv' => null, 'cuantia_maxima_smmlv' => 17.5]);
        PresupuestoAnual::factory()->create(['id_tipo' => $modalidad->id_tipo, 'anio' => $anio, 'monto_asignado' => 10_000_000_000]);
        $empleado = Empleado::factory()->create(['salario' => 5_000_000, 'capacidad_endeudamiento' => 2_500_000, 'fecha_vinculacion' => now()->subYears(5)]);

        foreach (['01-14', '03-31', '06-15', '11-16', '12-31'] as $diaMes) {
            Carbon::setTestNow(Carbon::parse("{$anio}-{$diaMes} 10:00:00"));

            $simulacion = app(SimuladorCreditoService::class)->simular($empleado, $modalidad, 4_000_000, 36);
            $prestamo = app(PrestamoService::class)->crearDesdeSolicitud(SolicitudCredito::factory()->create([
                'cedula_empleado' => $empleado->cedula,
                'id_tipo' => $modalidad->id_tipo,
                'monto_solicitado' => 4_000_000,
                'plazo_solicitado' => 36,
                'fecha_solicitud' => now()->toDateString(),
            ]));

            $this->assertSame(
                $prestamo->cuotas()->orderBy('numero_cuota')->get()->map(fn ($cuota) => $cuota->fecha_vencimiento->toDateString())->all(),
                array_column($simulacion['tabla_amortizacion'], 'fecha'),
                "Autorización el {$diaMes}",
            );
        }
    }

    // ───────── Cierre provisional de novedades ─────────

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function cierres(): array
    {
        return [
            'día 15' => ['2026-03-15', '2026-03-13'],
            'fin de mes de 31 días' => ['2026-03-31', '2026-03-29'],
            'fin de mes de 30 días' => ['2026-04-30', '2026-04-28'],
            'febrero normal' => ['2027-02-28', '2027-02-26'],
            'febrero bisiesto' => ['2028-02-29', '2028-02-27'],
            'cruce de mes' => ['2026-04-01', '2026-03-30'],
            'cruce de año' => ['2027-01-01', '2026-12-30'],
        ];
    }

    #[DataProvider('cierres')]
    public function test_el_cierre_de_novedades_es_dos_dias_calendario_antes_del_vencimiento(string $vencimiento, string $cierre): void
    {
        $this->assertSame(2, config('quincena.cierre_novedades_dias_antes'));
        $this->assertSame($cierre, $this->calendario()->cierreDeNovedades($vencimiento)->toDateString());
    }

    public function test_el_cierre_de_cada_vencimiento_de_un_anio_cae_dos_dias_antes(): void
    {
        foreach ($this->calendario()->vencimientosDesde('2028-01-01', 24) as $vencimiento) {
            $this->assertSame(
                CarbonImmutable::parse($vencimiento)->subDays(2)->toDateString(),
                $this->calendario()->cierreDeNovedades($vencimiento)->toDateString(),
            );
        }
    }

    public function test_cambiar_el_valor_del_cierre_por_configuracion_se_respeta(): void
    {
        config(['quincena.cierre_novedades_dias_antes' => 5]);
        $this->assertSame('2026-03-10', $this->calendario()->cierreDeNovedades('2026-03-15')->toDateString());
        $this->assertSame('2026-02-24', $this->calendario()->cierreDeNovedades('2026-03-01')->toDateString());

        config(['quincena.cierre_novedades_dias_antes' => 0]);
        $this->assertSame('2026-03-15', $this->calendario()->cierreDeNovedades('2026-03-15')->toDateString());

        config(['quincena.cierre_novedades_dias_antes' => -3]);
        $this->assertSame('2026-03-15', $this->calendario()->cierreDeNovedades('2026-03-15')->toDateString());
    }

    public function test_la_vista_previa_de_la_quincena_muestra_el_cierre_como_provisional(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-20 10:00:00'));
        $usuario = Usuario::factory()->create();

        $this->actingAs($usuario)->get(route('quincenas.vista-previa', [2026, 19]))
            ->assertOk()
            ->assertSee('Cierre de novedades de nómina: 2026-10-13')
            ->assertSee('provisional')
            ->assertSee('2 días antes del vencimiento')
            ->assertSee('solo informativo, no bloquea nada');

        config(['quincena.cierre_novedades_dias_antes' => 4]);

        $this->actingAs($usuario)->get(route('quincenas.vista-previa', [2026, 20]))
            ->assertOk()
            ->assertSee('Cierre de novedades de nómina: 2026-10-27')
            ->assertSee('4 días antes del vencimiento');
    }

    // ───────── El cierre no cambia ninguna operación ─────────

    /**
     * Aprueba, desembolsa, paga una cuota y confirma una quincena con el cierre
     * configurado y devuelve lo que quedó guardado (sin identificadores ni marcas
     * de tiempo). Todo se deshace al final para repetirlo con otro valor.
     *
     * @return array<string, mixed>
     */
    private function operacionesConCierre(int $diasAntes, TipoPrestamo $modalidad, Usuario $actor): array
    {
        config(['quincena.cierre_novedades_dias_antes' => $diasAntes]);
        $anio = now()->year;

        DB::beginTransaction();

        try {
            Carbon::setTestNow(Carbon::parse("{$anio}-03-03 10:00:00"));
            $empleado = Empleado::factory()->create(['salario' => 8_000_000, 'capacidad_endeudamiento' => 4_000_000, 'fecha_vinculacion' => now()->subYears(5)]);
            $solicitud = SolicitudCredito::factory()->create([
                'cedula_empleado' => $empleado->cedula,
                'id_tipo' => $modalidad->id_tipo,
                'monto_solicitado' => 6_000_000,
                'plazo_solicitado' => 24,
                'fecha_solicitud' => now()->toDateString(),
            ]);

            $prestamo = app(SolicitudCreditoService::class)->aprobar($solicitud, $actor);
            app(DesembolsoService::class)->registrar($prestamo, ['numero_tramo' => 1, 'fecha' => now()->toDateString()], $actor);

            $primera = $prestamo->cuotas()->orderBy('numero_cuota')->first();
            Carbon::setTestNow(Carbon::parse($primera->fecha_vencimiento->toDateString().' 10:00:00'));
            app(PagoService::class)->registrarPagoCuota($prestamo, $primera, $primera->fecha_vencimiento->toDateString(), $actor);

            $segunda = $prestamo->cuotas()->where('estado', EstadoCuota::Pendiente)->orderBy('numero_cuota')->first();
            Carbon::setTestNow(Carbon::parse($segunda->fecha_vencimiento->toDateString().' 10:00:00'));
            $quincena = $this->calendario()->quincenaDeFecha($segunda->fecha_vencimiento);
            $vista = app(QuincenaService::class)->procesar($quincena['anio'], $quincena['numero']);
            $proceso = app(QuincenaService::class)->confirmar(
                $quincena['anio'],
                $quincena['numero'],
                $actor,
                array_column($vista['cuotas'], 'valor_total', 'id_cuota'),
            );

            return [
                'solicitud' => $solicitud->fresh()->estado->value,
                'prestamo' => [...$prestamo->fresh()->only(['estado', 'monto_aprobado', 'cuota_periodica', 'num_cuotas']), 'fecha_autorizacion' => $prestamo->fresh()->fecha_autorizacion->toDateString()],
                'cuotas' => $prestamo->cuotas()->orderBy('numero_cuota')->get()->map(fn ($c) => [$c->numero_cuota, $c->fecha_vencimiento->toDateString(), $c->valor_capital, $c->valor_interes, $c->valor_total, $c->estado->value])->all(),
                'desembolsos' => $prestamo->desembolsos()->get()->map(fn ($d) => [$d->numero_tramo, $d->valor, $d->estado->value])->all(),
                'pagos' => $prestamo->pagos()->orderBy('id_pago')->get()->map(fn ($p) => [$p->fecha_pago->toDateString(), $p->monto, $p->tipo->value, $p->origen->value])->all(),
                'proceso' => [$proceso->estado->value, $proceso->resumen['pagos_creados'], $proceso->resumen['total']],
            ];
        } finally {
            Carbon::setTestNow();
            DB::rollBack();
        }
    }

    public function test_aprobar_desembolsar_pagar_y_confirmar_la_quincena_dan_lo_mismo_con_cualquier_cierre(): void
    {
        $anio = now()->year;
        SmmlvAnual::factory()->create(['anio' => $anio, 'valor' => 1_300_000]);
        $modalidad = TipoPrestamo::factory()->create(['nombre' => 'Educación', 'plazo_maximo' => 72]);
        TasaInt::factory()->create(['id_tipo' => $modalidad->id_tipo, 'anio' => $anio, 'tasa_anual' => 4.85]);
        CuantiaSmmlv::factory()->create(['id_tipo' => $modalidad->id_tipo, 'tramo_desde_smmlv' => 0, 'tramo_hasta_smmlv' => null, 'cuantia_maxima_smmlv' => 17.5]);
        PresupuestoAnual::factory()->create(['id_tipo' => $modalidad->id_tipo, 'anio' => $anio, 'monto_asignado' => 10_000_000_000]);
        $actor = Usuario::factory()->administrador()->create();

        $referencia = $this->operacionesConCierre(2, $modalidad, $actor);

        $this->assertSame('Aprobada', $referencia['solicitud']);
        $this->assertCount(24, $referencia['cuotas']);
        $this->assertSame('Pagada', $referencia['cuotas'][0][5]);
        $this->assertSame('Pagada', $referencia['cuotas'][1][5]);
        $this->assertSame(1, $referencia['proceso'][1]);

        // Con un cierre más temprano que el desembolso (14 o 30 días) la primera cuota pasa a la quincena
        // siguiente por diseño (PrimeraCuotaConCierreTest); estos cierres la dejan en la misma.
        foreach ([0, 1, 5, 9] as $dias) {
            $this->assertSame($referencia, $this->operacionesConCierre($dias, $modalidad, $actor), "Con el cierre a {$dias} días antes");
        }
    }
}
