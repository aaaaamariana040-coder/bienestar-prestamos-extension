<?php

namespace Tests\Feature;

use App\Enums\MotivoReliquidacion;
use App\Models\Empleado;
use App\Models\Prestamo;
use App\Models\TasaDtf;
use App\Models\Usuario;
use App\Services\PrestamoService;
use App\Services\RetiroEmpleadoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class RetiroEmpleadoTest extends TestCase
{
    use RefreshDatabase;

    private function crearPrestamoConCuotas(Empleado $empleado, float $monto = 6_000_000, int $numCuotas = 12): Prestamo
    {
        $prestamoService = app(PrestamoService::class);
        $tasaPeriodica = $prestamoService->tasaPeriodica(4.85);
        $cuota = $prestamoService->calcularCuotaFrancesa($monto, $tasaPeriodica, $numCuotas);

        $prestamo = Prestamo::factory()->create([
            'cedula_empleado' => $empleado->cedula,
            'monto_aprobado' => $monto,
            'num_cuotas' => $numCuotas,
            'cuota_periodica' => $cuota,
        ]);

        $prestamoService->generarCuotasDesde($prestamo, $monto, $tasaPeriodica, $cuota, $numCuotas, 1, '2026-11-15');

        return $prestamo;
    }

    public function test_el_endpoint_marca_al_empleado_como_retirado(): void
    {
        $actor = Usuario::factory()->create();
        $empleado = Empleado::factory()->create();

        $this->actingAs($actor)
            ->post(route('empleados.retiro', $empleado), ['fecha_retiro' => '2026-10-02'])
            ->assertRedirect(route('empleados.show', $empleado));

        $this->assertDatabaseHas('empleados', ['cedula' => $empleado->cedula, 'estado' => 'Retirado']);
    }

    public function test_crea_una_reliquidacion_con_motivo_retiro_por_cada_prestamo_con_saldo(): void
    {
        $actor = Usuario::factory()->create();
        $empleado = Empleado::factory()->create();
        $prestamo = $this->crearPrestamoConCuotas($empleado);
        $this->crearPrestamoConCuotas(Empleado::factory()->create());

        $this->actingAs($actor)
            ->post(route('empleados.retiro', $empleado), ['fecha_retiro' => '2026-10-02']);

        $this->assertDatabaseCount('reliquidaciones', 1);

        $reliquidacion = $prestamo->reliquidaciones()->firstOrFail();
        $this->assertSame(MotivoReliquidacion::Retiro, $reliquidacion->motivo);
        $this->assertEqualsWithDelta(6_000_000, (float) $reliquidacion->saldo_anterior, 0.05);
        $this->assertNull($reliquidacion->saldo_nuevo);
        $this->assertNull($reliquidacion->tasa_aplicada);
        $this->assertSame($actor->cedula, $reliquidacion->cedula_usu);
    }

    public function test_no_modifica_las_cuotas_del_prestamo(): void
    {
        $actor = Usuario::factory()->create();
        $empleado = Empleado::factory()->create();
        $prestamo = $this->crearPrestamoConCuotas($empleado);
        $antes = $prestamo->cuotas()->orderBy('numero_cuota')->get(['numero_cuota', 'fecha_vencimiento', 'valor_total'])->toArray();

        $this->actingAs($actor)
            ->post(route('empleados.retiro', $empleado), ['fecha_retiro' => '2026-10-02']);

        $this->assertSame($antes, $prestamo->cuotas()->orderBy('numero_cuota')->get(['numero_cuota', 'fecha_vencimiento', 'valor_total'])->toArray());
    }

    public function test_registra_la_operacion_en_auditoria(): void
    {
        $actor = Usuario::factory()->create();
        $empleado = Empleado::factory()->create();

        $this->actingAs($actor)
            ->post(route('empleados.retiro', $empleado), ['fecha_retiro' => '2026-10-02']);

        $this->assertDatabaseHas('auditorias', [
            'cedula_usu' => $actor->cedula,
            'funcion_realizada' => 'Retiro de empleado',
        ]);
    }

    public function test_un_empleado_ya_retirado_no_se_puede_retirar_otra_vez(): void
    {
        $actor = Usuario::factory()->create();
        $empleado = Empleado::factory()->retirado()->create();

        $this->actingAs($actor)
            ->post(route('empleados.retiro', $empleado), ['fecha_retiro' => '2026-10-02'])
            ->assertSessionHas('error');

        $this->assertDatabaseCount('reliquidaciones', 0);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_exige_fecha_de_retiro(): void
    {
        $actor = Usuario::factory()->create();
        $empleado = Empleado::factory()->create();

        $this->actingAs($actor)
            ->post(route('empleados.retiro', $empleado), [])
            ->assertSessionHasErrors('fecha_retiro');

        $this->assertDatabaseHas('empleados', ['cedula' => $empleado->cedula, 'estado' => 'Activo']);
    }

    public function test_el_calculo_de_liquidacion_por_retiro_lanza_excepcion_explicita(): void
    {
        $prestamo = Prestamo::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cálculo de liquidación por retiro aún no implementado');

        app(RetiroEmpleadoService::class)->liquidarSaldoPorRetiro($prestamo);
    }

    public function test_la_tabla_tasas_dtf_existe_y_arranca_vacia(): void
    {
        $this->assertDatabaseCount('tasas_dtf', 0);

        $tasa = TasaDtf::factory()->create(['fecha_vigencia' => '2026-01-01', 'valor' => 9.1234]);

        $this->assertDatabaseHas('tasas_dtf', ['id_tasa_dtf' => $tasa->id_tasa_dtf, 'fuente_normativa' => 'Superfinanciera']);
    }
}
