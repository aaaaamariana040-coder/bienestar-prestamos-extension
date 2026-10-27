<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Empleado;
use App\Models\Prestamo;
use App\Models\TasaDtf;
use App\Models\Usuario;
use App\Services\DtfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Pantalla «DTF»: captura y consulta del DTF trimestre anticipado. Nada lo
 * consume todavía (la liquidación por retiro queda para después).
 */
class DtfTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Usuario
    {
        return Usuario::factory()->administrador()->create();
    }

    private function dtf(string $fecha, float $nominal = 9.81, ?string $fuente = null): TasaDtf
    {
        return app(DtfService::class)->crear(['fecha_vigencia' => $fecha, 'dtf_nominal' => $nominal, 'fuente_normativa' => $fuente], $this->admin());
    }

    // ───────── Cálculo ─────────

    public function test_nominal_9_81_da_efectivo_anual_10_44(): void
    {
        $servicio = app(DtfService::class);

        $this->assertEqualsWithDelta(10.4423, $servicio->efectivoAnualDesdeNominal(9.81), 0.00005);
        $this->assertSame('10,44', $this->dtf('2026-01-01', 9.81)->efectivo_legible);
    }

    #[DataProvider('valoresDeControl')]
    public function test_otros_valores_de_control(float $nominal, float $efectivo): void
    {
        $this->assertEqualsWithDelta($efectivo, app(DtfService::class)->efectivoAnualDesdeNominal($nominal), 0.0001);
    }

    /**
     * @return array<string, array{float, float}>
     */
    public static function valoresDeControl(): array
    {
        return [
            'cero' => [0.0, 0.0],
            'cuatro' => [4.0, 4.1020],
            'ocho' => [8.0, 8.4166],
            'doce' => [12.0, 12.9570],
        ];
    }

    // ───────── Pantalla ─────────

    public function test_el_listado_va_de_la_fecha_mas_reciente_a_la_mas_antigua_con_dos_decimales(): void
    {
        $this->dtf('2025-01-01', 8.0, 'Fuente antigua');
        $this->dtf('2026-06-01', 9.81, 'Banco de la República, semana del 1 de junio');

        $this->actingAs($this->admin())->get(route('dtf.index'))
            ->assertOk()
            ->assertSeeInOrder(['2026-06-01', '9,81', '10,44', 'Banco de la República', '2025-01-01', '8,00', '8,42'])
            ->assertSee('Registrar un DTF');
    }

    public function test_sin_registros_el_listado_lo_dice(): void
    {
        $this->actingAs($this->admin())->get(route('dtf.index'))->assertOk()->assertSee('No hay DTF registrados.');
    }

    public function test_registrar_guarda_el_efectivo_calculado_y_deja_auditoria(): void
    {
        $actor = $this->admin();

        $this->actingAs($actor)->post(route('dtf.store'), ['fecha_vigencia' => '2026-03-01', 'dtf_nominal' => '9,81', 'fuente_normativa' => 'Banco de la República'])
            ->assertRedirect(route('dtf.index'))
            ->assertSessionHas('status', 'DTF registrado correctamente.');

        $dtf = TasaDtf::sole();
        $this->assertSame('2026-03-01', $dtf->fecha_vigencia->toDateString());
        $this->assertEquals(9.81, $dtf->dtf_nominal);
        $this->assertEqualsWithDelta(10.4423, (float) $dtf->valor, 0.0001);

        $auditoria = Auditoria::where('funcion_realizada', 'Registro de DTF')->sole();
        $this->assertSame($actor->cedula, $auditoria->cedula_usu);
        $this->assertSame('DTF vigente desde 2026-03-01: anterior sin registro → nuevo vigencia 2026-03-01, nominal 9,81 %, efectivo anual 10,44 %, Banco de la República', $auditoria->detalle);
    }

    public function test_el_registro_acepta_punto_y_coma_decimal_y_la_fuente_es_opcional(): void
    {
        $actor = $this->admin();

        $this->actingAs($actor)->post(route('dtf.store'), ['fecha_vigencia' => '2026-03-01', 'dtf_nominal' => '9.81'])->assertSessionHasNoErrors();
        $this->actingAs($actor)->post(route('dtf.store'), ['fecha_vigencia' => '2026-04-01', 'dtf_nominal' => '9,8125'])->assertSessionHasNoErrors();

        $this->assertSame(2, TasaDtf::count());
        $this->assertNull(TasaDtf::where('fecha_vigencia', '2026-03-01')->sole()->fuente_normativa);
    }

    public function test_el_nominal_cero_se_acepta_y_da_efectivo_cero(): void
    {
        $this->actingAs($this->admin())->post(route('dtf.store'), ['fecha_vigencia' => '2026-03-01', 'dtf_nominal' => '0'])->assertSessionHasNoErrors();

        $this->assertEquals(0, TasaDtf::sole()->valor);
    }

    #[DataProvider('nominalesInvalidos')]
    public function test_un_nominal_invalido_se_rechaza_con_mensaje_en_espanol(string $valor, string $mensaje): void
    {
        $this->actingAs($this->admin())->from(route('dtf.index'))
            ->post(route('dtf.store'), ['fecha_vigencia' => '2026-03-01', 'dtf_nominal' => $valor])
            ->assertRedirect(route('dtf.index'))
            ->assertSessionHasErrors(['dtf_nominal' => $mensaje]);

        $this->assertSame(0, TasaDtf::count());
        $this->assertSame(0, Auditoria::count());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function nominalesInvalidos(): array
    {
        return [
            'negativo' => ['-1', 'El DTF nominal no puede ser negativo.'],
            'cien' => ['100', 'El DTF nominal debe ser menor que 100 %.'],
            'mayor que cien' => ['150', 'El DTF nominal debe ser menor que 100 %.'],
            'texto' => ['abc', 'El DTF nominal debe ser un número (por ejemplo 9,81).'],
            'vacío' => ['', 'Escribe el DTF nominal trimestre anticipado.'],
            'demasiados decimales' => ['9,81234', 'El DTF nominal puede tener hasta 4 decimales.'],
        ];
    }

    public function test_noventa_y_nueve_con_nueve_nueve_se_acepta_sin_dividir_por_cero(): void
    {
        $this->actingAs($this->admin())->post(route('dtf.store'), ['fecha_vigencia' => '2026-03-01', 'dtf_nominal' => '99,9999'])->assertSessionHasNoErrors();

        $this->assertNotNull(TasaDtf::sole()->valor);
    }

    public function test_la_fecha_duplicada_o_invalida_se_rechaza(): void
    {
        $this->dtf('2026-03-01');
        $actor = $this->admin();

        $this->actingAs($actor)->post(route('dtf.store'), ['fecha_vigencia' => '2026-03-01', 'dtf_nominal' => '9'])
            ->assertSessionHasErrors(['fecha_vigencia' => 'Ya hay un DTF registrado con esa fecha de vigencia. Para cambiarlo, edita su fila de la tabla.']);

        foreach (['2026-02-30', 'no-es-fecha', '01/03/2026'] as $fecha) {
            $this->actingAs($actor)->post(route('dtf.store'), ['fecha_vigencia' => $fecha, 'dtf_nominal' => '9'])
                ->assertSessionHasErrors(['fecha_vigencia' => 'La fecha de vigencia no es una fecha válida.']);
        }

        $this->actingAs($actor)->post(route('dtf.store'), ['dtf_nominal' => '9'])
            ->assertSessionHasErrors(['fecha_vigencia' => 'Escribe la fecha desde la que aplica el DTF.']);

        $this->assertSame(1, TasaDtf::count());
    }

    public function test_los_errores_se_ven_en_pantalla_con_is_invalid_y_old(): void
    {
        $this->actingAs($this->admin())->from(route('dtf.index'))
            ->post(route('dtf.store'), ['fecha_vigencia' => '2026-03-01', 'dtf_nominal' => '150', 'fuente_normativa' => 'Mi fuente'])
            ->assertRedirect(route('dtf.index'));

        $this->actingAs($this->admin())->get(route('dtf.index'))
            ->assertOk()
            ->assertSee('El DTF nominal debe ser menor que 100 %.')
            ->assertSee('is-invalid', false)
            ->assertSee('value="150"', false)
            ->assertSee('value="Mi fuente"', false);
    }

    // ───────── Edición ─────────

    public function test_editar_recalcula_el_efectivo_y_deja_el_valor_anterior_y_el_nuevo_en_auditoria(): void
    {
        $dtf = $this->dtf('2026-03-01', 9.81, 'Fuente');
        $actor = $this->admin();

        $this->actingAs($actor)->put(route('dtf.update', $dtf), ['fecha_vigencia' => '2026-03-02', 'dtf_nominal' => '8,00', 'fuente_normativa' => 'Otra fuente'])
            ->assertRedirect(route('dtf.index'))
            ->assertSessionHas('status', 'DTF actualizado correctamente.');

        $dtf->refresh();
        $this->assertSame('2026-03-02', $dtf->fecha_vigencia->toDateString());
        $this->assertEquals(8.0, $dtf->dtf_nominal);
        $this->assertEqualsWithDelta(8.4166, (float) $dtf->valor, 0.0001);
        $this->assertSame('Otra fuente', $dtf->fuente_normativa);

        $auditoria = Auditoria::where('funcion_realizada', 'Edición de DTF')->sole();
        $this->assertSame($actor->cedula, $auditoria->cedula_usu);
        $this->assertSame('DTF: anterior vigencia 2026-03-01, nominal 9,81 %, efectivo anual 10,44 %, Fuente → nuevo vigencia 2026-03-02, nominal 8,00 %, efectivo anual 8,42 %, Otra fuente', $auditoria->detalle);
    }

    public function test_editar_sin_cambios_no_escribe_ni_audita(): void
    {
        $dtf = $this->dtf('2026-03-01', 9.81);
        $auditorias = Auditoria::count();

        $this->actingAs($this->admin())->put(route('dtf.update', $dtf), ['fecha_vigencia' => '2026-03-01', 'dtf_nominal' => '9,81'])
            ->assertSessionHas('status', 'El DTF ya tenía esos valores; no hubo cambios.');

        $this->assertSame($auditorias, Auditoria::count());
    }

    public function test_editar_conserva_su_propia_fecha_pero_rechaza_la_de_otro_y_valores_invalidos(): void
    {
        $uno = $this->dtf('2026-03-01');
        $this->dtf('2026-04-01');
        $actor = $this->admin();

        $this->actingAs($actor)->put(route('dtf.update', $uno), ['fecha_vigencia' => '2026-04-01', 'dtf_nominal' => '9'])
            ->assertSessionHasErrors(['fecha_vigencia'], null, 'edicionDtf');

        foreach (['-1', '100', '101', 'x'] as $valor) {
            $this->actingAs($actor)->put(route('dtf.update', $uno), ['fecha_vigencia' => '2026-03-01', 'dtf_nominal' => $valor])
                ->assertSessionHasErrors(['dtf_nominal'], null, 'edicionDtf');
        }

        $this->assertEquals(9.81, $uno->fresh()->dtf_nominal);
    }

    public function test_no_hay_ruta_para_borrar(): void
    {
        $dtf = $this->dtf('2026-03-01');

        $this->actingAs($this->admin())->delete('/dtf/'.$dtf->id_tasa_dtf)->assertStatus(405);
        $this->assertSame(1, TasaDtf::count());
    }

    // ───────── Permisos ─────────

    public function test_los_permisos_son_los_de_modalidades(): void
    {
        $dtf = $this->dtf('2026-03-01');
        $datos = ['fecha_vigencia' => '2026-05-01', 'dtf_nominal' => '9'];

        $sinPermiso = Usuario::factory()->conPermisos(['empleados.ver'])->create();
        $this->actingAs($sinPermiso)->get(route('dtf.index'))->assertForbidden();
        $this->actingAs($sinPermiso)->post(route('dtf.store'), $datos)->assertForbidden();
        $this->actingAs($sinPermiso)->put(route('dtf.update', $dtf), $datos)->assertForbidden();

        $soloVer = Usuario::factory()->conPermisos(['modalidades.ver'])->create();
        $this->actingAs($soloVer)->get(route('dtf.index'))->assertOk()->assertDontSee('Registrar un DTF')->assertDontSee('Guardar');
        $this->actingAs($soloVer)->post(route('dtf.store'), $datos)->assertForbidden();
        $this->actingAs($soloVer)->put(route('dtf.update', $dtf), $datos)->assertForbidden();

        $gestor = Usuario::factory()->conPermisos(['modalidades.ver', 'modalidades.gestionar'])->create();
        $this->actingAs($gestor)->post(route('dtf.store'), $datos)->assertRedirect(route('dtf.index'));

        $this->get('/dtf')->assertOk();
        auth()->logout();
        $this->get(route('dtf.index'))->assertRedirect(route('login'));
    }

    public function test_el_menu_muestra_dtf_solo_con_el_permiso(): void
    {
        $this->actingAs(Usuario::factory()->conPermisos(['modalidades.ver'])->create())->get(route('inicio'))->assertSee(route('dtf.index'));
        $this->actingAs(Usuario::factory()->conPermisos(['empleados.ver'])->create())->get(route('inicio'))->assertDontSee(route('dtf.index'));
    }

    // ───────── Servicio ─────────

    public function test_vigente_en_antes_entre_y_despues_de_los_registros(): void
    {
        $primero = $this->dtf('2025-01-01', 8.0);
        $segundo = $this->dtf('2025-07-01', 9.0);
        $tercero = $this->dtf('2026-01-01', 10.0);
        $servicio = app(DtfService::class);

        $this->assertNull($servicio->vigenteEn('2024-12-31'));
        $this->assertTrue($servicio->vigenteEn('2025-01-01')->is($primero));
        $this->assertTrue($servicio->vigenteEn('2025-06-30')->is($primero));
        $this->assertTrue($servicio->vigenteEn('2025-07-01')->is($segundo));
        $this->assertTrue($servicio->vigenteEn(now()->setDate(2025, 12, 31))->is($segundo));
        $this->assertTrue($servicio->vigenteEn('2026-01-01')->is($tercero));
        $this->assertTrue($servicio->vigenteEn('2030-05-05')->is($tercero));
    }

    public function test_vigente_en_sin_registros_devuelve_null_sin_inventar_valores(): void
    {
        $this->assertNull(app(DtfService::class)->vigenteEn('2026-01-01'));
        $this->assertNull(app(DtfService::class)->delCierreDeAnio(2026));
        $this->assertNull(app(DtfService::class)->dtfParaRetiro('2026-01-01'));
    }

    public function test_del_cierre_de_anio_usa_el_vigente_al_31_de_diciembre_anterior(): void
    {
        $this->dtf('2025-06-01', 8.0);
        $cierre = $this->dtf('2025-12-31', 9.0);
        $nuevo = $this->dtf('2026-01-01', 10.0);
        $servicio = app(DtfService::class);

        $this->assertTrue($servicio->delCierreDeAnio(2026)->is($cierre));
        $this->assertNull($servicio->delCierreDeAnio(2025));
        $this->assertTrue($servicio->delCierreDeAnio(2027)->is($nuevo));
    }

    public function test_el_criterio_configurado_decide_cual_dtf_aplica_al_retiro(): void
    {
        $this->dtf('2025-12-15', 8.0);
        $julio = $this->dtf('2026-07-01', 10.0);
        $servicio = app(DtfService::class);

        $this->assertSame('vigente_a_fecha', config('prestamos.dtf.criterio'));
        $this->assertTrue($servicio->dtfParaRetiro('2026-08-10')->is($julio));

        config(['prestamos.dtf.criterio' => 'cierre_anio_anterior']);
        $this->assertSame(8.0, (float) $servicio->dtfParaRetiro('2026-08-10')->dtf_nominal);

        config(['prestamos.dtf.criterio' => 'otro']);
        $this->expectException(InvalidArgumentException::class);
        $servicio->dtfParaRetiro('2026-08-10');
    }

    // ───────── Nada más cambia ─────────

    public function test_el_retiro_actual_sigue_igual_y_no_consume_el_dtf(): void
    {
        $this->dtf('2026-01-01');
        $empleado = Empleado::factory()->create();

        $this->actingAs($this->admin())->post(route('empleados.retiro', $empleado), ['fecha_retiro' => now()->toDateString()]);

        $this->assertSame(0, Prestamo::count());
        $this->assertSame(1, TasaDtf::count());
        $this->assertSame(0, Auditoria::where('funcion_realizada', 'like', '%DTF%')->where('funcion_realizada', '!=', 'Registro de DTF')->count());
    }
}
