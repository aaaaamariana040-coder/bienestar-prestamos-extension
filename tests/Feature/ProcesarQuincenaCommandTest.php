<?php

namespace Tests\Feature;

use App\Enums\EstadoCuota;
use App\Models\Cuota;
use App\Models\Pago;
use App\Models\Prestamo;
use App\Models\ProcesoQuincena;
use App\Models\Usuario;
use Database\Seeders\SistemaUsuarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcesarQuincenaCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-20 10:00:00');
        config(['quincena.regla_vencimiento' => 'calendario', 'quincena.modo_marcado' => 'confirmar']);
    }

    public function test_por_defecto_simula_la_quincena_actual_y_no_escribe_nada(): void
    {
        $this->seed(SistemaUsuarioSeeder::class);
        $this->cuotaQueVence('2026-10-20');

        $this->artisan('quincena:procesar')
            ->expectsOutputToContain('SIMULACIÓN (no se escribió nada) — quincena 20 de 2026')
            ->expectsOutputToContain('Cuotas: 1')
            ->assertSuccessful();

        $this->assertSame(0, ProcesoQuincena::count());
        $this->assertSame(0, Pago::count());
    }

    public function test_el_modo_real_exige_anio_y_quincena_explicitos(): void
    {
        $this->seed(SistemaUsuarioSeeder::class);

        $this->artisan('quincena:procesar', ['--ejecutar' => true])
            ->expectsOutputToContain('exige indicar --anio y --quincena')
            ->assertFailed();

        $this->artisan('quincena:procesar', ['--ejecutar' => true, '--anio' => 2026])
            ->expectsOutputToContain('exige indicar --anio y --quincena')
            ->assertFailed();

        $this->assertSame(0, ProcesoQuincena::count());
    }

    public function test_el_modo_real_con_automatico_paga_e_imprime_el_resumen_con_advertencias(): void
    {
        config(['quincena.modo_marcado' => 'automatico']);
        $this->seed(SistemaUsuarioSeeder::class);
        $prestamo = Prestamo::factory()->create(['fecha_autorizacion' => '2026-09-01']);
        $this->cuota($prestamo, 1, '2026-10-02');
        $this->cuota($prestamo, 2, '2026-10-14');

        $this->artisan('quincena:procesar', ['--ejecutar' => true, '--anio' => 2026, '--quincena' => 19])
            ->expectsOutputToContain('PROCESO REAL')
            ->expectsOutputToContain('Pagos creados: 2')
            ->expectsOutputToContain('ADVERTENCIA: El préstamo')
            ->assertSuccessful();

        $this->assertSame(2, Pago::count());
    }

    public function test_el_modo_real_aborta_si_no_existe_el_usuario_sistema(): void
    {
        $this->artisan('quincena:procesar', ['--ejecutar' => true, '--anio' => 2026, '--quincena' => 19])
            ->expectsOutputToContain('SistemaUsuarioSeeder')
            ->assertFailed();

        $this->assertNull(Usuario::find(Usuario::CEDULA_SISTEMA));
        $this->assertSame(0, ProcesoQuincena::count());
    }

    public function test_rechaza_una_quincena_fuera_de_rango(): void
    {
        $this->artisan('quincena:procesar', ['--anio' => 2026, '--quincena' => 25])
            ->expectsOutputToContain('entre 1 y 24')
            ->assertFailed();
    }

    private function cuotaQueVence(string $fecha): Cuota
    {
        return $this->cuota(Prestamo::factory()->create(['fecha_autorizacion' => '2026-09-01']), 1, $fecha);
    }

    private function cuota(Prestamo $prestamo, int $numero, string $fecha): Cuota
    {
        return Cuota::factory()->create([
            'id_prestamo' => $prestamo->id_prestamo,
            'numero_cuota' => $numero,
            'fecha_vencimiento' => $fecha,
            'valor_capital' => 80_000,
            'valor_interes' => 5_000,
            'valor_total' => 85_000,
            'estado' => EstadoCuota::Pendiente,
        ]);
    }
}
