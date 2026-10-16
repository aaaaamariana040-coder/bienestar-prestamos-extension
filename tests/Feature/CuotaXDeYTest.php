<?php

namespace Tests\Feature;

use App\Enums\EstadoCuota;
use App\Enums\EstadoPrestamo;
use App\Enums\MotivoReliquidacion;
use App\Models\Prestamo;
use App\Models\TipoPrestamo;
use App\Models\Usuario;
use App\Services\PagoService;
use App\Services\PrestamoService;
use App\Services\ReliquidacionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CuotaXDeYTest extends TestCase
{
    use RefreshDatabase;

    private function prestamoConTabla(int $numCuotas = 36, float $monto = 10_000_000): Prestamo
    {
        $prestamoService = app(PrestamoService::class);
        $tasaPeriodica = $prestamoService->tasaPeriodica(4.85);
        $cuota = $prestamoService->calcularCuotaFrancesa($monto, $tasaPeriodica, $numCuotas);

        $prestamo = Prestamo::factory()->create([
            'id_tipo' => TipoPrestamo::factory()->create(['nombre' => 'Educación'])->id_tipo,
            'monto_aprobado' => $monto,
            'num_cuotas' => $numCuotas,
            'cuota_periodica' => $cuota,
            'tasa_efectiva_anual' => 4.85,
        ]);

        $prestamoService->generarCuotasDesde($prestamo, $monto, $tasaPeriodica, $cuota, $numCuotas, 1, '2026-01-15');

        return $prestamo;
    }

    private function pagarCuotas(Prestamo $prestamo, int $cuantas, Usuario $actor): void
    {
        foreach ($prestamo->cuotas()->orderBy('numero_cuota')->limit($cuantas)->get() as $cuota) {
            app(PagoService::class)->registrarPagoCuota($prestamo, $cuota, '2026-02-01', $actor);
        }
    }

    public function test_sin_pagos_lleva_cero_cuotas(): void
    {
        $prestamo = $this->prestamoConTabla();

        $this->assertSame(0, $prestamo->cuotasPagadas());
        $this->assertSame('Cuota 0 de 36', $prestamo->progreso_cuotas);
    }

    public function test_con_pagos_parciales_cuenta_solo_las_cuotas_pagadas(): void
    {
        $prestamo = $this->prestamoConTabla();
        $this->pagarCuotas($prestamo, 2, Usuario::factory()->create());

        $this->assertSame(2, $prestamo->cuotasPagadas());
        $this->assertSame('Cuota 2 de 36', $prestamo->progreso_cuotas);
        $this->assertSame(EstadoPrestamo::Vigente, $prestamo->fresh()->estado);
    }

    public function test_un_prestamo_cancelado_muestra_el_total_de_cuotas(): void
    {
        $prestamo = $this->prestamoConTabla(24);
        $this->pagarCuotas($prestamo, 3, Usuario::factory()->create());
        app(PagoService::class)->registrarCancelacion($prestamo, '2026-02-15', Usuario::factory()->create());

        $prestamo = $prestamo->fresh();

        $this->assertSame(EstadoPrestamo::Cancelado, $prestamo->estado);
        $this->assertSame(24, $prestamo->cuotasPagadas());
        $this->assertSame('Cuota 24 de 24', $prestamo->progreso_cuotas);
    }

    public function test_un_prestamo_cancelado_sin_tabla_de_cuotas_tambien_muestra_total_de_total(): void
    {
        $prestamo = Prestamo::factory()->cancelado()->create(['num_cuotas' => 36]);

        $this->assertSame('Cuota 36 de 36', $prestamo->progreso_cuotas);
    }

    public function test_pagar_la_ultima_cuota_cierra_el_prestamo_y_muestra_total_de_total(): void
    {
        $prestamo = $this->prestamoConTabla(3, 3_000_000);
        $this->pagarCuotas($prestamo, 3, Usuario::factory()->create());

        $this->assertSame(EstadoPrestamo::Cancelado, $prestamo->fresh()->estado);
        $this->assertSame('Cuota 3 de 3', $prestamo->fresh()->progreso_cuotas);
    }

    public function test_despues_de_reliquidar_cambia_el_total_y_se_conservan_las_pagadas(): void
    {
        $actor = Usuario::factory()->create();
        $prestamo = $this->prestamoConTabla(36);
        $this->pagarCuotas($prestamo, 2, $actor);

        app(ReliquidacionService::class)->reliquidar(
            $prestamo,
            3_000_000,
            MotivoReliquidacion::AbonoExtraordinario,
            'plazo',
            '2026-02-10',
            $actor,
        );

        $prestamo = $prestamo->fresh();

        $this->assertLessThan(36, $prestamo->num_cuotas);
        $this->assertSame(2, $prestamo->cuotasPagadas());
        $this->assertSame("Cuota 2 de {$prestamo->num_cuotas}", $prestamo->progreso_cuotas);
        $this->assertSame($prestamo->num_cuotas, $prestamo->cuotas()->count());
    }

    public function test_usa_las_cuotas_ya_cargadas_sin_otra_consulta(): void
    {
        $prestamo = $this->prestamoConTabla(12);
        $this->pagarCuotas($prestamo, 4, Usuario::factory()->create());

        $cargado = Prestamo::with('cuotas')->findOrFail($prestamo->id_prestamo);
        $cuotasEnMemoria = $cargado->cuotas->where('estado', EstadoCuota::Pagada)->count();

        $this->assertSame($cuotasEnMemoria, $cargado->cuotasPagadas());
    }

    public function test_el_detalle_del_prestamo_muestra_la_cuenta(): void
    {
        $this->actingAs(Usuario::factory()->create());
        $prestamo = $this->prestamoConTabla(36);
        $this->pagarCuotas($prestamo, 2, Usuario::factory()->create());

        $this->get(route('prestamos.show', $prestamo))
            ->assertOk()
            ->assertSee('Cuotas pagadas')
            ->assertSee('Cuota 2 de 36');
    }

    public function test_el_simulador_muestra_la_cuenta_en_el_listado_de_prestamos_del_empleado(): void
    {
        $this->actingAs(Usuario::factory()->create());
        $prestamo = $this->prestamoConTabla(36);
        $this->pagarCuotas($prestamo, 2, Usuario::factory()->create());

        $this->getJson(route('simulador.empleado', $prestamo->cedula_empleado))
            ->assertOk()
            ->assertJsonPath('prestamos.0.progreso_cuotas', 'Cuota 2 de 36');

        $this->get(route('simulador.index'))->assertOk()->assertSee('Cuotas pagadas');
    }
}
