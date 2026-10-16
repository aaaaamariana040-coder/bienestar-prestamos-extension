<?php

namespace Tests\Feature;

use App\Enums\OrigenPago;
use App\Models\Cuota;
use App\Models\Empleado;
use App\Models\Pago;
use App\Models\Prestamo;
use App\Models\TipoPrestamo;
use App\Models\Usuario;
use App\Services\HistorialPagosService;
use App\Services\PagoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CuotaPagadaUnificadaTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actor = Usuario::factory()->create();
    }

    private function prestamoConCuotas(string $cedula, int $numCuotas = 4, ?TipoPrestamo $tipo = null): Prestamo
    {
        $empleado = Empleado::query()->find($cedula) ?? Empleado::factory()->create(['cedula' => $cedula, 'nombre_completo' => 'Empleado '.$cedula]);

        $prestamo = Prestamo::factory()->create([
            'cedula_empleado' => $empleado->cedula,
            'id_tipo' => ($tipo ?? TipoPrestamo::factory()->create())->id_tipo,
            'num_cuotas' => $numCuotas,
            'cuota_periodica' => 100_000,
        ]);

        foreach (range(1, $numCuotas) as $numero) {
            Cuota::factory()->create([
                'id_prestamo' => $prestamo->id_prestamo,
                'numero_cuota' => $numero,
                'valor_total' => 100_000,
                'valor_capital' => 90_000,
                'valor_interes' => 10_000,
            ]);
        }

        return $prestamo;
    }

    private function pagar(Prestamo $prestamo, int $numeroCuota, string $fecha): void
    {
        app(PagoService::class)->registrarPagoCuota(
            $prestamo,
            $prestamo->cuotas()->where('numero_cuota', $numeroCuota)->firstOrFail(),
            $fecha,
            $this->actor,
        );
    }

    public function test_ambas_definiciones_dan_lo_mismo_para_la_fecha_de_hoy(): void
    {
        $hoy = Carbon::today();

        $sinPagos = $this->prestamoConCuotas('1000000001');

        $parcial = $this->prestamoConCuotas('1000000002');
        $this->pagar($parcial, 1, $hoy->copy()->subDays(40)->toDateString());
        $this->pagar($parcial, 2, $hoy->copy()->subDays(10)->toDateString());

        $cancelado = $this->prestamoConCuotas('1000000003');
        $this->pagar($cancelado, 1, $hoy->copy()->subDays(30)->toDateString());
        app(PagoService::class)->registrarCancelacion($cancelado, $hoy->copy()->subDays(5)->toDateString(), $this->actor);

        $ultimaCuota = $this->prestamoConCuotas('1000000004', 2);
        $this->pagar($ultimaCuota, 1, $hoy->copy()->subDays(20)->toDateString());
        $this->pagar($ultimaCuota, 2, $hoy->copy()->subDays(2)->toDateString());

        $prestamos = Prestamo::all();
        $porFecha = app(HistorialPagosService::class)->cuotasPagadasHasta($prestamos, $hoy);

        $this->assertCount(4, $porFecha);

        foreach ($prestamos as $prestamo) {
            $this->assertSame(
                $prestamo->progreso_cuotas,
                'Cuota '.$porFecha[$prestamo->id_prestamo].' de '.$prestamo->num_cuotas,
                "Préstamo #{$prestamo->id_prestamo} ({$prestamo->estado->value}) no coincide"
            );
        }

        $this->assertSame(0, $porFecha[$sinPagos->id_prestamo]);
        $this->assertSame(2, $porFecha[$parcial->id_prestamo]);
        $this->assertSame(4, $porFecha[$cancelado->id_prestamo]);
        $this->assertSame(2, $porFecha[$ultimaCuota->id_prestamo]);
    }

    public function test_una_fecha_futura_tampoco_cambia_el_resultado_de_hoy(): void
    {
        $prestamo = $this->prestamoConCuotas('1000000001');
        $this->pagar($prestamo, 1, Carbon::today()->subDays(3)->toDateString());

        $servicio = app(HistorialPagosService::class);

        $this->assertSame(
            $servicio->cuotasPagadasHasta([$prestamo], Carbon::today()),
            $servicio->cuotasPagadasHasta([$prestamo], Carbon::today()->addYears(5)),
        );
    }

    public function test_el_tope_al_total_es_el_mismo_que_usa_progreso_cuotas(): void
    {
        $this->assertSame(36, Prestamo::toparAlTotal(40, 36));
        $this->assertSame(2, Prestamo::toparAlTotal(2, 36));

        // Cuotas pagadas de más (el total bajó al reliquidar): el Historial también topa.
        $prestamo = $this->prestamoConCuotas('1000000001', 3);
        $this->pagar($prestamo, 1, '2026-03-01');
        $this->pagar($prestamo, 2, '2026-03-16');
        $this->pagar($prestamo, 3, '2026-04-01');
        $prestamo->update(['num_cuotas' => 2]);

        $this->assertSame('Cuota 2 de 2', $prestamo->fresh()->progreso_cuotas);
        $this->assertSame(
            [$prestamo->id_prestamo => 2],
            app(HistorialPagosService::class)->cuotasPagadasHasta([$prestamo->fresh()], Carbon::create(2026, 12, 31)),
        );
    }

    public function test_un_cancelado_con_quincena_igual_o_posterior_al_ultimo_pago_muestra_el_total(): void
    {
        $prestamo = $this->prestamoConCuotas('1000000001');
        $this->pagar($prestamo, 1, '2026-04-30');
        $this->pagar($prestamo, 2, '2026-05-10');
        app(PagoService::class)->registrarCancelacion($prestamo, '2026-05-15', $this->actor);

        $servicio = app(HistorialPagosService::class);
        $prestamo = $prestamo->fresh();

        // Q9 de 2026 cierra el 15 de mayo (igual al último pago) y Q11 el 15 de junio (posterior).
        foreach ([9, 11] as $quincena) {
            $cierre = $servicio->cierreDeQuincena(2026, $quincena);

            $this->assertSame([$prestamo->id_prestamo => 4], $servicio->cuotasPagadasHasta([$prestamo], $cierre), "Q{$quincena}");
        }
    }

    public function test_un_cancelado_con_quincena_anterior_al_ultimo_pago_cuenta_solo_hasta_el_cierre(): void
    {
        $prestamo = $this->prestamoConCuotas('1000000001');
        $this->pagar($prestamo, 1, '2026-04-30');
        $this->pagar($prestamo, 2, '2026-05-10');
        app(PagoService::class)->registrarCancelacion($prestamo, '2026-06-05', $this->actor);

        $servicio = app(HistorialPagosService::class);
        $prestamo = $prestamo->fresh();

        $this->assertSame([$prestamo->id_prestamo => 2], $servicio->cuotasPagadasHasta([$prestamo], $servicio->cierreDeQuincena(2026, 9)));
        $this->assertSame([$prestamo->id_prestamo => 1], $servicio->cuotasPagadasHasta([$prestamo], $servicio->cierreDeQuincena(2026, 8)));
        $this->assertSame([$prestamo->id_prestamo => 0], $servicio->cuotasPagadasHasta([$prestamo], $servicio->cierreDeQuincena(2026, 1)));
        $this->assertSame([$prestamo->id_prestamo => 4], $servicio->cuotasPagadasHasta([$prestamo], $servicio->cierreDeQuincena(2026, 12)));
    }

    public function test_la_q_muestra_el_cancelado_solo_en_quincenas_anteriores_a_su_ultimo_pago(): void
    {
        $tipo = TipoPrestamo::factory()->create(['nombre' => 'Calamidad doméstica']);
        $prestamo = $this->prestamoConCuotas('1000000001', 4, $tipo);
        $this->pagar($prestamo, 1, '2026-04-30');
        $this->pagar($prestamo, 2, '2026-05-10');
        app(PagoService::class)->registrarCancelacion($prestamo, '2026-06-05', $this->actor);

        $usuario = Usuario::factory()->create();

        $this->actingAs($usuario)
            ->get(route('reportes.historial-pagos', ['anio' => 2026, 'quincena' => 9]))
            ->assertSee('Calamidad doméstica')
            ->assertSee('<span class="credito-cuotas">2/4</span>', false);

        // Q11 (cierre 15 de junio): ya estaba cancelado, no es un crédito vigente de esa quincena.
        $this->actingAs($usuario)
            ->get(route('reportes.historial-pagos', ['anio' => 2026, 'quincena' => 11]))
            ->assertDontSee('Calamidad doméstica');

        // En la consulta individual sí aparece, con el total.
        $this->actingAs($usuario)
            ->get(route('reportes.historial-pagos', ['anio' => 2026, 'quincena' => 11, 'cedula' => '1000000001']))
            ->assertSee('Calamidad doméstica')
            ->assertSee('4/4');
    }

    public function test_un_cancelado_sin_pagos_registrados_cuenta_como_total_y_no_aparece_en_la_q(): void
    {
        $prestamo = Prestamo::factory()->cancelado()->create(['num_cuotas' => 24]);

        $this->assertSame(
            [$prestamo->id_prestamo => 24],
            app(HistorialPagosService::class)->cuotasPagadasHasta([$prestamo], Carbon::create(2026, 5, 15)),
        );
    }

    public function test_el_reporte_individual_y_su_impresion_muestran_origen_y_cuota_x_de_y(): void
    {
        $tipo = TipoPrestamo::factory()->create(['nombre' => 'Vivienda propia']);
        $prestamo = $this->prestamoConCuotas('1000000001', 36, $tipo);

        $cuotaUno = $prestamo->cuotas()->where('numero_cuota', 1)->first();
        $cuotaDos = $prestamo->cuotas()->where('numero_cuota', 2)->first();

        foreach ([[$cuotaUno, '2026-03-15', OrigenPago::Manual], [$cuotaDos, '2026-03-31', OrigenPago::Automatico]] as [$cuota, $fecha, $origen]) {
            $cuota->update(['estado' => 'Pagada']);
            Pago::factory()->create([
                'id_prestamo' => $prestamo->id_prestamo,
                'id_cuota' => $cuota->id_cuota,
                'fecha_pago' => $fecha,
                'monto' => 100_000,
                'tipo' => 'Regular',
                'origen' => $origen,
            ]);
        }

        $consulta = [
            'anio' => 2026, 'quincena' => 10, 'cedula' => '1000000001',
            'modalidad' => $tipo->id_tipo, 'desde' => '2026-03-01', 'hasta' => '2026-03-31',
        ];

        $usuario = Usuario::factory()->create();

        $pantalla = $this->actingAs($usuario)->get(route('reportes.historial-pagos', $consulta))->assertOk();
        $impresion = $this->actingAs($usuario)->get(route('reportes.historial-pagos.imprimir', ['tipo' => 'individual'] + $consulta))->assertOk();

        foreach ([$pantalla, $impresion] as $respuesta) {
            $respuesta
                ->assertSee('<th>Origen</th>', false)
                ->assertSee('<td>Manual</td>', false)
                ->assertSee('<td>Automático</td>', false)
                ->assertSee('<td>1 de 36</td>', false)
                ->assertSee('<td>2 de 36</td>', false);
        }
    }
}
