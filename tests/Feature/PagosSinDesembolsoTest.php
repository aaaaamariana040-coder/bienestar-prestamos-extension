<?php

namespace Tests\Feature;

use App\Enums\EstadoCuota;
use App\Enums\MotivoReliquidacion;
use App\Models\Prestamo;
use App\Models\TipoPrestamo;
use App\Models\Usuario;
use App\Services\CesantiasService;
use App\Services\DesembolsoService;
use App\Services\PagoService;
use App\Services\QuincenaService;
use App\Services\ReliquidacionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Un pago o abono solo se registra sobre un crédito efectivo: mientras el préstamo
 * está pendiente de desembolso, el servicio lo rechaza.
 */
class PagosSinDesembolsoTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $funcionario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->funcionario = Usuario::factory()->create();
    }

    private function prestamo(bool $pendiente, string $modalidad = 'Educación'): Prestamo
    {
        $tipo = TipoPrestamo::factory()->create(['nombre' => $modalidad]);
        $prestamo = Prestamo::factory()->create([
            'id_tipo' => $tipo->id_tipo,
            'monto_aprobado' => 300_000,
            'cuota_periodica' => 100_000,
            'pendiente_desembolso' => $pendiente,
        ]);

        foreach ([1, 2, 3] as $n) {
            $prestamo->cuotas()->create([
                'numero_cuota' => $n,
                'fecha_vencimiento' => "2026-0{$n}-15",
                'valor_capital' => 95_000,
                'valor_interes' => 5_000,
                'valor_total' => 100_000,
                'estado' => EstadoCuota::Pendiente,
            ]);
        }

        return $prestamo;
    }

    private function assertBloqueado(callable $accion, Prestamo $prestamo): void
    {
        try {
            $accion();
            $this->fail('Se esperaba que el servicio rechazara el movimiento.');
        } catch (RuntimeException $e) {
            $this->assertSame(Prestamo::MENSAJE_PENDIENTE_DESEMBOLSO, $e->getMessage());
        }

        $this->assertDatabaseCount('pagos', 0);
        $this->assertSame(3, $prestamo->cuotas()->where('estado', EstadoCuota::Pendiente)->count());
    }

    public function test_el_mensaje_es_el_acordado(): void
    {
        $this->assertSame(
            'No se puede registrar: el préstamo aún no se ha desembolsado. Los pagos y abonos se registran después del desembolso.',
            Prestamo::MENSAJE_PENDIENTE_DESEMBOLSO,
        );
    }

    public function test_el_servicio_bloquea_pago_abono_cancelacion_y_reliquidacion(): void
    {
        $prestamo = $this->prestamo(true);
        $cuota = $prestamo->cuotas()->first();
        $pagos = app(PagoService::class);

        $this->assertBloqueado(fn () => $pagos->registrarPagoCuota($prestamo, $cuota, '2026-01-15', $this->funcionario), $prestamo);
        $this->assertBloqueado(fn () => $pagos->registrarAbonoExtraordinario($prestamo, 50_000, '2026-01-15', $this->funcionario), $prestamo);
        $this->assertBloqueado(fn () => $pagos->registrarCancelacion($prestamo, '2026-01-15', $this->funcionario), $prestamo);
        $this->assertBloqueado(fn () => app(ReliquidacionService::class)->reliquidar($prestamo, 50_000, MotivoReliquidacion::AbonoExtraordinario, 'plazo', '2026-01-15', $this->funcionario), $prestamo);
        $this->assertDatabaseCount('reliquidaciones', 0);
    }

    public function test_el_abono_de_cesantias_tambien_se_bloquea(): void
    {
        $prestamo = $this->prestamo(true, 'Vivienda — compra');

        $this->assertBloqueado(fn () => app(CesantiasService::class)->registrarAbono($prestamo, 2025, 50_000, '2026-01-15', $this->funcionario), $prestamo);
        $this->assertDatabaseCount('abonos_cesantias', 0);
    }

    public function test_las_rutas_devuelven_el_mensaje_y_no_registran_nada(): void
    {
        $prestamo = $this->prestamo(true);
        $cuota = $prestamo->cuotas()->first();

        $this->actingAs($this->funcionario)
            ->post(route('pagos.store-cuota', [$prestamo, $cuota]), ['fecha_pago' => '2026-01-15'])
            ->assertSessionHas('error', Prestamo::MENSAJE_PENDIENTE_DESEMBOLSO);
        $this->actingAs($this->funcionario)
            ->post(route('pagos.store-abono', $prestamo), ['monto' => '50000', 'fecha_pago' => '2026-01-15'])
            ->assertSessionHas('error', Prestamo::MENSAJE_PENDIENTE_DESEMBOLSO);
        $this->actingAs($this->funcionario)
            ->post(route('pagos.store-cancelacion', $prestamo), ['fecha_pago' => '2026-01-15'])
            ->assertSessionHas('error', Prestamo::MENSAJE_PENDIENTE_DESEMBOLSO);

        $this->assertDatabaseCount('pagos', 0);
    }

    public function test_los_mismos_movimientos_funcionan_tras_registrar_el_desembolso(): void
    {
        $prestamo = $this->prestamo(true);
        app(DesembolsoService::class)->registrar($prestamo, ['numero_tramo' => 1, 'fecha' => '2026-01-02'], $this->funcionario);
        $prestamo->refresh();
        $this->assertFalse($prestamo->pendiente_desembolso);

        $pagos = app(PagoService::class);
        $pagos->registrarPagoCuota($prestamo, $prestamo->cuotas()->first(), '2026-01-15', $this->funcionario);
        $pagos->registrarAbonoExtraordinario($prestamo, 50_000, '2026-01-16', $this->funcionario);
        $pagos->registrarCancelacion($prestamo, '2026-01-17', $this->funcionario);

        $this->assertDatabaseCount('pagos', 3);
    }

    public function test_los_prestamos_anteriores_a_la_regla_no_cambian(): void
    {
        $prestamo = $this->prestamo(false);

        app(PagoService::class)->registrarPagoCuota($prestamo, $prestamo->cuotas()->first(), '2026-01-15', $this->funcionario);

        $this->assertDatabaseCount('pagos', 1);
    }

    public function test_los_botones_aparecen_deshabilitados_con_motivo_mientras_esta_pendiente(): void
    {
        $prestamo = $this->prestamo(true, 'Vivienda — compra');

        $html = $this->actingAs($this->funcionario)->get(route('prestamos.show', $prestamo))->assertOk()->getContent();

        $this->assertSame(3, substr_count($html, 'Se puede pagar después del desembolso.'));
        $this->assertSame(3, substr_count($html, 'Disponible después del desembolso'));
        $this->assertMatchesRegularExpression('/<button[^>]*disabled[^>]*>\s*Pagar/', $html);
        $this->assertMatchesRegularExpression('/<fieldset[^>]*disabled/', $html);
        $this->assertMatchesRegularExpression('/<button[^>]*disabled[^>]*>Cancelar préstamo/', $html);
    }

    public function test_los_botones_estan_habilitados_cuando_ya_se_desembolso(): void
    {
        $prestamo = $this->prestamo(false);

        $html = $this->actingAs($this->funcionario)->get(route('prestamos.show', $prestamo))->assertOk()->getContent();

        $this->assertStringNotContainsString('pago-bloqueado', $html);
        $this->assertDoesNotMatchRegularExpression('/<fieldset[^>]*disabled/', $html);
        $this->assertDoesNotMatchRegularExpression('/<button[^>]*disabled[^>]*>\s*Pagar/', $html);
    }

    public function test_el_aviso_de_cuota_excluida_se_configura(): void
    {
        config(['quincena.aviso_cuota_excluida_lugar' => 'la caja 3']);

        $this->assertStringContainsString('Debe pagarse por ventanilla: la caja 3.', app(QuincenaService::class)->avisoCuotaExcluida());
    }
}
