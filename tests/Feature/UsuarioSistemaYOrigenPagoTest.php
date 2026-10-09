<?php

namespace Tests\Feature;

use App\Enums\EstadoCuota;
use App\Enums\OrigenPago;
use App\Models\Auditoria;
use App\Models\Cuota;
use App\Models\Pago;
use App\Models\Prestamo;
use App\Models\Usuario;
use App\Services\AuditoriaService;
use Database\Seeders\SistemaUsuarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class UsuarioSistemaYOrigenPagoTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRACION_ORIGEN = 'database/migrations/2026_10_09_161112_agrega_origen_a_pagos_table.php';

    public function test_el_seeder_crea_el_usuario_sistema_deshabilitado_y_es_idempotente(): void
    {
        $this->seed(SistemaUsuarioSeeder::class);
        $this->seed(SistemaUsuarioSeeder::class);

        $this->assertSame(1, Usuario::where('cedula', Usuario::CEDULA_SISTEMA)->count());

        $sistema = Usuario::sistema();
        $this->assertSame('0000000000', $sistema->cedula);
        $this->assertSame('Sistema', $sistema->nombre_completo);
        $this->assertFalse($sistema->habilitado);
        $this->assertFalse($sistema->puedeOperar());
    }

    public function test_correr_el_seeder_de_nuevo_no_cambia_la_contrasena_y_lo_deja_deshabilitado(): void
    {
        $this->seed(SistemaUsuarioSeeder::class);
        $hashOriginal = Usuario::sistema()->contrasena_hash;

        Usuario::sistema()->update(['habilitado' => true]);
        $this->seed(SistemaUsuarioSeeder::class);

        $this->assertSame($hashOriginal, Usuario::sistema()->contrasena_hash);
        $this->assertFalse(Usuario::sistema()->habilitado);
    }

    public function test_el_usuario_sistema_no_puede_iniciar_sesion(): void
    {
        $this->seed(SistemaUsuarioSeeder::class);

        $this->post(route('login'), ['cedula' => Usuario::CEDULA_SISTEMA, 'password' => 'password'])
            ->assertSessionHasErrors('cedula');

        $this->assertGuest();
    }

    public function test_aun_conociendo_la_contrasena_el_usuario_sistema_sigue_sin_poder_entrar(): void
    {
        $this->seed(SistemaUsuarioSeeder::class);
        Usuario::sistema()->update(['contrasena_hash' => 'clave-conocida-de-prueba']);

        $this->post(route('login'), ['cedula' => Usuario::CEDULA_SISTEMA, 'password' => 'clave-conocida-de-prueba'])
            ->assertSessionHasErrors('cedula');

        $this->assertGuest();
    }

    public function test_la_auditoria_registra_acciones_automaticas_a_nombre_del_sistema(): void
    {
        $this->seed(SistemaUsuarioSeeder::class);

        $auditoria = app(AuditoriaService::class)->registrarComoSistema('Proceso de prueba', 'Sin usuario con sesión');

        $this->assertSame(Usuario::CEDULA_SISTEMA, $auditoria->cedula_usu);
        $this->assertDatabaseHas('auditorias', [
            'cedula_usu' => '0000000000',
            'funcion_realizada' => 'Proceso de prueba',
            'detalle' => 'Sin usuario con sesión',
        ]);
        $this->assertSame(1, Auditoria::count());
    }

    public function test_sin_el_usuario_sistema_avisa_como_crearlo(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('db:seed --class=SistemaUsuarioSeeder');

        app(AuditoriaService::class)->registrarComoSistema('Proceso de prueba');
    }

    public function test_los_pagos_hechos_por_la_pantalla_quedan_como_manual(): void
    {
        $this->actingAs(Usuario::factory()->create());
        $prestamo = Prestamo::factory()->create(['monto_aprobado' => 10_000_000, 'num_cuotas' => 24, 'cuota_periodica' => 500_000]);
        $cuota = Cuota::factory()->create(['id_prestamo' => $prestamo->id_prestamo, 'numero_cuota' => 1, 'estado' => EstadoCuota::Pendiente]);
        Cuota::factory()->create(['id_prestamo' => $prestamo->id_prestamo, 'numero_cuota' => 2, 'estado' => EstadoCuota::Pendiente]);

        $this->post(route('pagos.store-cuota', [$prestamo, $cuota]), ['fecha_pago' => '2026-02-01'])->assertRedirect();
        $this->post(route('pagos.store-abono', $prestamo), ['monto' => 100_000, 'fecha_pago' => '2026-02-02'])->assertRedirect();
        $this->post(route('pagos.store-cancelacion', $prestamo), ['fecha_pago' => '2026-02-03'])->assertRedirect();

        $this->assertSame(3, Pago::count());
        $this->assertSame([OrigenPago::Manual], Pago::all()->pluck('origen')->unique()->values()->all());
        $this->assertSame(0, Pago::where('origen', OrigenPago::Automatico)->count());
    }

    public function test_la_migracion_del_origen_se_revierte_y_se_aplica_de_nuevo_conservando_los_pagos_como_manual(): void
    {
        $pago = Pago::factory()->create();

        $this->artisan('migrate:rollback', ['--path' => self::MIGRACION_ORIGEN, '--force' => true])->assertSuccessful();

        $this->assertFalse(Schema::hasColumn('pagos', 'origen'));
        $this->assertSame(1, Pago::count());

        $this->artisan('migrate', ['--path' => self::MIGRACION_ORIGEN, '--force' => true])->assertSuccessful();

        $this->assertTrue(Schema::hasColumn('pagos', 'origen'));
        $this->assertSame(OrigenPago::Manual, $pago->fresh()->origen);
    }

    public function test_un_pago_puede_marcarse_como_automatico(): void
    {
        $pago = Pago::factory()->create(['origen' => OrigenPago::Automatico]);

        $this->assertSame(OrigenPago::Automatico, $pago->fresh()->origen);
        $this->assertSame('Automático', $pago->fresh()->origen->value);
    }
}
