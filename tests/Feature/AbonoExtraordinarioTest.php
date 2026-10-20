<?php

namespace Tests\Feature;

use App\Enums\EstadoCuota;
use App\Models\Prestamo;
use App\Models\TipoPrestamo;
use App\Models\Usuario;
use App\Services\CalculoFinancieroService;
use App\Services\PrestamoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Abono extraordinario con reliquidación (Acuerdo 19 de 2022, Art. 12 §4 y §5).
 */
class AbonoExtraordinarioTest extends TestCase
{
    use RefreshDatabase;

    private const TASA_EA = 1.61;

    private const NUM_CUOTAS = 130;

    private function calculo(): CalculoFinancieroService
    {
        return new CalculoFinancieroService;
    }

    private function prestamo(string $modalidad = 'Vivienda', int $cuotasPagadas = 0): Prestamo
    {
        $monto = 22_000_000;
        $prestamoService = app(PrestamoService::class);
        $tasaPeriodica = $prestamoService->tasaPeriodica(self::TASA_EA);
        $cuota = $prestamoService->calcularCuotaFrancesa($monto, $tasaPeriodica, self::NUM_CUOTAS);

        $prestamo = Prestamo::factory()->create([
            'id_tipo' => (TipoPrestamo::firstWhere('nombre', $modalidad) ?? TipoPrestamo::factory()->create(['nombre' => $modalidad]))->id_tipo,
            'tasa_efectiva_anual' => self::TASA_EA,
            'monto_aprobado' => $monto,
            'num_cuotas' => self::NUM_CUOTAS,
            'cuota_periodica' => $cuota,
        ]);

        $prestamoService->generarCuotasDesde($prestamo, $monto, $tasaPeriodica, $cuota, self::NUM_CUOTAS, 1, '2026-01-15');
        $prestamo->cuotas()->where('numero_cuota', '<=', $cuotasPagadas)->update(['estado' => EstadoCuota::Pagada]);

        return $prestamo->fresh();
    }

    private function saldoPendiente(Prestamo $prestamo): float
    {
        return (float) $prestamo->cuotas()->where('estado', EstadoCuota::Pendiente)->sum('valor_capital');
    }

    private function pendientes(Prestamo $prestamo): int
    {
        return $prestamo->cuotas()->where('estado', EstadoCuota::Pendiente)->count();
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function abonar(Usuario $usuario, Prestamo $prestamo, float $monto, array $extra = []): TestResponse
    {
        return $this->actingAs($usuario)->post(route('pagos.store-abono', $prestamo), [
            'monto' => $monto,
            'fecha_pago' => '2026-03-01',
            ...$extra,
        ]);
    }

    public function test_un_abono_menor_al_umbral_se_registra_sin_reliquidar_ni_pedir_opcion(): void
    {
        $prestamo = $this->prestamo();
        $monto = round((float) $prestamo->cuota_periodica * 9.99, 2);

        $this->abonar(Usuario::factory()->create(), $prestamo, $monto)
            ->assertRedirect(route('prestamos.show', $prestamo))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('pagos', ['id_prestamo' => $prestamo->id_prestamo, 'tipo' => 'Abono extraordinario', 'monto' => $monto]);
        $this->assertDatabaseCount('reliquidaciones', 0);
        $this->assertSame(self::NUM_CUOTAS, $this->pendientes($prestamo));
        $this->assertEqualsWithDelta((float) $prestamo->cuota_periodica, (float) $prestamo->fresh()->cuota_periodica, 0.001);
    }

    public function test_un_abono_de_exactamente_diez_cuotas_alcanza_el_umbral(): void
    {
        $prestamo = $this->prestamo();
        $monto = round((float) $prestamo->cuota_periodica * 10, 2);

        $this->abonar(Usuario::factory()->create(), $prestamo, $monto, ['modo_reliquidacion' => 'cuota'])
            ->assertRedirect(route('prestamos.show', $prestamo));

        $this->assertDatabaseCount('reliquidaciones', 1);
    }

    public function test_sobre_el_umbral_es_obligatorio_elegir_y_nada_se_guarda_sin_la_eleccion(): void
    {
        $prestamo = $this->prestamo();

        $this->abonar(Usuario::factory()->create(), $prestamo, round((float) $prestamo->cuota_periodica * 12, 2))
            ->assertSessionHas('error');

        $this->assertDatabaseCount('pagos', 0);
        $this->assertDatabaseCount('reliquidaciones', 0);
        $this->assertSame(self::NUM_CUOTAS, $this->pendientes($prestamo));
    }

    public function test_bajar_la_cuota_mantiene_las_cuotas_restantes_y_recalcula_el_valor(): void
    {
        $actor = Usuario::factory()->create();
        $prestamo = $this->prestamo(cuotasPagadas: 3);
        $cuotaAnterior = (float) $prestamo->cuota_periodica;
        $saldoAnterior = $this->saldoPendiente($prestamo);
        $monto = 2_000_000.0;
        $restantes = $this->pendientes($prestamo);

        $this->abonar($actor, $prestamo, $monto, ['modo_reliquidacion' => 'cuota'])
            ->assertRedirect(route('prestamos.show', $prestamo));

        $prestamo->refresh();
        $saldoNuevo = round($saldoAnterior - $monto, 2);
        $cuotaEsperada = $this->calculo()->cuotaFija($saldoNuevo, $this->calculo()->tasaPeriodicaQuincenal(self::TASA_EA), $restantes);

        $this->assertSame($restantes, $this->pendientes($prestamo));
        $this->assertSame(self::NUM_CUOTAS, $prestamo->num_cuotas);
        $this->assertEqualsWithDelta($cuotaEsperada, (float) $prestamo->cuota_periodica, 0.001);
        $this->assertLessThan($cuotaAnterior, (float) $prestamo->cuota_periodica);
        $this->assertEqualsWithDelta($saldoNuevo, $this->saldoPendiente($prestamo), 0.01);

        $this->assertDatabaseHas('pagos', ['id_prestamo' => $prestamo->id_prestamo, 'tipo' => 'Abono extraordinario', 'monto' => $monto, 'monto_aplicado_capital' => $monto, 'cedula_usu' => $actor->cedula]);
        $this->assertDatabaseHas('reliquidaciones', [
            'id_prestamo' => $prestamo->id_prestamo,
            'motivo' => 'Abono extraordinario',
            'opcion_elegida' => 'cuota',
            'saldo_anterior' => $saldoAnterior,
            'saldo_nuevo' => $saldoNuevo,
            'fecha' => '2026-03-01 00:00:00',
            'cedula_usu' => $actor->cedula,
        ]);
        $this->assertDatabaseHas('auditorias', ['cedula_usu' => $actor->cedula, 'funcion_realizada' => 'Reliquidación de préstamo']);
    }

    public function test_las_cuotas_ya_pagadas_no_se_tocan(): void
    {
        $prestamo = $this->prestamo(cuotasPagadas: 3);
        $antes = $prestamo->cuotas()->where('estado', EstadoCuota::Pagada)->get()->map->only(['id_cuota', 'numero_cuota', 'valor_capital', 'valor_interes', 'valor_total'])->all();

        $this->abonar(Usuario::factory()->create(), $prestamo, 2_000_000, ['modo_reliquidacion' => 'plazo'])
            ->assertRedirect(route('prestamos.show', $prestamo));

        $despues = $prestamo->cuotas()->where('estado', EstadoCuota::Pagada)->get()->map->only(['id_cuota', 'numero_cuota', 'valor_capital', 'valor_interes', 'valor_total'])->all();

        $this->assertCount(3, $despues);
        $this->assertSame($antes, $despues);
        $this->assertSame(4, $prestamo->cuotas()->where('estado', EstadoCuota::Pendiente)->min('numero_cuota'));
    }

    public function test_bajar_el_plazo_usa_el_n_sugerido_y_recalcula_la_cuota(): void
    {
        $prestamo = $this->prestamo();
        $cuotaAnterior = (float) $prestamo->cuota_periodica;
        $monto = 2_000_000.0;
        $saldoNuevo = round(22_000_000 - $monto, 2);
        $tasa = $this->calculo()->tasaPeriodicaQuincenal(self::TASA_EA);
        $nSugerido = $this->calculo()->numPeriodosParaCuota($saldoNuevo, $tasa, $cuotaAnterior);

        $this->abonar(Usuario::factory()->create(), $prestamo, $monto, ['modo_reliquidacion' => 'plazo'])
            ->assertRedirect(route('prestamos.show', $prestamo));

        $prestamo->refresh();

        $this->assertLessThan(self::NUM_CUOTAS, $nSugerido);
        $this->assertSame($nSugerido, $this->pendientes($prestamo));
        $this->assertSame($nSugerido, $prestamo->num_cuotas);
        $this->assertEqualsWithDelta($this->calculo()->cuotaFija($saldoNuevo, $tasa, $nSugerido), (float) $prestamo->cuota_periodica, 0.001);
        $this->assertLessThanOrEqual($cuotaAnterior, (float) $prestamo->cuota_periodica);
        $this->assertEqualsWithDelta($saldoNuevo, $this->saldoPendiente($prestamo), 0.01);
        $this->assertDatabaseHas('reliquidaciones', ['id_prestamo' => $prestamo->id_prestamo, 'opcion_elegida' => 'plazo']);
    }

    public function test_bajar_el_plazo_con_un_n_digitado_recalcula_la_cuota_con_ese_n(): void
    {
        $prestamo = $this->prestamo();
        $saldoNuevo = round(22_000_000 - 2_000_000, 2);

        $this->abonar(Usuario::factory()->create(), $prestamo, 2_000_000, ['modo_reliquidacion' => 'plazo', 'num_cuotas' => 100])
            ->assertRedirect(route('prestamos.show', $prestamo));

        $prestamo->refresh();

        $this->assertSame(100, $this->pendientes($prestamo));
        $this->assertSame(100, $prestamo->num_cuotas);
        $this->assertEqualsWithDelta(
            $this->calculo()->cuotaFija($saldoNuevo, $this->calculo()->tasaPeriodicaQuincenal(self::TASA_EA), 100),
            (float) $prestamo->cuota_periodica,
            0.001,
        );
    }

    public function test_un_n_digitado_mayor_a_las_cuotas_que_faltan_se_rechaza_sin_guardar_nada(): void
    {
        $prestamo = $this->prestamo();

        $this->abonar(Usuario::factory()->create(), $prestamo, 2_000_000, ['modo_reliquidacion' => 'plazo', 'num_cuotas' => self::NUM_CUOTAS + 1])
            ->assertSessionHas('error');

        $this->assertDatabaseCount('pagos', 0);
        $this->assertDatabaseCount('reliquidaciones', 0);
        $this->assertSame(self::NUM_CUOTAS, $this->pendientes($prestamo));
    }

    public function test_un_abono_igual_o_mayor_al_saldo_se_rechaza_y_no_cancela_el_prestamo(): void
    {
        $prestamo = $this->prestamo();

        foreach ([$this->saldoPendiente($prestamo), $this->saldoPendiente($prestamo) + 1_000_000] as $monto) {
            $this->abonar(Usuario::factory()->create(), $prestamo, $monto, ['modo_reliquidacion' => 'cuota'])
                ->assertSessionHas('error');
        }

        $this->assertDatabaseCount('pagos', 0);
        $this->assertDatabaseCount('reliquidaciones', 0);
        $this->assertSame('Vigente', $prestamo->fresh()->estado->value);
    }

    public function test_un_abono_menor_al_umbral_pero_mayor_al_saldo_tambien_se_rechaza(): void
    {
        $prestamo = Prestamo::factory()->create([
            'id_tipo' => TipoPrestamo::factory()->create(['nombre' => 'Educación'])->id_tipo,
            'num_cuotas' => 5,
            'cuota_periodica' => 100_000,
        ]);
        foreach (range(1, 5) as $numero) {
            $prestamo->cuotas()->create([
                'numero_cuota' => $numero,
                'fecha_vencimiento' => '2026-03-01',
                'valor_capital' => 100_000,
                'valor_interes' => 0,
                'valor_total' => 100_000,
                'estado' => EstadoCuota::Pendiente,
            ]);
        }
        $usuario = Usuario::factory()->create();

        // Saldo de capital 500.000; el umbral son 1.000.000 (10 cuotas): 700.000 y 500.000 quedan por debajo.
        foreach ([700_000, 500_000] as $monto) {
            $this->abonar($usuario, $prestamo, $monto)
                ->assertRedirect()
                ->assertSessionHas('error', fn ($mensaje) => str_contains($mensaje, 'Cancelación total'));
        }

        $this->assertDatabaseCount('pagos', 0);
        $this->assertDatabaseCount('reliquidaciones', 0);
        $this->assertSame(5, $this->pendientes($prestamo));
        $this->assertSame('Vigente', $prestamo->fresh()->estado->value);

        $this->abonar($usuario, $prestamo, 499_999.99)->assertSessionHas('status');
        $this->assertDatabaseCount('pagos', 1);
    }

    public function test_en_una_modalidad_no_configurada_el_abono_grande_va_a_capital_sin_reliquidar(): void
    {
        $prestamo = $this->prestamo('Educación');

        $this->abonar(Usuario::factory()->create(), $prestamo, round((float) $prestamo->cuota_periodica * 15, 2))
            ->assertRedirect(route('prestamos.show', $prestamo))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('pagos', ['id_prestamo' => $prestamo->id_prestamo, 'tipo' => 'Abono extraordinario']);
        $this->assertDatabaseCount('reliquidaciones', 0);
    }

    public function test_el_umbral_y_las_modalidades_se_pueden_cambiar_por_configuracion(): void
    {
        config([
            'prestamos.abono_extraordinario.umbral_cuotas' => 5,
            'prestamos.abono_extraordinario.modalidades' => ['*'],
        ]);
        $prestamo = $this->prestamo('Educación');

        $this->abonar(Usuario::factory()->create(), $prestamo, round((float) $prestamo->cuota_periodica * 5, 2), ['modo_reliquidacion' => 'cuota'])
            ->assertRedirect(route('prestamos.show', $prestamo));

        $this->assertDatabaseCount('reliquidaciones', 1);
    }

    public function test_el_administrador_y_el_funcionario_pueden_registrar_el_abono(): void
    {
        foreach ([Usuario::factory()->administrador()->create(), Usuario::factory()->create()] as $usuario) {
            $prestamo = $this->prestamo();

            $this->abonar($usuario, $prestamo, 2_000_000, ['modo_reliquidacion' => 'cuota'])
                ->assertRedirect(route('prestamos.show', $prestamo))
                ->assertSessionHas('status');

            $this->assertDatabaseHas('reliquidaciones', ['id_prestamo' => $prestamo->id_prestamo, 'cedula_usu' => $usuario->cedula]);
        }
    }

    public function test_un_invitado_no_puede_abonar_ni_ver_la_vista_previa(): void
    {
        $prestamo = $this->prestamo();

        $this->post(route('pagos.store-abono', $prestamo), ['monto' => 2_000_000])->assertRedirect(route('login'));
        $this->get(route('pagos.vista-previa-abono', [$prestamo, 'monto' => 2_000_000]))->assertRedirect(route('login'));
        $this->assertDatabaseCount('pagos', 0);
    }

    public function test_no_se_puede_abonar_ni_previsualizar_en_un_prestamo_cancelado(): void
    {
        $prestamo = Prestamo::factory()->cancelado()->create();
        $usuario = Usuario::factory()->create();

        $this->abonar($usuario, $prestamo, 2_000_000)->assertForbidden();
        $this->actingAs($usuario)->getJson(route('pagos.vista-previa-abono', [$prestamo, 'monto' => 2_000_000]))->assertForbidden();
    }

    public function test_la_vista_previa_bajo_el_umbral_no_ofrece_eleccion(): void
    {
        $prestamo = $this->prestamo();

        $this->actingAs(Usuario::factory()->create())
            ->getJson(route('pagos.vista-previa-abono', [$prestamo, 'monto' => 500_000]))
            ->assertOk()
            ->assertJson(['supera_umbral' => false, 'umbral_cuotas' => 10])
            ->assertJsonMissingPath('plan');
    }

    public function test_la_vista_previa_sobre_el_umbral_calcula_el_plan_sin_guardar_nada(): void
    {
        $prestamo = $this->prestamo();
        $saldoNuevo = round(22_000_000 - 2_000_000, 2);
        $tasa = $this->calculo()->tasaPeriodicaQuincenal(self::TASA_EA);
        $usuario = Usuario::factory()->create();

        $this->actingAs($usuario)
            ->getJson(route('pagos.vista-previa-abono', [$prestamo, 'monto' => 2_000_000, 'modo_reliquidacion' => 'cuota']))
            ->assertOk()
            ->assertJson([
                'supera_umbral' => true,
                'plan' => [
                    'saldo_anterior' => 22_000_000,
                    'saldo_nuevo' => $saldoNuevo,
                    'cuotas_actuales' => self::NUM_CUOTAS,
                    'cuotas_nuevas' => self::NUM_CUOTAS,
                    'n_sugerido' => null,
                    'cuota_nueva' => $this->calculo()->cuotaFija($saldoNuevo, $tasa, self::NUM_CUOTAS),
                ],
            ]);

        $sugerido = $this->calculo()->numPeriodosParaCuota($saldoNuevo, $tasa, (float) $prestamo->cuota_periodica);

        $this->actingAs($usuario)
            ->getJson(route('pagos.vista-previa-abono', [$prestamo, 'monto' => 2_000_000, 'modo_reliquidacion' => 'plazo']))
            ->assertJsonPath('plan.n_sugerido', $sugerido)
            ->assertJsonPath('plan.cuotas_nuevas', $sugerido);

        $this->actingAs($usuario)
            ->getJson(route('pagos.vista-previa-abono', [$prestamo, 'monto' => 2_000_000, 'modo_reliquidacion' => 'plazo', 'num_cuotas' => 100]))
            ->assertJsonPath('plan.cuotas_nuevas', 100)
            ->assertJsonPath('plan.n_sugerido', $sugerido);

        $this->assertDatabaseCount('pagos', 0);
        $this->assertDatabaseCount('reliquidaciones', 0);
        $this->assertSame(self::NUM_CUOTAS, $this->pendientes($prestamo));
    }

    public function test_la_vista_previa_de_un_abono_mayor_al_saldo_devuelve_el_error(): void
    {
        $prestamo = $this->prestamo();

        $this->actingAs(Usuario::factory()->create())
            ->getJson(route('pagos.vista-previa-abono', [$prestamo, 'monto' => 30_000_000, 'modo_reliquidacion' => 'cuota']))
            ->assertOk()
            ->assertJsonPath('error', fn ($error) => str_contains($error, 'menor que el saldo'))
            ->assertJsonMissingPath('plan');
    }

    public function test_la_reliquidacion_aparece_en_el_historial_del_prestamo(): void
    {
        $usuario = Usuario::factory()->create();
        $prestamo = $this->prestamo();

        $this->abonar($usuario, $prestamo, 2_000_000, ['modo_reliquidacion' => 'cuota']);

        $this->actingAs($usuario)->get(route('prestamos.show', $prestamo))
            ->assertOk()
            ->assertSee('Historial de reliquidaciones')
            ->assertSee('Bajó la cuota')
            ->assertSee('20,000,000.00');
    }

    public function test_la_pantalla_del_prestamo_muestra_el_formulario_con_el_umbral(): void
    {
        $prestamo = $this->prestamo();

        $this->actingAs(Usuario::factory()->create())->get(route('prestamos.show', $prestamo))
            ->assertOk()
            ->assertSee('Registrar abono extraordinario')
            ->assertSee('Un abono menor a 10 cuotas', false)
            ->assertSee('abonos\/vista-previa', false);
    }
}
