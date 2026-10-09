<?php

namespace Tests\Feature;

use App\Enums\EstadoCuota;
use App\Enums\MotivoReliquidacion;
use App\Models\Prestamo;
use App\Models\TipoPrestamo;
use App\Models\Usuario;
use App\Services\CalculoFinancieroService;
use App\Services\PrestamoService;
use App\Services\ReliquidacionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReliquidacionTest extends TestCase
{
    use RefreshDatabase;

    private function crearPrestamoConTablaReal(float $monto = 10_000_000, int $numCuotas = 24, float $tasaAnual = 4.85): Prestamo
    {
        $prestamoService = app(PrestamoService::class);
        $tasaPeriodica = $prestamoService->tasaPeriodica($tasaAnual);
        $cuotaPeriodica = $prestamoService->calcularCuotaFrancesa($monto, $tasaPeriodica, $numCuotas);

        $tipoPrestamo = TipoPrestamo::factory()->create(['nombre' => 'Educación']);
        $prestamo = Prestamo::factory()->create([
            'id_tipo' => $tipoPrestamo->id_tipo,
            'monto_aprobado' => $monto,
            'num_cuotas' => $numCuotas,
            'cuota_periodica' => $cuotaPeriodica,
        ]);

        $prestamoService->generarCuotasDesde($prestamo, $monto, $tasaPeriodica, $cuotaPeriodica, $numCuotas, 1, '2026-01-15');

        return $prestamo;
    }

    private function crearPrestamoRealSinTasaGuardada(?float $tasaEfectivaAnual): Prestamo
    {
        $calculo = new CalculoFinancieroService;
        $monto = 1_258_589;
        $tasaPeriodica = $calculo->tasaPeriodicaQuincenal(1.61);
        $cuota = $calculo->cuotaFija($monto, $tasaPeriodica, 7);

        $prestamo = Prestamo::factory()->create([
            'id_tipo' => TipoPrestamo::factory()->create(['nombre' => 'Educación'])->id_tipo,
            'monto_aprobado' => $monto,
            'num_cuotas' => 7,
            'cuota_periodica' => $cuota,
            'tasa_efectiva_anual' => $tasaEfectivaAnual,
        ]);

        app(PrestamoService::class)->generarCuotasDesde($prestamo, $monto, $tasaPeriodica, $cuota, 7, 1, '2026-01-15');

        return $prestamo;
    }

    public function test_reliquidar_con_la_tasa_guardada_da_la_cuota_exacta_del_caso_real(): void
    {
        $prestamo = $this->crearPrestamoRealSinTasaGuardada(1.61);

        // Saldo 1.258.589 menos un abono de 100.000 deja el saldo real del Excel: 1.158.589.
        $reliquidacion = app(ReliquidacionService::class)->reliquidar(
            $prestamo,
            100_000,
            MotivoReliquidacion::AbonoExtraordinario,
            'cuota',
            '2026-02-01',
            Usuario::factory()->create(),
        );

        $this->assertEqualsWithDelta(1_158_589, (float) $reliquidacion->saldo_nuevo, 0.01);
        $this->assertEqualsWithDelta(165_953.74, (float) $prestamo->fresh()->cuota_periodica, 0.01);
        $this->assertEqualsWithDelta(771.29, (float) $prestamo->cuotas()->where('estado', EstadoCuota::Pendiente)->orderBy('numero_cuota')->first()->valor_interes, 0.01);
        $this->assertEqualsWithDelta((new CalculoFinancieroService)->tasaNominalAnual(1.61), (float) $reliquidacion->tasa_aplicada, 0.0001);
    }

    public function test_un_prestamo_sin_tasa_guardada_se_reliquida_deduciendo_la_tasa_de_la_primera_cuota(): void
    {
        $prestamo = $this->crearPrestamoRealSinTasaGuardada(null);

        $reliquidacion = app(ReliquidacionService::class)->reliquidar(
            $prestamo,
            100_000,
            MotivoReliquidacion::AbonoExtraordinario,
            'cuota',
            '2026-02-01',
            Usuario::factory()->create(),
        );

        // Respaldo: la tasa deducida del interés redondeado es aproximada, de ahí la tolerancia de 0,05.
        $this->assertEqualsWithDelta(1_158_589, (float) $reliquidacion->saldo_nuevo, 0.01);
        $this->assertEqualsWithDelta(165_953.74, (float) $prestamo->fresh()->cuota_periodica, 0.05);
        $this->assertSame(7, $prestamo->cuotas()->where('estado', EstadoCuota::Pendiente)->count());
    }

    public function test_un_abono_pequeno_no_dispara_reliquidacion(): void
    {
        $funcionario = Usuario::factory()->create();
        $prestamo = $this->crearPrestamoConTablaReal();
        $cuotaOriginal = (float) $prestamo->cuota_periodica;

        $this->actingAs($funcionario)->post(route('pagos.store-abono', $prestamo), [
            'monto' => $cuotaOriginal * 5,
            'fecha_pago' => '2026-02-01',
        ])->assertRedirect(route('prestamos.show', $prestamo));

        $this->assertDatabaseCount('reliquidaciones', 0);
        $this->assertDatabaseHas('prestamos', ['id_prestamo' => $prestamo->id_prestamo, 'num_cuotas' => 24]);
    }

    /**
     * DIAGNÓSTICO, NO ES EL COMPORTAMIENTO DESEADO: documenta el estado actual
     * de un abono por debajo del umbral de 10 cuotas. Hoy solo se registra el
     * pago; la tabla de cuotas pendientes no se toca, así que ni el saldo
     * pendiente ni el interés futuro bajan. Cuando se corrija, esta prueba debe
     * fallar y reemplazarse por una que exija que el abono reduzca ambos.
     */
    public function test_diagnostico_un_abono_menor_al_umbral_no_reduce_saldo_ni_interes_futuro(): void
    {
        $funcionario = Usuario::factory()->create();
        $prestamo = $this->crearPrestamoConTablaReal();
        $cuotaOriginal = (float) $prestamo->cuota_periodica;

        $saldoAntes = (float) $prestamo->cuotas()->where('estado', EstadoCuota::Pendiente)->sum('valor_capital');
        $interesFuturoAntes = (float) $prestamo->cuotas()->where('estado', EstadoCuota::Pendiente)->sum('valor_interes');

        $this->actingAs($funcionario)->post(route('pagos.store-abono', $prestamo), [
            'monto' => round($cuotaOriginal * 5, 2),
            'fecha_pago' => '2026-02-01',
        ])->assertRedirect(route('prestamos.show', $prestamo));

        $this->assertDatabaseHas('pagos', ['id_prestamo' => $prestamo->id_prestamo, 'tipo' => 'Abono extraordinario']);
        $this->assertEqualsWithDelta($saldoAntes, (float) $prestamo->cuotas()->where('estado', EstadoCuota::Pendiente)->sum('valor_capital'), 0.01);
        $this->assertEqualsWithDelta($interesFuturoAntes, (float) $prestamo->cuotas()->where('estado', EstadoCuota::Pendiente)->sum('valor_interes'), 0.01);
    }

    public function test_un_abono_que_supera_10_cuotas_reliquida_bajando_el_plazo(): void
    {
        $funcionario = Usuario::factory()->create();
        $prestamo = $this->crearPrestamoConTablaReal();
        $cuotaOriginal = (float) $prestamo->cuota_periodica;
        $monto = round($cuotaOriginal * 15, 2);

        $this->actingAs($funcionario)->post(route('pagos.store-abono', $prestamo), [
            'monto' => $monto,
            'fecha_pago' => '2026-02-01',
            'modo_reliquidacion' => 'plazo',
        ])->assertRedirect(route('prestamos.show', $prestamo));

        $prestamo->refresh();

        $this->assertDatabaseCount('reliquidaciones', 1);
        $this->assertDatabaseHas('reliquidaciones', [
            'id_prestamo' => $prestamo->id_prestamo,
            'motivo' => 'Abono extraordinario',
            'saldo_anterior' => 10_000_000,
            'saldo_nuevo' => round(10_000_000 - $monto, 2),
        ]);

        // Modo "plazo": la cuota periódica no cambia, el número de cuotas sí baja.
        $this->assertSame(number_format($cuotaOriginal, 2), number_format((float) $prestamo->cuota_periodica, 2));
        $this->assertLessThan(24, $prestamo->num_cuotas);

        $saldoPendiente = $prestamo->cuotas()->where('estado', EstadoCuota::Pendiente)->sum('valor_capital');
        $this->assertEqualsWithDelta(round(10_000_000 - $monto, 2), (float) $saldoPendiente, 1.0);
    }

    public function test_un_abono_que_supera_10_cuotas_reliquida_bajando_la_cuota(): void
    {
        $funcionario = Usuario::factory()->create();
        $prestamo = $this->crearPrestamoConTablaReal();
        $cuotaOriginal = (float) $prestamo->cuota_periodica;
        $monto = round($cuotaOriginal * 15, 2);

        $this->actingAs($funcionario)->post(route('pagos.store-abono', $prestamo), [
            'monto' => $monto,
            'fecha_pago' => '2026-02-01',
            'modo_reliquidacion' => 'cuota',
        ])->assertRedirect(route('prestamos.show', $prestamo));

        $prestamo->refresh();

        $this->assertDatabaseCount('reliquidaciones', 1);
        // Modo "cuota": el plazo se mantiene, la cuota periódica baja.
        $this->assertSame(24, $prestamo->num_cuotas);
        $this->assertLessThan($cuotaOriginal, (float) $prestamo->cuota_periodica);
    }

    public function test_no_se_puede_reliquidar_con_un_abono_mayor_o_igual_al_saldo(): void
    {
        $funcionario = Usuario::factory()->create();
        $prestamo = $this->crearPrestamoConTablaReal();

        $this->actingAs($funcionario)->post(route('pagos.store-abono', $prestamo), [
            'monto' => 11_000_000,
            'fecha_pago' => '2026-02-01',
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertDatabaseCount('reliquidaciones', 0);
        $this->assertDatabaseCount('pagos', 0);
    }
}
