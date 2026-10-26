<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\TasaInt;
use App\Models\TipoPrestamo;
use App\Models\Usuario;
use App\Services\PrestamoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Módulo de tasas (pantalla de la modalidad): errores visibles, edición de la tasa
 * de un año ya registrado y coma decimal. El tope del 5 % de vivienda no cambia el IPC
 * registrado: se aplica al leerlo.
 */
class TasaIntEdicionTest extends TestCase
{
    use RefreshDatabase;

    private const NOTA_VIVIENDA = 'Para vivienda, desde las solicitudes del 15/11/2023 se aplica un tope del 5 % (Acuerdo 11 de 2023); aquí se registra el IPC real.';

    private function modalidad(string $nombre = 'Educación'): TipoPrestamo
    {
        return TipoPrestamo::factory()->create(['nombre' => $nombre]);
    }

    private function tasa(TipoPrestamo $modalidad, int $anio = 2026, float $valor = 5.0): TasaInt
    {
        return TasaInt::factory()->create(['id_tipo' => $modalidad->id_tipo, 'anio' => $anio, 'tasa_anual' => $valor]);
    }

    private function pantalla(Usuario $usuario, TipoPrestamo $modalidad)
    {
        return $this->actingAs($usuario)->followingRedirects()->get(route('tipos-prestamo.show', $modalidad));
    }

    // ───────── Errores visibles ─────────

    public function test_un_anio_duplicado_muestra_el_error_en_pantalla_y_conserva_lo_digitado(): void
    {
        $modalidad = $this->modalidad();
        $this->tasa($modalidad, 2026, 5);

        $this->actingAs(Usuario::factory()->create())->from(route('tipos-prestamo.show', $modalidad))->followingRedirects()
            ->post(route('tasas-int.store', $modalidad), ['anio' => 2026, 'tasa_anual' => '6,5', 'fuente_normativa' => 'DANE'])
            ->assertOk()
            ->assertSee('id="errores-tasa"', false)
            ->assertSee('Ya hay una tasa registrada para ese año en esta modalidad.')
            ->assertSee('is-invalid', false)
            ->assertSee('value="6,5"', false)
            ->assertSee('value="DANE"', false);

        $this->assertSame(1, TasaInt::where('id_tipo', $modalidad->id_tipo)->count());
    }

    public function test_una_tasa_fuera_de_rango_muestra_el_error_en_pantalla(): void
    {
        $modalidad = $this->modalidad();

        foreach (['101', '-1'] as $valor) {
            $this->actingAs(Usuario::factory()->create())->from(route('tipos-prestamo.show', $modalidad))->followingRedirects()
                ->post(route('tasas-int.store', $modalidad), ['anio' => 2026, 'tasa_anual' => $valor])
                ->assertSee('id="errores-tasa"', false)
                ->assertSee('is-invalid', false)
                ->assertSee('value="'.$valor.'"', false);
        }

        $this->assertSame(0, TasaInt::count());
    }

    public function test_un_valor_no_numerico_muestra_el_error_en_pantalla(): void
    {
        $modalidad = $this->modalidad();

        $this->actingAs(Usuario::factory()->create())->from(route('tipos-prestamo.show', $modalidad))->followingRedirects()
            ->post(route('tasas-int.store', $modalidad), ['anio' => 2026, 'tasa_anual' => 'abc'])
            ->assertSee('id="errores-tasa"', false)
            ->assertSee('debe ser un número')
            ->assertSee('value="abc"', false);

        $this->assertSame(0, TasaInt::count());
    }

    public function test_un_error_al_editar_se_muestra_en_su_fila_y_conserva_lo_digitado(): void
    {
        $modalidad = $this->modalidad();
        $tasa = $this->tasa($modalidad, 2026, 5);

        $this->actingAs(Usuario::factory()->create())->from(route('tipos-prestamo.show', $modalidad))->followingRedirects()
            ->put(route('tasas-int.update', $tasa), ['tasa_anual' => '150', '_tasa_editada' => $tasa->id_tasa])
            ->assertSee('id="errores-tasa"', false)
            ->assertSee('is-invalid', false)
            ->assertSee('value="150"', false);

        $this->assertEquals(5, $tasa->fresh()->tasa_anual);
    }

    // ───────── Edición ─────────

    public function test_editar_la_tasa_la_cambia_y_deja_en_auditoria_el_valor_anterior_y_el_nuevo(): void
    {
        $modalidad = $this->modalidad('Vivienda — construcción');
        $tasa = $this->tasa($modalidad, 2026, 5);
        $actor = Usuario::factory()->create();

        $this->actingAs($actor)->put(route('tasas-int.update', $tasa), ['tasa_anual' => '6.5'])
            ->assertRedirect(route('tipos-prestamo.show', $modalidad))
            ->assertSessionHas('status', 'Tasa de 2026 actualizada correctamente.');

        $this->assertEquals(6.5, $tasa->fresh()->tasa_anual);

        $auditoria = Auditoria::where('funcion_realizada', 'Edición de tasa de interés')->sole();
        $this->assertSame($actor->cedula, $auditoria->cedula_usu);
        $this->assertSame('Modalidad Vivienda — construcción — año 2026: tasa anterior 5,0000 % → nueva 6,5000 %', $auditoria->detalle);
    }

    public function test_la_edicion_acepta_coma_decimal(): void
    {
        $tasa = $this->tasa($this->modalidad(), 2026, 5);

        $this->actingAs(Usuario::factory()->create())->put(route('tasas-int.update', $tasa), ['tasa_anual' => '6,25'])
            ->assertSessionHasNoErrors();

        $this->assertEquals(6.25, $tasa->fresh()->tasa_anual);
    }

    public function test_el_registro_acepta_coma_decimal(): void
    {
        $modalidad = $this->modalidad();

        $this->actingAs(Usuario::factory()->create())->post(route('tasas-int.store', $modalidad), ['anio' => 2027, 'tasa_anual' => '6,5'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('tipos-prestamo.show', $modalidad));

        $this->assertEquals(6.5, TasaInt::where('id_tipo', $modalidad->id_tipo)->where('anio', 2027)->firstOrFail()->tasa_anual);
    }

    public function test_un_decimal_de_tres_cifras_no_se_lee_como_miles(): void
    {
        $tasa = $this->tasa($this->modalidad(), 2026, 5);

        $this->actingAs(Usuario::factory()->create())->put(route('tasas-int.update', $tasa), ['tasa_anual' => '5.250'])
            ->assertSessionHasNoErrors();

        $this->assertEquals(5.25, $tasa->fresh()->tasa_anual);
    }

    public function test_editar_no_cambia_el_anio_ni_la_modalidad(): void
    {
        $modalidad = $this->modalidad();
        $otra = $this->modalidad('Calamidad doméstica');
        $tasa = $this->tasa($modalidad, 2026, 5);

        $this->actingAs(Usuario::factory()->create())->put(route('tasas-int.update', $tasa), [
            'tasa_anual' => '4,5',
            'anio' => 1999,
            'id_tipo' => $otra->id_tipo,
            'fuente_normativa' => 'Otra fuente',
        ])->assertSessionHasNoErrors();

        $tasa->refresh();
        $this->assertEquals(4.5, $tasa->tasa_anual);
        $this->assertSame(2026, $tasa->anio);
        $this->assertSame($modalidad->id_tipo, $tasa->id_tipo);
        $this->assertNotSame('Otra fuente', $tasa->fuente_normativa);
    }

    public function test_guardar_el_mismo_valor_no_escribe_ni_audita(): void
    {
        $tasa = $this->tasa($this->modalidad(), 2026, 5);

        $this->actingAs(Usuario::factory()->create())->put(route('tasas-int.update', $tasa), ['tasa_anual' => '5,00'])
            ->assertSessionHas('status', 'La tasa de 2026 ya tenía ese valor; no hubo cambios.');

        $this->assertSame(0, Auditoria::where('funcion_realizada', 'Edición de tasa de interés')->count());
    }

    public function test_la_edicion_rechaza_valores_fuera_de_rango_o_no_numericos(): void
    {
        $tasa = $this->tasa($this->modalidad(), 2026, 5);
        $usuario = Usuario::factory()->create();

        foreach (['-0,1', '100,5', 'abc', ''] as $valor) {
            $this->actingAs($usuario)->put(route('tasas-int.update', $tasa), ['tasa_anual' => $valor])
                ->assertSessionHasErrorsIn('edicionTasa', 'tasa_anual');
        }

        $this->actingAs($usuario)->put(route('tasas-int.update', $tasa), ['tasa_anual' => '100'])->assertSessionHasNoErrors();
        $this->assertEquals(100, $tasa->fresh()->tasa_anual);
        $this->assertSame(1, Auditoria::where('funcion_realizada', 'Edición de tasa de interés')->count());
    }

    // ───────── Permisos ─────────

    public function test_un_usuario_sin_el_permiso_no_puede_editar_la_tasa(): void
    {
        $tasa = $this->tasa($this->modalidad(), 2026, 5);

        foreach ([Usuario::factory()->sinPermisos(), Usuario::factory()->conPermisos(['modalidades.ver'])] as $fabrica) {
            $this->actingAs($fabrica->create())->put(route('tasas-int.update', $tasa), ['tasa_anual' => '9'])->assertForbidden();
        }

        $this->assertEquals(5, $tasa->fresh()->tasa_anual);
        $this->assertSame(0, Auditoria::count());
    }

    public function test_un_usuario_deshabilitado_no_puede_editar_la_tasa(): void
    {
        $tasa = $this->tasa($this->modalidad(), 2026, 5);

        $this->actingAs(Usuario::factory()->administrador()->create(['habilitado' => false]))
            ->put(route('tasas-int.update', $tasa), ['tasa_anual' => '9'])
            ->assertRedirect(route('login'));

        $this->assertEquals(5, $tasa->fresh()->tasa_anual);
    }

    public function test_quien_solo_ve_las_modalidades_ve_la_tabla_sin_campos_de_edicion(): void
    {
        $modalidad = $this->modalidad();
        $this->tasa($modalidad, 2026, 5);

        $this->actingAs(Usuario::factory()->conPermisos(['modalidades.ver'])->create())
            ->get(route('tipos-prestamo.show', $modalidad))
            ->assertOk()
            ->assertDontSee('tasa-editar-', false)
            ->assertDontSee('Registrar tasa');
    }

    // ───────── Nota del tope y tope tras editar ─────────

    public function test_la_nota_del_tope_aparece_solo_en_las_modalidades_de_vivienda(): void
    {
        $vivienda = $this->modalidad('Vivienda — compra de inmueble');
        $educacion = $this->modalidad('Educación');
        $usuario = Usuario::factory()->create();

        $this->actingAs($usuario)->get(route('tipos-prestamo.show', $vivienda))
            ->assertOk()
            ->assertSee('id="nota-tope-vivienda"', false)
            ->assertSee(self::NOTA_VIVIENDA);

        $this->actingAs($usuario)->get(route('tipos-prestamo.show', $educacion))
            ->assertOk()
            ->assertDontSee('id="nota-tope-vivienda"', false);
    }

    public function test_el_tope_de_vivienda_se_sigue_aplicando_tras_editar_un_ipc_mayor_a_5(): void
    {
        $modalidad = $this->modalidad('Vivienda — construcción');
        $tasa = $this->tasa($modalidad, 2026, 4.8);

        $this->actingAs(Usuario::factory()->create())->put(route('tasas-int.update', $tasa), ['tasa_anual' => '6,5'])
            ->assertSessionHasNoErrors();

        // El IPC registrado es el real: 6,5 sin recortar.
        $this->assertEquals(6.5, $tasa->fresh()->tasa_anual);

        // La tasa que se lee para una solicitud actual queda en el tope.
        $detalle = app(PrestamoService::class)->tasaDetallada($modalidad, 2026, 480, true, '2026-03-01');
        $this->assertSame(5.0, $detalle['tasa']);
        $this->assertSame(6.5, $detalle['registrada']);
        $this->assertTrue($detalle['limitada']);

        // Y la pantalla de la modalidad sigue mostrando el IPC real.
        $this->get(route('tipos-prestamo.show', $modalidad))->assertSee('value="6,5"', false);
    }

    public function test_el_tope_no_toca_el_valor_guardado_al_registrar_un_ipc_mayor_a_5(): void
    {
        $modalidad = $this->modalidad('Vivienda — compra de inmueble');

        $this->actingAs(Usuario::factory()->create())->post(route('tasas-int.store', $modalidad), ['anio' => 2026, 'tasa_anual' => '6,5'])
            ->assertSessionHasNoErrors();

        $this->assertEquals(6.5, TasaInt::where('id_tipo', $modalidad->id_tipo)->firstOrFail()->tasa_anual);
    }
}
