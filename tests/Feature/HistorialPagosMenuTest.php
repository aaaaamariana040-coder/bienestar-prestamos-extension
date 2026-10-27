<?php

namespace Tests\Feature;

use App\Enums\EstadoProcesoQuincena;
use App\Models\ProcesoQuincena;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Historial de pagos: «Buscar» a la vista y los reportes y descargas en un solo menú.
 */
class HistorialPagosMenuTest extends TestCase
{
    use RefreshDatabase;

    private const ANIO = 2026;

    private const QUINCENA = 19;

    private function pantalla(Usuario $usuario, array $parametros = []): TestResponse
    {
        return $this->actingAs($usuario)->get(route('reportes.historial-pagos', ['anio' => self::ANIO, 'quincena' => self::QUINCENA, ...$parametros]));
    }

    public function test_el_menu_trae_los_tres_items_con_sus_destinos_a_la_derecha_de_buscar(): void
    {
        $usuario = Usuario::factory()->create();

        $this->pantalla($usuario)
            ->assertOk()
            ->assertSeeInOrder(['>Buscar</button>', 'id="boton-reportes-descargas"', 'Reportes y descargas', 'id="resumen-prestamos"', 'id="imprimir-q"', 'id="descargar-informe-quincena"'], false)
            ->assertSee('data-bs-toggle="dropdown"', false)
            ->assertSee('aria-expanded="false"', false)
            ->assertSee('href="'.route('reportes.prestamos').'">Resumen de préstamos por modalidad y estado</a>', false)
            ->assertSee('href="'.e(route('reportes.historial-pagos.imprimir', ['anio' => self::ANIO, 'quincena' => self::QUINCENA])).'">Imprimir o guardar la Q en PDF</a>', false)
            ->assertSee('target="_blank" rel="noopener"', false)
            ->assertSee('form="form-informe-quincena">Descargar informe de la quincena (CSV)</button>', false)
            ->assertSee('action="'.route('quincenas.excel', [self::ANIO, self::QUINCENA]).'"', false);
    }

    public function test_la_impresion_conserva_la_cedula_elegida(): void
    {
        $this->pantalla(Usuario::factory()->create(), ['cedula' => '123'])
            ->assertSee('href="'.e(route('reportes.historial-pagos.imprimir', ['anio' => self::ANIO, 'quincena' => self::QUINCENA, 'cedula' => '123'])).'"', false);
    }

    public function test_con_la_quincena_confirmada_el_item_descarga_el_informe_guardado(): void
    {
        $usuario = Usuario::factory()->create();
        $proceso = ProcesoQuincena::factory()->create([
            'anio' => self::ANIO, 'numero_quincena' => self::QUINCENA, 'estado' => EstadoProcesoQuincena::Completado,
            'cedula_usu' => $usuario->cedula, 'finalizado_en' => now(), 'resumen' => ['cuotas' => []],
        ]);

        $this->pantalla($usuario)
            ->assertOk()
            ->assertSee('href="'.route('quincenas.proceso.excel', $proceso).'">Descargar informe de la quincena (CSV)</a>', false)
            ->assertDontSee('form-informe-quincena');
    }

    public function test_sin_permiso_de_quincenas_no_aparece_el_informe_pero_si_los_demas_items(): void
    {
        $usuario = Usuario::factory()->conPermisos(['reportes.ver'])->create();

        $this->pantalla($usuario)
            ->assertOk()
            ->assertSee('id="resumen-prestamos"', false)
            ->assertSee('id="imprimir-q"', false)
            ->assertDontSee('id="descargar-informe-quincena"', false)
            ->assertDontSee('Descargar informe de la quincena');
    }

    public function test_solo_con_quincenas_ver_el_informe_confirmado_aparece_y_el_de_vista_previa_no(): void
    {
        $solo = Usuario::factory()->conPermisos(['reportes.ver', 'quincenas.ver'])->create();

        $this->pantalla($solo)->assertDontSee('id="descargar-informe-quincena"', false);

        $proceso = ProcesoQuincena::factory()->create([
            'anio' => self::ANIO, 'numero_quincena' => self::QUINCENA, 'estado' => EstadoProcesoQuincena::Completado,
            'cedula_usu' => $solo->cedula, 'finalizado_en' => now(), 'resumen' => ['cuotas' => []],
        ]);

        $this->pantalla($solo)->assertSee(route('quincenas.proceso.excel', $proceso), false);
    }

    public function test_los_botones_anteriores_ya_no_estan_sueltos(): void
    {
        $this->pantalla(Usuario::factory()->create())
            ->assertDontSee('btn btn-sm btn-outline-secondary no-imprimir">Resumen de préstamos', false)
            ->assertDontSee('btn btn-outline-secondary" id="imprimir-q"', false);
    }

    public function test_sin_permiso_de_reportes_no_se_entra_a_la_pantalla(): void
    {
        $this->pantalla(Usuario::factory()->conPermisos(['empleados.ver'])->create())->assertForbidden();
    }
}
