<?php

namespace Tests\Feature;

use App\Enums\EstadoCuota;
use App\Enums\EstadoPrestamo;
use App\Enums\EstadoProcesoQuincena;
use App\Enums\ModoMarcado;
use App\Enums\OrigenPago;
use App\Models\Auditoria;
use App\Models\Cuota;
use App\Models\Pago;
use App\Models\Prestamo;
use App\Models\ProcesoQuincena;
use App\Models\Usuario;
use App\Services\PagoService;
use App\Services\QuincenaCambioException;
use App\Services\QuincenaService;
use App\Services\QuincenaYaConfirmadaException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ConfirmarQuincenaTest extends TestCase
{
    use RefreshDatabase;

    private const ANIO = 2026;

    /** Primera quincena de octubre de 2026: del 1 al 15 (ya terminó). */
    private const QUINCENA = 19;

    /** Segunda quincena de octubre de 2026: del 16 al 31 (la actual). */
    private const QUINCENA_ACTUAL = 20;

    private Usuario $funcionario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-20 10:00:00');
        config(['quincena.regla_vencimiento' => 'calendario', 'quincena.modo_marcado' => 'confirmar']);

        $this->funcionario = Usuario::factory()->create(['nombre' => 'Laura', 'apellido' => 'Gómez']);
    }

    // ── Vista previa ────────────────────────────────────────────────────────

    public function test_la_vista_previa_muestra_las_cuotas_y_no_escribe_nada(): void
    {
        $cuota = $this->cuotaQueVence('2026-10-10');
        $pagos = Pago::count();
        $auditorias = Auditoria::count();

        $this->actingAs($this->funcionario)
            ->get(route('quincenas.vista-previa', [self::ANIO, self::QUINCENA]))
            ->assertOk()
            ->assertSee('Confirmar quincena 19 de 2026')
            ->assertSee('85,000.00')
            ->assertSee("vistas[{$cuota->id_cuota}]", false)
            ->assertSee('Confirmar quincena</button>', false);

        $this->assertSame($pagos, Pago::count());
        $this->assertSame(0, ProcesoQuincena::count());
        $this->assertSame($auditorias, Auditoria::count());
        $this->assertSame(EstadoCuota::Pendiente, $cuota->fresh()->estado);
    }

    public function test_la_vista_previa_de_una_quincena_que_no_empezo_se_rechaza(): void
    {
        $this->actingAs($this->funcionario)
            ->get(route('quincenas.vista-previa', [self::ANIO, 24]))
            ->assertRedirect(route('quincenas.index'))
            ->assertSessionHas('error');
    }

    public function test_la_vista_previa_de_una_quincena_ya_confirmada_lleva_al_detalle(): void
    {
        $proceso = $this->procesoConfirmado(self::QUINCENA);

        $this->actingAs($this->funcionario)
            ->get(route('quincenas.vista-previa', [self::ANIO, self::QUINCENA]))
            ->assertRedirect(route('quincenas.proceso', $proceso));
    }

    public function test_la_vista_previa_advierte_dos_cuotas_del_mismo_prestamo_y_permite_excluir_una(): void
    {
        $prestamo = Prestamo::factory()->create(['estado' => EstadoPrestamo::Vigente, 'fecha_autorizacion' => '2026-09-01']);
        $primera = $this->cuota($prestamo, 1, '2026-10-02');
        $segunda = $this->cuota($prestamo, 2, '2026-10-14');

        $this->actingAs($this->funcionario)
            ->get(route('quincenas.vista-previa', [self::ANIO, self::QUINCENA]))
            ->assertOk()
            ->assertSee("El préstamo #{$prestamo->id_prestamo} tiene 2 cuotas que vencen en la misma quincena")
            ->assertSee('Puedes excluir una de ellas');

        $this->confirmar(self::QUINCENA, [$primera, $segunda], excluir: [$segunda->id_cuota => 'Se cobra en la quincena siguiente'])
            ->assertRedirect();

        $this->assertSame(EstadoCuota::Pagada, $primera->fresh()->estado);
        $this->assertSame(EstadoCuota::Pendiente, $segunda->fresh()->estado);
    }

    // ── Aviso (Inicio y menú) ───────────────────────────────────────────────

    public function test_el_aviso_aparece_en_inicio_y_en_el_menu_con_las_quincenas_iniciadas_sin_confirmar(): void
    {
        $this->cuotaQueVence('2026-10-10');
        $this->cuotaQueVence('2026-10-25');

        $respuesta = $this->actingAs($this->funcionario)->get(route('inicio'))->assertOk();

        $respuesta->assertSee('Quincenas pendientes de confirmar')
            ->assertSee('quincena 19 de 2026 (1 cuotas)')
            ->assertSee('quincena 20 de 2026 (1 cuotas)')
            ->assertSee('Ir a Quincenas')
            ->assertSee(route('quincenas.index'), false)
            ->assertSee('Quincenas pendientes de confirmar"', false);
    }

    public function test_el_aviso_lista_todas_las_quincenas_iniciadas_sin_confirmar_aunque_sean_antiguas(): void
    {
        $this->cuotaQueVence('2026-08-10');
        $this->cuotaQueVence('2026-09-20');

        $pendientes = app(QuincenaService::class)->quincenasPendientes();

        $this->assertSame([[2026, 15], [2026, 18]], array_map(fn (array $fila) => [$fila['anio'], $fila['numero']], $pendientes));
    }

    public function test_no_hay_aviso_si_la_quincena_ya_se_confirmo_o_no_tiene_cuotas_o_no_ha_empezado(): void
    {
        $this->cuotaQueVence('2026-10-10');
        $this->procesoConfirmado(self::QUINCENA);
        $this->cuotaQueVence('2026-11-10');
        $this->cuotaQueVence('2026-10-12', EstadoPrestamo::Cancelado);

        $this->assertSame([], app(QuincenaService::class)->quincenasPendientes());

        $this->actingAs($this->funcionario)->get(route('inicio'))->assertOk()->assertDontSee('Quincenas pendientes de confirmar');
    }

    public function test_el_aviso_no_se_muestra_a_una_cuenta_que_no_puede_operar(): void
    {
        $this->cuotaQueVence('2026-10-10');
        $deshabilitado = Usuario::factory()->create(['habilitado' => false]);

        $this->actingAs($deshabilitado)->get(route('inicio'))->assertRedirect(route('login'));
    }

    public function test_una_cuota_excluida_aparece_como_vencida_anterior_y_mantiene_el_aviso_solo_de_su_quincena(): void
    {
        $excluida = $this->cuotaQueVence('2026-10-10');
        $pagada = $this->cuotaQueVence('2026-10-12');

        $this->confirmar(self::QUINCENA, [$excluida, $pagada], excluir: [$excluida->id_cuota => 'Licencia no remunerada'])->assertRedirect();

        $siguiente = app(QuincenaService::class)->procesar(self::ANIO, self::QUINCENA_ACTUAL);

        $this->assertSame(['cuotas' => 1, 'prestamos' => 1], $siguiente['vencidas_anteriores']);
        $this->assertSame([], app(QuincenaService::class)->quincenasPendientes());
    }

    // ── Confirmación ────────────────────────────────────────────────────────

    public function test_confirmar_paga_las_cuotas_a_nombre_de_quien_confirma_y_registra_el_proceso(): void
    {
        $uno = $this->cuotaQueVence('2026-10-10');
        $dos = $this->cuotaQueVence('2026-10-11', EstadoPrestamo::EnMora);

        $respuesta = $this->confirmar(self::QUINCENA, [$uno, $dos]);

        $proceso = ProcesoQuincena::firstOrFail();
        $respuesta->assertRedirect(route('quincenas.proceso', $proceso));

        $this->assertSame(EstadoProcesoQuincena::Completado, $proceso->estado);
        $this->assertSame(ModoMarcado::Confirmar, $proceso->modo_marcado);
        $this->assertSame($this->funcionario->cedula, $proceso->cedula_usu);
        $this->assertSame(2, $proceso->resumen['pagos_creados']);
        $this->assertSame(170_000.0, (float) $proceso->resumen['total']);
        $this->assertSame([], $proceso->resumen['excluidas_manuales']);

        foreach ([$uno, $dos] as $cuota) {
            $this->assertSame(EstadoCuota::Pagada, $cuota->fresh()->estado);
        }

        $this->assertSame(2, Pago::count());

        foreach (Pago::all() as $pago) {
            $this->assertSame($this->funcionario->cedula, $pago->cedula_usu);
            $this->assertSame(OrigenPago::Automatico, $pago->origen);
            $this->assertSame($proceso->id_proceso, $pago->id_proceso_quincena);
            $this->assertSame('2026-10-15', $pago->fecha_pago->toDateString());
        }

        $this->assertSame(2, $proceso->pagos()->count());
    }

    public function test_confirmar_funciona_sin_el_usuario_sistema(): void
    {
        $cuota = $this->cuotaQueVence('2026-10-10');

        $this->assertNull(Usuario::find(Usuario::CEDULA_SISTEMA));

        $this->confirmar(self::QUINCENA, [$cuota])->assertRedirect();

        $this->assertSame(1, Pago::count());
    }

    public function test_la_fecha_del_pago_es_hoy_si_el_corte_de_la_quincena_aun_no_llega(): void
    {
        $cuota = $this->cuotaQueVence('2026-10-25');

        $this->confirmar(self::QUINCENA_ACTUAL, [$cuota])->assertRedirect();

        $this->assertSame('2026-10-20', Pago::firstOrFail()->fecha_pago->toDateString());
    }

    public function test_se_pueden_confirmar_las_quincenas_fuera_de_orden(): void
    {
        $anterior = $this->cuotaQueVence('2026-10-10');
        $actual = $this->cuotaQueVence('2026-10-25');

        $this->confirmar(self::QUINCENA_ACTUAL, [$actual])->assertRedirect();
        $this->confirmar(self::QUINCENA, [$anterior])->assertRedirect();

        $this->assertSame(2, ProcesoQuincena::count());
        $this->assertSame(2, Pago::count());
    }

    public function test_conserva_la_auditoria_de_cada_pago_y_agrega_la_de_la_confirmacion(): void
    {
        $uno = $this->cuotaQueVence('2026-10-10');
        $dos = $this->cuotaQueVence('2026-10-11');
        $excluida = $this->cuotaQueVence('2026-10-12');

        $this->confirmar(self::QUINCENA, [$uno, $dos, $excluida], excluir: [$excluida->id_cuota => 'Calamidad del empleado']);

        $this->assertSame(2, Auditoria::where('funcion_realizada', 'Pago de cuota')->where('cedula_usu', $this->funcionario->cedula)->count());

        $confirmacion = Auditoria::where('funcion_realizada', 'Confirmación de quincena')->sole();
        $this->assertSame($this->funcionario->cedula, $confirmacion->cedula_usu);
        $this->assertSame('2026-10-20', $confirmacion->fecha->toDateString());
        $this->assertStringContainsString('Quincena 19 de 2026', $confirmacion->detalle);
        $this->assertStringContainsString('2 cuotas pagadas por 170.000,00', $confirmacion->detalle);
        $this->assertStringContainsString('excluidas: 1', $confirmacion->detalle);
        $this->assertStringContainsString('Calamidad del empleado', $confirmacion->detalle);
    }

    public function test_pagar_la_ultima_cuota_pendiente_cierra_el_prestamo(): void
    {
        $cuota = $this->cuotaQueVence('2026-10-10');

        $this->confirmar(self::QUINCENA, [$cuota])->assertRedirect();

        $this->assertSame(EstadoPrestamo::Cancelado, $cuota->prestamo->fresh()->estado);
    }

    public function test_un_prestamo_con_mas_cuotas_pendientes_sigue_vigente(): void
    {
        $prestamo = Prestamo::factory()->create(['estado' => EstadoPrestamo::Vigente, 'fecha_autorizacion' => '2026-09-01']);
        $cuota = $this->cuota($prestamo, 1, '2026-10-10');
        $this->cuota($prestamo, 2, '2026-10-25');

        $this->confirmar(self::QUINCENA, [$cuota])->assertRedirect();

        $this->assertSame(EstadoPrestamo::Vigente, $prestamo->fresh()->estado);
    }

    // ── Exclusión ───────────────────────────────────────────────────────────

    public function test_una_cuota_excluida_con_motivo_queda_pendiente_y_el_motivo_queda_guardado(): void
    {
        $pagada = $this->cuotaQueVence('2026-10-10');
        $excluida = $this->cuotaQueVence('2026-10-11');

        $this->confirmar(self::QUINCENA, [$pagada, $excluida], excluir: [$excluida->id_cuota => '  Descuento en revisión  ']);

        $proceso = ProcesoQuincena::firstOrFail();
        $this->assertSame(EstadoCuota::Pagada, $pagada->fresh()->estado);
        $this->assertSame(EstadoCuota::Pendiente, $excluida->fresh()->estado);
        $this->assertSame(1, Pago::count());
        $this->assertSame(1, $proceso->resumen['pagos_creados']);
        $this->assertSame(85_000.0, (float) $proceso->resumen['total']);
        $this->assertSame($excluida->id_cuota, $proceso->resumen['excluidas_manuales'][0]['id_cuota']);
        $this->assertSame('Descuento en revisión', $proceso->resumen['excluidas_manuales'][0]['motivo']);
    }

    public function test_excluir_sin_motivo_se_rechaza_y_no_escribe_nada(): void
    {
        $uno = $this->cuotaQueVence('2026-10-10');
        $dos = $this->cuotaQueVence('2026-10-11');

        $this->confirmar(self::QUINCENA, [$uno, $dos], excluir: [$dos->id_cuota => '   '])
            ->assertSessionHasErrors("motivo.{$dos->id_cuota}");

        $this->assertSame(0, Pago::count());
        $this->assertSame(0, ProcesoQuincena::count());
        $this->assertSame(EstadoCuota::Pendiente, $uno->fresh()->estado);
    }

    public function test_el_servicio_tambien_exige_el_motivo_y_que_la_cuota_estuviera_en_la_vista(): void
    {
        $cuota = $this->cuotaQueVence('2026-10-10');
        $vistas = [$cuota->id_cuota => 85_000];

        try {
            $this->servicio()->confirmar(self::ANIO, self::QUINCENA, $this->funcionario, $vistas, [$cuota->id_cuota => '']);
            $this->fail('Debía exigir el motivo.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('motivo es obligatorio', $e->getMessage());
        }

        try {
            $this->servicio()->confirmar(self::ANIO, self::QUINCENA, $this->funcionario, $vistas, [999_999 => 'No estaba']);
            $this->fail('Debía rechazar la cuota ajena a la vista previa.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('no estaba en la vista previa', $e->getMessage());
        }

        $this->assertSame(0, ProcesoQuincena::count());
    }

    public function test_excluir_todas_las_cuotas_con_motivo_confirma_la_quincena_sin_pagos(): void
    {
        $cuota = $this->cuotaQueVence('2026-10-10');

        $this->confirmar(self::QUINCENA, [$cuota], excluir: [$cuota->id_cuota => 'Nómina suspendida'])->assertRedirect();

        $this->assertSame(1, ProcesoQuincena::count());
        $this->assertSame(0, Pago::count());
        $this->assertSame(EstadoCuota::Pendiente, $cuota->fresh()->estado);
    }

    // ── Doble confirmación ──────────────────────────────────────────────────

    public function test_confirmar_dos_veces_la_misma_quincena_se_rechaza_con_quien_y_cuando(): void
    {
        $cuota = $this->cuotaQueVence('2026-10-10');
        $this->confirmar(self::QUINCENA, [$cuota])->assertRedirect();

        $otra = Usuario::factory()->create(['nombre' => 'Pedro', 'apellido' => 'Ríos']);
        $respuesta = $this->actingAs($otra)->post(route('quincenas.confirmar', [self::ANIO, self::QUINCENA]), [
            'vistas' => [$cuota->id_cuota => 85_000],
        ]);

        $respuesta->assertRedirect(route('quincenas.proceso', ProcesoQuincena::firstOrFail()));
        $this->assertStringContainsString('ya fue confirmada por Laura Gómez el 20/10/2026 05:00', session('error'));
        $this->assertSame(1, Pago::count());
        $this->assertSame(1, ProcesoQuincena::count());
    }

    public function test_si_otra_confirmacion_gana_la_carrera_la_restriccion_unica_la_rechaza_sin_pagar_nada(): void
    {
        $cuota = $this->cuotaQueVence('2026-10-10');
        $otra = Usuario::factory()->create(['nombre' => 'Pedro', 'apellido' => 'Ríos']);

        // La otra persona confirmó entre la vista previa y este intento: el servicio no
        // lo sabe de antemano, así que solo la restricción única de la base lo detiene.
        $ganador = $this->procesoConfirmado(self::QUINCENA, $otra);

        try {
            $this->servicio()->confirmar(self::ANIO, self::QUINCENA, $this->funcionario, [$cuota->id_cuota => 85_000]);
            $this->fail('Debía rechazar la doble confirmación.');
        } catch (QuincenaYaConfirmadaException $e) {
            $this->assertSame($ganador->id_proceso, $e->proceso->id_proceso);
            $this->assertStringContainsString('ya fue confirmada por Pedro Ríos', $e->getMessage());
        }

        $this->assertSame(0, Pago::count());
        $this->assertSame(EstadoCuota::Pendiente, $cuota->fresh()->estado);
        $this->assertSame(1, ProcesoQuincena::count());
    }

    public function test_toma_el_proceso_pendiente_que_dejo_la_version_anterior_en_vez_de_chocar_con_el_(): void
    {
        $cuota = $this->cuotaQueVence('2026-10-10');
        $heredado = ProcesoQuincena::factory()->create([
            'anio' => self::ANIO,
            'numero_quincena' => self::QUINCENA,
            'estado' => EstadoProcesoQuincena::PendienteConfirmacion,
            'cedula_usu' => Usuario::factory()->create()->cedula,
        ]);

        $this->confirmar(self::QUINCENA, [$cuota])->assertRedirect(route('quincenas.proceso', $heredado));

        $heredado->refresh();
        $this->assertSame(EstadoProcesoQuincena::Completado, $heredado->estado);
        $this->assertSame($this->funcionario->cedula, $heredado->cedula_usu);
        $this->assertSame(1, ProcesoQuincena::count());
        $this->assertSame(1, Pago::where('id_proceso_quincena', $heredado->id_proceso)->count());
    }

    // ── Quincena no iniciada ────────────────────────────────────────────────

    public function test_confirmar_una_quincena_que_no_empezo_se_rechaza_sin_escribir_nada(): void
    {
        $cuota = $this->cuotaQueVence('2026-12-25');

        $this->actingAs($this->funcionario)
            ->post(route('quincenas.confirmar', [self::ANIO, 24]), ['vistas' => [$cuota->id_cuota => 85_000]])
            ->assertRedirect(route('quincenas.vista-previa', [self::ANIO, 24]))
            ->assertSessionHas('error');

        $this->assertStringContainsString('todavía no empieza', session('error'));
        $this->assertSame(0, ProcesoQuincena::count());
        $this->assertSame(0, Pago::count());
    }

    // ── Cambios entre la vista previa y la confirmación ─────────────────────

    public function test_un_pago_manual_entre_la_vista_previa_y_la_confirmacion_rechaza_y_muestra_la_diferencia(): void
    {
        $manual = $this->cuotaQueVence('2026-10-10');
        $otra = $this->cuotaQueVence('2026-10-11');

        app(PagoService::class)->registrarPagoCuota($manual->prestamo, $manual, '2026-10-12', $this->funcionario);

        $this->confirmar(self::QUINCENA, [$manual, $otra])
            ->assertRedirect(route('quincenas.vista-previa', [self::ANIO, self::QUINCENA]))
            ->assertSessionHas('error')
            ->assertSessionHas('diferencias', fn (array $diferencias) => count($diferencias) === 1
                && str_contains($diferencias[0], "préstamo #{$manual->id_prestamo}")
                && str_contains($diferencias[0], 'Pagada'));

        $this->assertSame(1, Pago::count());
        $this->assertSame(0, ProcesoQuincena::count());
        $this->assertSame(EstadoCuota::Pendiente, $otra->fresh()->estado);

        $this->actingAs($this->funcionario)
            ->withSession(['diferencias' => ['Una diferencia de prueba']])
            ->get(route('quincenas.vista-previa', [self::ANIO, self::QUINCENA]))
            ->assertSee('Lo que cambió desde que viste la pantalla')
            ->assertSee('Una diferencia de prueba');
    }

    public function test_una_reliquidacion_entre_la_vista_previa_y_la_confirmacion_rechaza(): void
    {
        $vista = $this->cuotaQueVence('2026-10-10');

        // La reliquidación borra las cuotas pendientes y las regenera con ids nuevos.
        $vista->delete();
        $nueva = $this->cuota($vista->prestamo, 1, '2026-10-10', valorTotal: 70_000);

        $this->confirmar(self::QUINCENA, [$vista])
            ->assertRedirect(route('quincenas.vista-previa', [self::ANIO, self::QUINCENA]))
            ->assertSessionHas('diferencias', fn (array $diferencias) => count($diferencias) === 2
                && str_contains($diferencias[0], 'reliquidó')
                && str_contains($diferencias[1], 'Apareció la cuota 1'));

        $this->assertSame(0, Pago::count());
        $this->assertSame(0, ProcesoQuincena::count());
        $this->assertSame(EstadoCuota::Pendiente, $nueva->fresh()->estado);
    }

    public function test_un_cambio_de_valor_de_la_cuota_rechaza(): void
    {
        $cuota = $this->cuotaQueVence('2026-10-10');
        $cuota->update(['valor_total' => 90_000]);

        $this->confirmar(self::QUINCENA, [$cuota], valores: [$cuota->id_cuota => 85_000])
            ->assertSessionHas('diferencias', fn (array $diferencias) => str_contains($diferencias[0], 'cambió de 85.000,00 a 90.000,00'));

        $this->assertSame(0, Pago::count());
    }

    public function test_una_cancelacion_entre_la_vista_previa_y_la_confirmacion_rechaza(): void
    {
        $cuota = $this->cuotaQueVence('2026-10-10');

        app(PagoService::class)->registrarCancelacion($cuota->prestamo, '2026-10-12', $this->funcionario);

        $this->confirmar(self::QUINCENA, [$cuota])
            ->assertRedirect(route('quincenas.vista-previa', [self::ANIO, self::QUINCENA]))
            ->assertSessionHas('diferencias');

        $this->assertSame(1, Pago::count());
        $this->assertSame(0, ProcesoQuincena::count());
    }

    public function test_un_prestamo_que_pasa_a_cancelado_sin_pagar_la_cuota_rechaza_con_su_estado(): void
    {
        $cuota = $this->cuotaQueVence('2026-10-10');
        $cuota->prestamo->update(['estado' => EstadoPrestamo::Cancelado]);

        $this->confirmar(self::QUINCENA, [$cuota])
            ->assertSessionHas('diferencias', fn (array $diferencias) => str_contains($diferencias[0], 'pasó a estado Cancelado'));
    }

    public function test_una_cuota_nueva_que_entra_a_la_quincena_rechaza(): void
    {
        $vista = $this->cuotaQueVence('2026-10-10');
        $this->cuotaQueVence('2026-10-11');

        $this->confirmar(self::QUINCENA, [$vista])
            ->assertSessionHas('diferencias', fn (array $diferencias) => count($diferencias) === 1 && str_contains($diferencias[0], 'Apareció la cuota'));

        $this->assertSame(0, Pago::count());
    }

    public function test_el_servicio_lanza_la_excepcion_de_cambio_con_las_diferencias(): void
    {
        $cuota = $this->cuotaQueVence('2026-10-10');
        $cuota->update(['estado' => EstadoCuota::Pagada]);

        try {
            $this->servicio()->confirmar(self::ANIO, self::QUINCENA, $this->funcionario, [$cuota->id_cuota => 85_000]);
            $this->fail('Debía rechazar el cambio.');
        } catch (QuincenaCambioException $e) {
            $this->assertCount(1, $e->diferencias);
        }
    }

    public function test_una_quincena_sin_cuotas_no_se_puede_confirmar(): void
    {
        $this->cuotaQueVence('2026-10-25');

        $this->confirmar(self::QUINCENA, [])->assertSessionHasErrors('vistas');

        $this->assertSame(0, ProcesoQuincena::count());
    }

    // ── Atomicidad ──────────────────────────────────────────────────────────

    public function test_si_un_pago_falla_se_revierte_todo_el_proceso(): void
    {
        $uno = $this->cuotaQueVence('2026-10-10');
        $dos = $this->cuotaQueVence('2026-10-11');
        $real = app(PagoService::class);
        $llamadas = 0;

        $falso = Mockery::mock(PagoService::class);
        $falso->shouldReceive('registrarPagoCuota')->andReturnUsing(function (...$argumentos) use ($real, &$llamadas) {
            if (++$llamadas === 2) {
                throw new RuntimeException('Falla simulada en el segundo pago');
            }

            return $real->registrarPagoCuota(...$argumentos);
        });
        $this->app->instance(PagoService::class, $falso);

        try {
            $this->servicio()->confirmar(self::ANIO, self::QUINCENA, $this->funcionario, [
                $uno->id_cuota => 85_000,
                $dos->id_cuota => 85_000,
            ]);
            $this->fail('Debía fallar.');
        } catch (RuntimeException $e) {
            $this->assertSame('Falla simulada en el segundo pago', $e->getMessage());
        }

        $this->assertSame(2, $llamadas);
        $this->assertSame(0, Pago::count());
        $this->assertSame(0, ProcesoQuincena::count());
        $this->assertSame(0, Auditoria::where('funcion_realizada', 'Confirmación de quincena')->count());
        $this->assertSame(0, Auditoria::where('funcion_realizada', 'Pago de cuota')->count());
        $this->assertSame(EstadoCuota::Pendiente, $uno->fresh()->estado);
        $this->assertSame(EstadoCuota::Pendiente, $dos->fresh()->estado);
    }

    // ── Pantallas ───────────────────────────────────────────────────────────

    public function test_el_indice_lista_pendientes_y_procesos_confirmados(): void
    {
        $this->cuotaQueVence('2026-10-25');
        $pagada = $this->cuotaQueVence('2026-10-10');
        $this->confirmar(self::QUINCENA, [$pagada]);

        $this->actingAs($this->funcionario)
            ->get(route('quincenas.index'))
            ->assertOk()
            ->assertSee('Pendientes de confirmar')
            ->assertSee('Quincena 20 de 2026')
            ->assertSee('Revisar y confirmar')
            ->assertSee('Quincenas confirmadas')
            ->assertSee('Quincena 19 de 2026')
            ->assertSee('Laura Gómez')
            ->assertSee('85,000.00');
    }

    public function test_el_detalle_muestra_quien_cuando_las_pagadas_y_las_excluidas_con_motivo(): void
    {
        $pagada = $this->cuotaQueVence('2026-10-10');
        $excluida = $this->cuotaQueVence('2026-10-11');
        $this->confirmar(self::QUINCENA, [$pagada, $excluida], excluir: [$excluida->id_cuota => 'Licencia no remunerada']);

        $this->actingAs($this->funcionario)
            ->get(route('quincenas.proceso', ProcesoQuincena::firstOrFail()))
            ->assertOk()
            ->assertSee('Quincena 19 de 2026')
            ->assertSee('Confirmada por')
            ->assertSee('Laura Gómez')
            ->assertSee('20/10/2026 05:00')
            ->assertSee('Cuotas pagadas')
            ->assertSee("#{$pagada->id_prestamo}")
            ->assertSee('Cuotas excluidas')
            ->assertSee('Licencia no remunerada');
    }

    public function test_el_detalle_de_un_proceso_automatico_indica_que_fue_el_sistema(): void
    {
        $proceso = ProcesoQuincena::factory()->create([
            'anio' => self::ANIO,
            'numero_quincena' => self::QUINCENA,
            'estado' => EstadoProcesoQuincena::Completado,
            'modo_marcado' => ModoMarcado::Automatico,
            'resumen' => ['cuotas' => [], 'total' => 0, 'pagos_creados' => 0],
        ]);

        $this->actingAs($this->funcionario)
            ->get(route('quincenas.proceso', $proceso))
            ->assertOk()
            ->assertSee('Proceso automático (Sistema)');
    }

    // ── Permisos ────────────────────────────────────────────────────────────

    public function test_el_administrador_y_el_funcionario_pueden_ver_y_confirmar(): void
    {
        $cuota = $this->cuotaQueVence('2026-10-10');
        $administrador = Usuario::factory()->administrador()->create();

        $this->actingAs($administrador)->get(route('quincenas.index'))->assertOk();
        $this->actingAs($this->funcionario)->get(route('quincenas.index'))->assertOk();

        $this->actingAs($administrador)
            ->post(route('quincenas.confirmar', [self::ANIO, self::QUINCENA]), ['vistas' => [$cuota->id_cuota => 85_000]])
            ->assertRedirect(route('quincenas.proceso', ProcesoQuincena::firstOrFail()));

        $this->assertSame($administrador->cedula, ProcesoQuincena::firstOrFail()->cedula_usu);
    }

    public function test_un_invitado_no_puede_ver_ni_confirmar(): void
    {
        $cuota = $this->cuotaQueVence('2026-10-10');

        $this->get(route('quincenas.index'))->assertRedirect(route('login'));
        $this->get(route('quincenas.vista-previa', [self::ANIO, self::QUINCENA]))->assertRedirect(route('login'));
        $this->post(route('quincenas.confirmar', [self::ANIO, self::QUINCENA]), ['vistas' => [$cuota->id_cuota => 85_000]])
            ->assertRedirect(route('login'));

        $this->assertSame(0, ProcesoQuincena::count());
        $this->assertSame(0, Pago::count());
    }

    public function test_una_cuenta_deshabilitada_no_puede_confirmar(): void
    {
        $cuota = $this->cuotaQueVence('2026-10-10');
        $deshabilitado = Usuario::factory()->create(['habilitado' => false]);

        $this->actingAs($deshabilitado)
            ->post(route('quincenas.confirmar', [self::ANIO, self::QUINCENA]), ['vistas' => [$cuota->id_cuota => 85_000]])
            ->assertRedirect(route('login'));

        $this->assertSame(0, Pago::count());
    }

    public function test_el_menu_muestra_quincenas_con_la_cantidad_pendiente(): void
    {
        $this->cuotaQueVence('2026-10-10');

        $this->actingAs($this->funcionario)
            ->get(route('quincenas.index'))
            ->assertSee('Quincenas pendientes de confirmar', false)
            ->assertSee('text-bg-warning', false);
    }

    // ── Ayudas ──────────────────────────────────────────────────────────────

    private function servicio(): QuincenaService
    {
        return app(QuincenaService::class);
    }

    /**
     * Envía la confirmación como la haría la pantalla: con lo que se vio
     * (id => valor_total) y las exclusiones marcadas (id => motivo).
     *
     * @param  list<Cuota>  $cuotasVistas
     * @param  array<int, string>  $excluir
     * @param  array<int, float>  $valores
     */
    private function confirmar(int $quincena, array $cuotasVistas, array $excluir = [], array $valores = [])
    {
        $vistas = [];

        foreach ($cuotasVistas as $cuota) {
            $vistas[$cuota->id_cuota] = $valores[$cuota->id_cuota] ?? 85_000;
        }

        return $this->actingAs($this->funcionario)->post(route('quincenas.confirmar', [self::ANIO, $quincena]), [
            'vistas' => $vistas,
            'excluir' => array_keys($excluir),
            'motivo' => $excluir,
        ]);
    }

    private function procesoConfirmado(int $quincena, ?Usuario $usuario = null): ProcesoQuincena
    {
        return ProcesoQuincena::factory()->create([
            'anio' => self::ANIO,
            'numero_quincena' => $quincena,
            'estado' => EstadoProcesoQuincena::Completado,
            'cedula_usu' => ($usuario ?? $this->funcionario)->cedula,
            'finalizado_en' => now(),
            'resumen' => ['cuotas' => [], 'total' => 0, 'pagos_creados' => 0],
        ]);
    }

    private function cuotaQueVence(string $fecha, EstadoPrestamo $estadoPrestamo = EstadoPrestamo::Vigente): Cuota
    {
        $prestamo = Prestamo::factory()->create([
            'estado' => $estadoPrestamo,
            'fecha_autorizacion' => '2026-09-01',
        ]);

        return $this->cuota($prestamo, 1, $fecha);
    }

    private function cuota(Prestamo $prestamo, int $numero, string $fecha, float $valorTotal = 85_000): Cuota
    {
        return Cuota::factory()->create([
            'id_prestamo' => $prestamo->id_prestamo,
            'numero_cuota' => $numero,
            'fecha_vencimiento' => $fecha,
            'valor_capital' => $valorTotal - 5_000,
            'valor_interes' => 5_000,
            'valor_total' => $valorTotal,
            'estado' => EstadoCuota::Pendiente,
        ]);
    }
}
