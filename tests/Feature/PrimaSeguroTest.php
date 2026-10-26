<?php

namespace Tests\Feature;

use App\Enums\ConceptoSeguro;
use App\Models\Auditoria;
use App\Models\PrimaSeguro;
use App\Models\Usuario;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pantalla «Seguros»: primas por año, con errores visibles, edición y Auditoría.
 */
class PrimaSeguroTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Usuario
    {
        return Usuario::factory()->administrador()->create();
    }

    private function prima(ConceptoSeguro $concepto = ConceptoSeguro::VidaDeudores, int $anio = 2090, float $tasa = 0.0003231): PrimaSeguro
    {
        return PrimaSeguro::factory()->create(['concepto' => $concepto, 'anio' => $anio, 'tasa' => $tasa]);
    }

    // ───────── Datos iniciales ─────────

    public function test_la_migracion_deja_las_primas_iniciales_del_ano_en_curso_y_del_anterior(): void
    {
        $this->assertSame(4, PrimaSeguro::count());

        foreach ([now()->year, now()->year - 1] as $anio) {
            $this->assertEquals(0.0003231, PrimaSeguro::where('concepto', 'vida_deudores')->where('anio', $anio)->firstOrFail()->tasa);
            $this->assertEquals(0, PrimaSeguro::where('concepto', 'incendio_terremoto')->where('anio', $anio)->firstOrFail()->tasa);
        }
    }

    public function test_el_concepto_y_el_ano_son_unicos_en_la_base(): void
    {
        $this->expectException(QueryException::class);

        $this->prima(ConceptoSeguro::VidaDeudores, now()->year);
    }

    // ───────── Listado y registro ─────────

    public function test_el_listado_muestra_las_primas_con_siete_decimales(): void
    {
        $this->actingAs($this->admin())->get(route('seguros.index'))
            ->assertOk()
            ->assertSee('Primas de seguros')
            ->assertSee('Vida de deudores')
            ->assertSee('Incendio y terremoto')
            ->assertSee('0,0003231')
            ->assertSee('0,0000000');
    }

    public function test_se_puede_registrar_la_prima_de_un_ano_y_queda_en_auditoria(): void
    {
        $actor = $this->admin();

        $this->actingAs($actor)->post(route('seguros.store'), ['concepto' => 'incendio_terremoto', 'anio' => 2090, 'tasa' => '0.00045'])
            ->assertRedirect(route('seguros.index'))
            ->assertSessionHas('status', 'Prima registrada correctamente.');

        $this->assertEquals(0.00045, PrimaSeguro::where('concepto', 'incendio_terremoto')->where('anio', 2090)->firstOrFail()->tasa);

        $auditoria = Auditoria::where('funcion_realizada', 'Registro de prima de seguro')->sole();
        $this->assertSame($actor->cedula, $auditoria->cedula_usu);
        $this->assertSame('Seguro Incendio y terremoto — año 2090: tasa anterior sin registro → nueva 0,0004500', $auditoria->detalle);
    }

    public function test_el_registro_acepta_coma_decimal(): void
    {
        $this->actingAs($this->admin())->post(route('seguros.store'), ['concepto' => 'vida_deudores', 'anio' => 2091, 'tasa' => '0,0004'])
            ->assertSessionHasNoErrors();

        $this->assertEquals(0.0004, PrimaSeguro::where('anio', 2091)->firstOrFail()->tasa);
    }

    public function test_un_duplicado_de_concepto_y_ano_muestra_el_error_en_pantalla_y_conserva_lo_digitado(): void
    {
        $this->prima(ConceptoSeguro::VidaDeudores, 2090);

        $this->actingAs($this->admin())->from(route('seguros.index'))->followingRedirects()
            ->post(route('seguros.store'), ['concepto' => 'vida_deudores', 'anio' => 2090, 'tasa' => '0,0005'])
            ->assertOk()
            ->assertSee('id="errores-prima"', false)
            ->assertSee('Ya hay una prima registrada para ese concepto y año.')
            ->assertSee('is-invalid', false)
            ->assertSee('value="0,0005"', false);

        $this->assertSame(1, PrimaSeguro::where('anio', 2090)->count());
    }

    public function test_el_mismo_ano_con_otro_concepto_si_se_puede_registrar(): void
    {
        $this->prima(ConceptoSeguro::VidaDeudores, 2090);

        $this->actingAs($this->admin())->post(route('seguros.store'), ['concepto' => 'incendio_terremoto', 'anio' => 2090, 'tasa' => '0'])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, PrimaSeguro::where('anio', 2090)->count());
    }

    public function test_una_tasa_invalida_o_negativa_se_rechaza_con_el_error_visible(): void
    {
        $casos = [
            'abc' => 'debe ser un número',
            '-0,0001' => 'debe ser al menos',
            '1,5' => 'no puede ser mayor que 1',
            '0,000123456789' => 'hasta 8 decimales',
            '' => 'obligatorio',
        ];

        foreach ($casos as $tasa => $mensaje) {
            $this->actingAs($this->admin())->from(route('seguros.index'))->followingRedirects()
                ->post(route('seguros.store'), ['concepto' => 'vida_deudores', 'anio' => 2092, 'tasa' => (string) $tasa])
                ->assertSee('id="errores-prima"', false)
                ->assertSee($mensaje);
        }

        $this->assertSame(0, PrimaSeguro::where('anio', 2092)->count());
    }

    public function test_un_concepto_inexistente_se_rechaza(): void
    {
        $this->actingAs($this->admin())->post(route('seguros.store'), ['concepto' => 'otro', 'anio' => 2092, 'tasa' => '0,0003'])
            ->assertSessionHasErrors('concepto');
    }

    // ───────── Edición ─────────

    public function test_editar_la_prima_la_cambia_y_deja_en_auditoria_el_valor_anterior_y_el_nuevo(): void
    {
        $prima = $this->prima(ConceptoSeguro::VidaDeudores, 2090, 0.0003231);
        $actor = $this->admin();

        $this->actingAs($actor)->put(route('seguros.update', $prima), ['tasa' => '0,0004'])
            ->assertRedirect(route('seguros.index'))
            ->assertSessionHas('status', 'Prima de Vida de deudores 2090 actualizada correctamente.');

        $this->assertEquals(0.0004, $prima->fresh()->tasa);

        $auditoria = Auditoria::where('funcion_realizada', 'Edición de prima de seguro')->sole();
        $this->assertSame($actor->cedula, $auditoria->cedula_usu);
        $this->assertSame('Seguro Vida de deudores — año 2090: tasa anterior 0,0003231 → nueva 0,0004000', $auditoria->detalle);
    }

    public function test_la_edicion_acepta_punto_y_coma_decimal(): void
    {
        $prima = $this->prima();

        foreach (['0.0005', '0,0006'] as $valor) {
            $this->actingAs($this->admin())->put(route('seguros.update', $prima), ['tasa' => $valor])->assertSessionHasNoErrors();
        }

        $this->assertEquals(0.0006, $prima->fresh()->tasa);
    }

    public function test_editar_no_cambia_el_concepto_ni_el_ano(): void
    {
        $prima = $this->prima(ConceptoSeguro::VidaDeudores, 2090);

        $this->actingAs($this->admin())->put(route('seguros.update', $prima), ['tasa' => '0,0004', 'concepto' => 'incendio_terremoto', 'anio' => 1999])
            ->assertSessionHasNoErrors();

        $prima->refresh();
        $this->assertEquals(0.0004, $prima->tasa);
        $this->assertSame(ConceptoSeguro::VidaDeudores, $prima->concepto);
        $this->assertSame(2090, $prima->anio);
    }

    public function test_un_error_al_editar_se_muestra_en_su_fila_y_conserva_lo_digitado(): void
    {
        $prima = $this->prima();

        $this->actingAs($this->admin())->from(route('seguros.index'))->followingRedirects()
            ->put(route('seguros.update', $prima), ['tasa' => '-3', '_prima_editada' => $prima->id_prima])
            ->assertSee('id="errores-prima"', false)
            ->assertSee('is-invalid', false)
            ->assertSee('value="-3"', false);

        $this->assertEquals(0.0003231, $prima->fresh()->tasa);
    }

    public function test_guardar_el_mismo_valor_no_escribe_ni_audita(): void
    {
        $prima = $this->prima();

        $this->actingAs($this->admin())->put(route('seguros.update', $prima), ['tasa' => '0,0003231'])
            ->assertSessionHas('status', 'La prima de Vida de deudores 2090 ya tenía ese valor; no hubo cambios.');

        $this->assertSame(0, Auditoria::count());
    }

    public function test_no_hay_borrado_de_primas(): void
    {
        $prima = $this->prima();

        $this->actingAs($this->admin())->delete('/seguros/'.$prima->id_prima)->assertStatus(405);

        $this->assertNotNull($prima->fresh());
    }

    // ───────── Permisos y menú ─────────

    public function test_el_administrador_tiene_ver_y_gestionar_por_defecto(): void
    {
        $admin = $this->admin();

        $this->assertTrue($admin->tienePermiso('seguros.ver'));
        $this->assertTrue($admin->tienePermiso('seguros.gestionar'));
    }

    public function test_el_funcionario_ve_las_primas_pero_no_las_gestiona(): void
    {
        $funcionario = Usuario::factory()->create();
        $prima = $this->prima();

        $this->assertTrue($funcionario->tienePermiso('seguros.ver'));
        $this->assertFalse($funcionario->tienePermiso('seguros.gestionar'));

        $this->actingAs($funcionario)->get(route('seguros.index'))
            ->assertOk()
            ->assertSee('0,0003231')
            ->assertDontSee('Registrar la prima de un año')
            ->assertDontSee('prima-editar-', false);

        $this->actingAs($funcionario)->post(route('seguros.store'), ['concepto' => 'vida_deudores', 'anio' => 2093, 'tasa' => '0,0003'])->assertForbidden();
        $this->actingAs($funcionario)->put(route('seguros.update', $prima), ['tasa' => '0,9'])->assertForbidden();

        $this->assertEquals(0.0003231, $prima->fresh()->tasa);
        $this->assertSame(0, PrimaSeguro::where('anio', 2093)->count());
    }

    public function test_sin_el_permiso_no_se_ve_la_pantalla_ni_el_menu(): void
    {
        $sinPermiso = Usuario::factory()->conPermisos(['empleados.ver'])->create();

        $this->actingAs($sinPermiso)->get(route('seguros.index'))->assertForbidden();

        $this->actingAs($sinPermiso)->get(route('inicio'))
            ->assertOk()
            ->assertDontSee(route('seguros.index'), false);
    }

    public function test_el_menu_muestra_seguros_a_quien_puede_verlos(): void
    {
        foreach ([Usuario::factory()->create(), $this->admin()] as $usuario) {
            $this->actingAs($usuario)->get(route('inicio'))
                ->assertOk()
                ->assertSee(route('seguros.index'), false);
        }
    }

    public function test_gestionar_exige_ver_en_las_dependencias_del_catalogo(): void
    {
        $this->assertSame(['seguros.ver'], config('permisos.dependencias')['seguros.gestionar']);
        $this->assertContains('!seguros.gestionar', config('permisos.defaults.FUNCIONARIO_BIENESTAR'));
    }
}
