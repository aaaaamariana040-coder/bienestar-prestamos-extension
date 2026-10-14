<?php

namespace Tests\Feature;

use App\Enums\EstadoCuota;
use App\Enums\EstadoPrestamo;
use App\Enums\EstadoProcesoQuincena;
use App\Enums\OrigenPago;
use App\Models\Auditoria;
use App\Models\Cuota;
use App\Models\Pago;
use App\Models\Prestamo;
use App\Models\ProcesoQuincena;
use App\Models\Usuario;
use App\Services\QuincenaService;
use Database\Seeders\SistemaUsuarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class QuincenaServiceTest extends TestCase
{
    use RefreshDatabase;

    private const ANIO = 2026;

    /** Primera quincena de octubre de 2026: del 1 al 15. */
    private const QUINCENA = 19;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-20 10:00:00');
        $this->seed(SistemaUsuarioSeeder::class);
        config([
            'quincena.regla_vencimiento' => 'calendario',
            'quincena.modo_marcado' => 'confirmar',
        ]);
    }

    public function test_la_simulacion_no_escribe_nada_en_la_base_de_datos(): void
    {
        $cuota = $this->cuotaQueVence('2026-10-15');
        $pagos = Pago::count();
        $procesos = ProcesoQuincena::count();
        $auditorias = Auditoria::count();

        $resultado = $this->servicio()->procesar(self::ANIO, self::QUINCENA);

        $this->assertTrue($resultado['simulacion']);
        $this->assertCount(1, $resultado['cuotas']);
        $this->assertSame($cuota->id_cuota, $resultado['cuotas'][0]['id_cuota']);
        $this->assertSame((float) $cuota->valor_total, $resultado['total']);
        $this->assertSame($pagos, Pago::count());
        $this->assertSame($procesos, ProcesoQuincena::count());
        $this->assertSame($auditorias, Auditoria::count());
        $this->assertSame(EstadoCuota::Pendiente, $cuota->fresh()->estado);
    }

    public function test_el_proceso_real_con_confirmar_registra_las_cuotas_y_no_crea_pagos(): void
    {
        $cuota = $this->cuotaQueVence('2026-10-15');

        $resultado = $this->servicio()->procesar(self::ANIO, self::QUINCENA, false);

        $this->assertSame(0, Pago::count());
        $this->assertSame(0, $resultado['pagos_creados']);
        $this->assertSame(EstadoCuota::Pendiente, $cuota->fresh()->estado);

        $proceso = ProcesoQuincena::firstOrFail();
        $this->assertSame(EstadoProcesoQuincena::PendienteConfirmacion, $proceso->estado);
        $this->assertSame('confirmar', $proceso->modo_marcado->value);
        $this->assertSame(Usuario::CEDULA_SISTEMA, $proceso->cedula_usu);
        $this->assertSame([$cuota->id_cuota], array_column($proceso->resumen['cuotas'], 'id_cuota'));
    }

    public function test_el_proceso_real_con_automatico_crea_pagos_automaticos_a_nombre_de_sistema(): void
    {
        config(['quincena.modo_marcado' => 'automatico']);
        $cuota = $this->cuotaQueVence('2026-10-15');

        $resultado = $this->servicio()->procesar(self::ANIO, self::QUINCENA, false);

        $this->assertSame(1, $resultado['pagos_creados']);
        $pago = Pago::firstOrFail();
        $this->assertSame(OrigenPago::Automatico, $pago->origen);
        $this->assertSame(Usuario::CEDULA_SISTEMA, $pago->cedula_usu);
        $this->assertSame($cuota->id_cuota, $pago->id_cuota);
        $this->assertSame('2026-10-15', $pago->fecha_pago->toDateString());
        $this->assertSame(EstadoCuota::Pagada, $cuota->fresh()->estado);
        $this->assertSame(EstadoProcesoQuincena::Completado, ProcesoQuincena::firstOrFail()->estado);
    }

    public function test_ejecutar_dos_veces_la_misma_quincena_no_duplica_nada(): void
    {
        config(['quincena.modo_marcado' => 'automatico']);
        $this->cuotaQueVence('2026-10-15');

        $primero = $this->servicio()->procesar(self::ANIO, self::QUINCENA, false);
        $segundo = $this->servicio()->procesar(self::ANIO, self::QUINCENA, false);

        $this->assertFalse($primero['ya_procesado']);
        $this->assertTrue($segundo['ya_procesado']);
        $this->assertSame(1, Pago::count());
        $this->assertSame(1, ProcesoQuincena::count());
        $this->assertSame($primero['proceso']->id_proceso, $segundo['proceso']->id_proceso);
    }

    public function test_ejecutar_dos_veces_en_modo_confirmar_tampoco_duplica_el_proceso(): void
    {
        $this->cuotaQueVence('2026-10-15');

        $this->servicio()->procesar(self::ANIO, self::QUINCENA, false);
        $segundo = $this->servicio()->procesar(self::ANIO, self::QUINCENA, false);

        $this->assertTrue($segundo['ya_procesado']);
        $this->assertSame(1, ProcesoQuincena::count());
        $this->assertSame(0, Pago::count());
    }

    public function test_solo_entran_prestamos_vigentes_y_en_mora(): void
    {
        $vigente = $this->cuotaQueVence('2026-10-10');
        $enMora = $this->cuotaQueVence('2026-10-11', EstadoPrestamo::EnMora);
        $cancelado = $this->cuotaQueVence('2026-10-12', EstadoPrestamo::Cancelado);

        $resultado = $this->servicio()->procesar(self::ANIO, self::QUINCENA);

        $incluidas = array_column($resultado['cuotas'], 'id_cuota');
        $this->assertEqualsCanonicalizing([$vigente->id_cuota, $enMora->id_cuota], $incluidas);
        $this->assertNotContains($cancelado->id_cuota, $incluidas);
        $this->assertCount(1, $resultado['excluidos']);
        $this->assertSame($cancelado->id_cuota, $resultado['excluidos'][0]['id_cuota']);
        $this->assertStringContainsString('Cancelado', $resultado['excluidos'][0]['motivo']);
    }

    public function test_una_cuota_ya_pagada_se_excluye_con_su_motivo(): void
    {
        $cuota = $this->cuotaQueVence('2026-10-10', EstadoPrestamo::Vigente, EstadoCuota::Pagada);

        $resultado = $this->servicio()->procesar(self::ANIO, self::QUINCENA);

        $this->assertSame([], $resultado['cuotas']);
        $this->assertSame($cuota->id_cuota, $resultado['excluidos'][0]['id_cuota']);
        $this->assertSame('La cuota ya está pagada', $resultado['excluidos'][0]['motivo']);
    }

    public function test_la_regla_calendario_asigna_la_cuota_a_la_quincena_calendario_de_su_fecha(): void
    {
        $cuota = $this->cuotaQueVence('2026-10-16', autorizacion: '2026-10-01');

        $primera = $this->servicio()->procesar(self::ANIO, self::QUINCENA);
        $segunda = $this->servicio()->procesar(self::ANIO, self::QUINCENA + 1);

        $this->assertSame([], $primera['cuotas']);
        $this->assertSame([$cuota->id_cuota], array_column($segunda['cuotas'], 'id_cuota'));
    }

    public function test_la_regla_cada_15_dias_asigna_la_cuota_al_ciclo_contado_desde_la_autorizacion(): void
    {
        config(['quincena.regla_vencimiento' => 'cada_15_dias']);
        $cuota = $this->cuotaQueVence('2026-10-16', autorizacion: '2026-10-01');

        $primera = $this->servicio()->procesar(self::ANIO, self::QUINCENA);
        $segunda = $this->servicio()->procesar(self::ANIO, self::QUINCENA + 1);

        $this->assertSame('cada_15_dias', $primera['regla_vencimiento']);
        $this->assertSame([$cuota->id_cuota], array_column($primera['cuotas'], 'id_cuota'));
        $this->assertSame([], $segunda['cuotas']);
    }

    public function test_advierte_sin_suprimir_cuando_un_prestamo_tiene_dos_cuotas_en_la_misma_quincena(): void
    {
        $prestamo = Prestamo::factory()->create(['estado' => EstadoPrestamo::Vigente]);
        $primera = $this->cuota($prestamo, 1, '2026-10-02');
        $segunda = $this->cuota($prestamo, 2, '2026-10-14');

        $resultado = $this->servicio()->procesar(self::ANIO, self::QUINCENA);

        $this->assertEqualsCanonicalizing([$primera->id_cuota, $segunda->id_cuota], array_column($resultado['cuotas'], 'id_cuota'));
        $this->assertCount(1, $resultado['advertencias']);
        $this->assertStringContainsString("#{$prestamo->id_prestamo}", $resultado['advertencias'][0]);
        $this->assertStringContainsString('2 cuotas', $resultado['advertencias'][0]);
    }

    public function test_el_proceso_real_aborta_sin_escribir_si_no_existe_el_usuario_sistema(): void
    {
        Usuario::where('cedula', Usuario::CEDULA_SISTEMA)->delete();
        $this->cuotaQueVence('2026-10-15');

        try {
            $this->servicio()->procesar(self::ANIO, self::QUINCENA, false);
            $this->fail('Debía abortar.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('SistemaUsuarioSeeder', $e->getMessage());
        }

        $this->assertSame(0, ProcesoQuincena::count());
        $this->assertNull(Usuario::find(Usuario::CEDULA_SISTEMA));
    }

    public function test_el_proceso_real_rechaza_una_quincena_que_todavia_no_empieza(): void
    {
        $this->expectException(RuntimeException::class);

        $this->servicio()->procesar(self::ANIO, 24, false);
    }

    public function test_una_configuracion_invalida_se_rechaza_en_vez_de_adivinar(): void
    {
        config(['quincena.modo_marcado' => 'quizas']);

        $this->expectException(InvalidArgumentException::class);

        $this->servicio()->procesar(self::ANIO, self::QUINCENA);
    }

    public function test_informa_las_cuotas_vencidas_de_quincenas_anteriores_sin_pagarlas(): void
    {
        config(['quincena.modo_marcado' => 'automatico']);
        $atrasada = $this->cuotaQueVence('2026-09-10', EstadoPrestamo::EnMora);
        $this->cuotaQueVence('2026-10-10');

        $resultado = $this->servicio()->procesar(self::ANIO, self::QUINCENA, false);

        $this->assertSame(['cuotas' => 1, 'prestamos' => 1], $resultado['vencidas_anteriores']);
        $this->assertSame(EstadoCuota::Pendiente, $atrasada->fresh()->estado);
        $this->assertSame(1, Pago::count());
    }

    private function servicio(): QuincenaService
    {
        return app(QuincenaService::class);
    }

    private function cuotaQueVence(
        string $fecha,
        EstadoPrestamo $estadoPrestamo = EstadoPrestamo::Vigente,
        EstadoCuota $estadoCuota = EstadoCuota::Pendiente,
        string $autorizacion = '2026-09-01',
    ): Cuota {
        $prestamo = Prestamo::factory()->create([
            'estado' => $estadoPrestamo,
            'fecha_autorizacion' => $autorizacion,
        ]);

        return $this->cuota($prestamo, 1, $fecha, $estadoCuota);
    }

    private function cuota(Prestamo $prestamo, int $numero, string $fecha, EstadoCuota $estado = EstadoCuota::Pendiente): Cuota
    {
        return Cuota::factory()->create([
            'id_prestamo' => $prestamo->id_prestamo,
            'numero_cuota' => $numero,
            'fecha_vencimiento' => $fecha,
            'valor_capital' => 80_000,
            'valor_interes' => 5_000,
            'valor_total' => 85_000,
            'estado' => $estado,
        ]);
    }
}
