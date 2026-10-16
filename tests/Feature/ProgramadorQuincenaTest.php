<?php

namespace Tests\Feature;

use App\Enums\EstadoCuota;
use App\Models\Cuota;
use App\Models\Pago;
use App\Models\Prestamo;
use App\Models\ProcesoQuincena;
use Database\Seeders\SistemaUsuarioSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProgramadorQuincenaTest extends TestCase
{
    use RefreshDatabase;

    public function test_por_defecto_el_programador_esta_apagado_y_no_registra_el_proceso(): void
    {
        $this->assertFalse(config('quincena.programador_activo'));
        $this->assertSame([], $this->eventosDeQuincena());

        $this->seed(SistemaUsuarioSeeder::class);
        $this->cuotaQueVence('2026-10-31');
        $this->travelTo('2026-10-31 11:00:00');

        $this->artisan('schedule:run')->assertSuccessful();

        $this->assertSame(0, ProcesoQuincena::count());
        $this->assertSame(0, Pago::count());
    }

    public function test_activo_corre_el_ultimo_dia_del_mes_con_anio_y_quincena_explicitos(): void
    {
        $this->activarProgramador();
        $this->seed(SistemaUsuarioSeeder::class);
        $this->cuotaQueVence('2026-10-31');
        $this->travelTo('2026-10-31 11:00:00');

        $this->artisan('schedule:run')->assertSuccessful();

        $proceso = ProcesoQuincena::firstOrFail();
        $this->assertSame(2026, $proceso->anio);
        $this->assertSame(20, $proceso->numero_quincena);
        $this->assertSame('confirmar', $proceso->modo_marcado->value);
        $this->assertSame(0, Pago::count());
    }

    public function test_activo_no_hace_nada_en_un_dia_que_no_es_corte_de_quincena(): void
    {
        $this->activarProgramador();
        $this->seed(SistemaUsuarioSeeder::class);
        $this->cuotaQueVence('2026-10-20');
        $this->travelTo('2026-10-20 11:00:00');

        $this->artisan('schedule:run')->assertSuccessful();

        $this->assertSame(0, ProcesoQuincena::count());
    }

    private function activarProgramador(): void
    {
        config(['quincena.programador_activo' => true, 'quincena.modo_marcado' => 'confirmar']);

        require base_path('routes/console.php');

        $this->assertCount(1, $this->eventosDeQuincena());
    }

    /**
     * @return list<mixed>
     */
    private function eventosDeQuincena(): array
    {
        return collect(app(Schedule::class)->events())
            ->filter(fn ($evento) => $evento->description === 'quincena:procesar')
            ->values()
            ->all();
    }

    private function cuotaQueVence(string $fecha): Cuota
    {
        return Cuota::factory()->create([
            'id_prestamo' => Prestamo::factory()->create(['fecha_autorizacion' => '2026-09-01'])->id_prestamo,
            'numero_cuota' => 1,
            'fecha_vencimiento' => $fecha,
            'valor_capital' => 80_000,
            'valor_interes' => 5_000,
            'valor_total' => 85_000,
            'estado' => EstadoCuota::Pendiente,
        ]);
    }
}
