<?php

namespace Tests\Feature;

use App\Models\Prestamo;
use App\Models\TipoPrestamo;
use App\Models\Usuario;
use App\Services\PrestamoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AbonoCesantiasTest extends TestCase
{
    use RefreshDatabase;

    private function crearPrestamoConTablaReal(string $nombreModalidad, float $monto = 10_000_000, int $numCuotas = 24, float $tasaAnual = 4.85): Prestamo
    {
        $prestamoService = app(PrestamoService::class);
        $tasaPeriodica = $prestamoService->tasaPeriodica($tasaAnual);
        $cuotaPeriodica = $prestamoService->calcularCuotaFrancesa($monto, $tasaPeriodica, $numCuotas);

        $tipoPrestamo = TipoPrestamo::factory()->create(['nombre' => $nombreModalidad]);
        $prestamo = Prestamo::factory()->create([
            'id_tipo' => $tipoPrestamo->id_tipo,
            'monto_aprobado' => $monto,
            'num_cuotas' => $numCuotas,
            'cuota_periodica' => $cuotaPeriodica,
        ]);

        $prestamoService->generarCuotasDesde($prestamo, $monto, $tasaPeriodica, $cuotaPeriodica, $numCuotas, 1, '2026-01-15');

        return $prestamo;
    }

    public function test_se_registra_un_abono_de_cesantias_para_un_prestamo_de_vivienda_y_siempre_reliquida(): void
    {
        $funcionario = Usuario::factory()->create();
        $prestamo = $this->crearPrestamoConTablaReal('Vivienda — compra de inmueble');
        $cuotaOriginal = (float) $prestamo->cuota_periodica;

        // Monto pequeño (menos de 10 cuotas): un abono extraordinario común no
        // reliquidaría, pero el de cesantías sí lo hace siempre (Art. 12 §6).
        $monto = round($cuotaOriginal * 3, 2);

        $this->actingAs($funcionario)->post(route('cesantias.store', $prestamo), [
            'anio' => 2025,
            'monto' => $monto,
            'fecha_abono' => '2026-02-15',
            'modo_reliquidacion' => 'plazo',
        ])->assertRedirect(route('prestamos.show', $prestamo));

        $this->assertDatabaseHas('abonos_cesantias', [
            'id_prestamo' => $prestamo->id_prestamo,
            'anio' => 2025,
            'porcentaje' => 50,
            'monto' => $monto,
        ]);

        $this->assertDatabaseHas('reliquidaciones', [
            'id_prestamo' => $prestamo->id_prestamo,
            'motivo' => 'Abono extraordinario',
            'saldo_anterior' => 10_000_000,
            'saldo_nuevo' => round(10_000_000 - $monto, 2),
        ]);
    }

    public function test_el_abono_de_cesantias_no_aplica_a_modalidades_distintas_a_vivienda(): void
    {
        $funcionario = Usuario::factory()->create();
        $prestamo = $this->crearPrestamoConTablaReal('Educación');

        $this->actingAs($funcionario)->post(route('cesantias.store', $prestamo), [
            'anio' => 2025,
            'monto' => 500_000,
            'fecha_abono' => '2026-02-15',
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertDatabaseCount('abonos_cesantias', 0);
        $this->assertDatabaseCount('reliquidaciones', 0);
    }

    public function test_invitado_es_redirigido_a_login(): void
    {
        $prestamo = Prestamo::factory()->create();

        $this->post(route('cesantias.store', $prestamo), [])->assertRedirect(route('login'));
    }
}
