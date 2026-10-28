<?php

namespace Tests\Feature;

use App\Enums\EstadoCuota;
use App\Enums\EstadoPrestamo;
use App\Models\Cuota;
use App\Models\Empleado;
use App\Models\Pago;
use App\Models\Prestamo;
use App\Models\ProcesoQuincena;
use App\Models\Usuario;
use App\Services\QuincenaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Ajustes de la jefatura (2): búsqueda en la lista de préstamos, texto del menú y ayuda,
 * hoja impresa del préstamo y quincena en curso como selección por defecto.
 */
class AjustesJefe2Test extends TestCase
{
    use RefreshDatabase;

    private Usuario $funcionario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-28 10:30:00');
        config(['quincena.regla_vencimiento' => 'calendario', 'quincena.modo_marcado' => 'confirmar']);

        $this->funcionario = Usuario::factory()->create(['nombre' => 'Laura', 'apellido' => 'Gómez']);
    }

    private function prestamoDe(string $cedula, string $nombre, array $extra = []): Prestamo
    {
        Empleado::factory()->create(['cedula' => $cedula, 'nombre_completo' => $nombre]);

        return Prestamo::factory()->create(['cedula_empleado' => $cedula, 'estado' => EstadoPrestamo::Vigente, ...$extra]);
    }

    // ───────── Búsqueda en la lista de préstamos ─────────

    private function tresPrestamos(): void
    {
        $this->prestamoDe('1000000111', 'José Ángel Muñoz');
        $this->prestamoDe('1000000222', 'Ana Gómez');
        $this->prestamoDe('2000000333', 'Pedro Pérez');
    }

    /**
     * @return array<string, array{string, list<string>, list<string>}>
     */
    public static function busquedas(): array
    {
        return [
            'nombre sin tildes ni mayúsculas' => ['jose angel', ['José Ángel Muñoz'], ['Ana Gómez', 'Pedro Pérez']],
            'nombre con otras mayúsculas y tildes' => ['PÉREZ', ['Pedro Pérez'], ['Ana Gómez', 'José Ángel Muñoz']],
            'la eñe sin tilde' => ['munoz', ['José Ángel Muñoz'], ['Ana Gómez']],
            'parte del nombre' => ['gom', ['Ana Gómez'], ['Pedro Pérez']],
            'cédula parcial' => ['1000000', ['José Ángel Muñoz', 'Ana Gómez'], ['Pedro Pérez']],
            'cédula más larga' => ['10000002', ['Ana Gómez'], ['José Ángel Muñoz', 'Pedro Pérez']],
            'cédula completa' => ['2000000333', ['Pedro Pérez'], ['Ana Gómez', 'José Ángel Muñoz']],
            'la cédula es por prefijo, no por fragmento' => ['000222', [], ['Ana Gómez']],
            'vacía muestra todos' => ['', ['José Ángel Muñoz', 'Ana Gómez', 'Pedro Pérez'], []],
        ];
    }

    /**
     * @param  list<string>  $deben
     * @param  list<string>  $noDeben
     */
    #[DataProvider('busquedas')]
    public function test_la_lista_filtra_por_nombre_y_por_cedula(string $q, array $deben, array $noDeben): void
    {
        $this->tresPrestamos();

        $respuesta = $this->actingAs($this->funcionario)->get(route('prestamos.index', ['q' => $q]))->assertOk();

        foreach ($deben as $nombre) {
            $respuesta->assertSee($nombre);
        }

        foreach ($noDeben as $nombre) {
            $respuesta->assertDontSee($nombre);
        }
    }

    public function test_sin_resultados_muestra_el_mensaje(): void
    {
        $this->tresPrestamos();

        $this->actingAs($this->funcionario)->get(route('prestamos.index', ['q' => 'zzzz']))
            ->assertOk()
            ->assertSee('No hay préstamos que coincidan con la búsqueda');
    }

    public function test_la_busqueda_funciona_por_parametro_y_por_peticion_asincrona(): void
    {
        $this->tresPrestamos();

        $html = $this->actingAs($this->funcionario)->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->get(route('prestamos.index', ['q' => 'ana']))->assertOk()->getContent();

        $this->assertStringContainsString('id="resultados-prestamos"', $html);
        $this->assertStringContainsString('Ana Gómez', $html);
        $this->assertStringNotContainsString('Pedro Pérez', $html);
        $this->assertStringContainsString('value="ana"', $html);
    }

    public function test_la_pagina_trae_la_barra_de_busqueda_la_ayuda_y_el_script_en_tiempo_real(): void
    {
        $this->actingAs($this->funcionario)->get(route('prestamos.index'))
            ->assertOk()
            ->assertSee('type="search" id="q" name="q"', false)
            ->assertSee('Buscar por cédula o nombre del empleado')
            ->assertSee('Para registrar un pago, un abono o una cancelación, busque al empleado y entre a «Ver» en su préstamo.')
            ->assertSee("fetch(url, { signal: peticion.signal, headers: { 'X-Requested-With': 'XMLHttpRequest' } })", false);
    }

    public function test_la_lista_sigue_exigiendo_el_permiso_de_ver_prestamos(): void
    {
        $this->actingAs(Usuario::factory()->conPermisos(['empleados.ver'])->create())->get(route('prestamos.index', ['q' => 'x']))->assertForbidden();
    }

    public function test_el_menu_dice_pagos_y_abonos_y_conserva_la_ruta(): void
    {
        $html = $this->actingAs($this->funcionario)->get(route('prestamos.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Pagos y abonos', $html);
        $this->assertStringNotContainsString('Registrar pago o abono', $html);
        $this->assertMatchesRegularExpression('/href="'.preg_quote(route('prestamos.index'), '/').'"[^>]*>\s*Pagos y abonos/s', $html);
    }

    // ───────── Hoja impresa del préstamo ─────────

    public function test_el_boton_imprimir_y_la_hoja_traen_los_bloques_indicados_sin_botones(): void
    {
        $prestamo = $this->prestamoDe('1000000111', 'José Ángel Muñoz');
        $prestamo->cuotas()->create([
            'numero_cuota' => 1, 'fecha_vencimiento' => '2026-11-15', 'valor_capital' => 80_000, 'valor_interes' => 5_000,
            'valor_total' => 85_000, 'estado' => EstadoCuota::Pendiente,
        ]);
        Pago::factory()->create(['id_prestamo' => $prestamo->id_prestamo, 'id_cuota' => null, 'fecha_pago' => '2026-10-20', 'monto' => 12_345]);

        $html = $this->actingAs($this->funcionario)->get(route('prestamos.show', $prestamo))->assertOk()->getContent();

        $this->assertStringContainsString('id="imprimir-prestamo"', $html);
        $this->assertStringContainsString('window.print()', $html);

        $this->assertSame(1, preg_match('/<article class="hoja-prestamo solo-impresion".*?<\/article>/s', $html, $coincidencia));
        $hoja = $coincidencia[0];

        foreach ([
            'Politécnico Colombiano Jaime Isaza Cadavid',
            'Información del préstamo',
            'José Ángel Muñoz',
            '1000000111',
            'Tipo de vinculación',
            'Modalidad',
            'Monto aprobado',
            'Plazo',
            'Tasa efectiva anual',
            'Cuota periódica',
            'Estado',
            'Fecha de desembolso',
            'Primera cuota',
            'Cierre de novedades de esa quincena',
            'N.º',
            'Fecha de vencimiento',
            'Capital',
            'Interés',
            'Valor',
            '15/11/2026',
            'Pagos y abonos registrados',
            'Desembolsos',
            'Impreso el 28/10/2026 10:30 por Laura Gómez.',
        ] as $esperado) {
            $this->assertStringContainsString($esperado, $hoja, "La hoja debe traer «{$esperado}».");
        }

        $this->assertStringContainsString('logo', $hoja);

        foreach (['<form', '<button', '<input', '<select', '<a '] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $hoja, "La hoja no debe traer «{$prohibido}».");
        }

        $this->assertStringContainsString('@page prestamo', $html);
        $this->assertStringContainsString('display: table-header-group', $html);
        $this->assertStringContainsString('position: fixed', $html);
        $this->assertStringContainsString('class="no-imprimir"', $html);

    }

    public function test_la_hoja_trae_el_aviso_de_cuota_excluida_si_aplica(): void
    {
        $this->travelTo('2026-10-20 10:00:00');
        $prestamo = Prestamo::factory()->create(['estado' => EstadoPrestamo::Vigente, 'fecha_autorizacion' => '2026-09-01']);
        $excluida = Cuota::factory()->create(['id_prestamo' => $prestamo->id_prestamo, 'numero_cuota' => 1, 'fecha_vencimiento' => '2026-10-10', 'valor_total' => 85_000]);
        $normal = Cuota::factory()->create(['id_prestamo' => $prestamo->id_prestamo, 'numero_cuota' => 2, 'fecha_vencimiento' => '2026-10-12', 'valor_total' => 85_000]);

        $this->actingAs($this->funcionario)->post(route('quincenas.confirmar', [2026, 19]), [
            'vistas' => [$excluida->id_cuota => 85_000, $normal->id_cuota => 85_000],
            'excluir' => [$excluida->id_cuota],
            'motivo' => [$excluida->id_cuota => 'Licencia no remunerada'],
        ])->assertRedirect();
        $this->assertSame(1, ProcesoQuincena::query()->confirmados()->count());

        $html = $this->actingAs($this->funcionario)->get(route('prestamos.show', $prestamo))->assertOk()->getContent();
        preg_match('/<article class="hoja-prestamo solo-impresion".*?<\/article>/s', $html, $coincidencia);

        $this->assertStringContainsString('Esta cuota no se descontó por nómina.', $coincidencia[0]);
        $this->assertStringContainsString('Licencia no remunerada', $coincidencia[0]);
    }

    public function test_sin_permiso_de_ver_el_prestamo_no_hay_boton_ni_hoja(): void
    {
        $prestamo = $this->prestamoDe('1000000111', 'José Ángel Muñoz');

        $this->actingAs(Usuario::factory()->conPermisos(['empleados.ver'])->create())->get(route('prestamos.show', $prestamo))->assertForbidden();
        $this->actingAs(Usuario::factory()->conPermisos(['prestamos.ver'])->create())->get(route('prestamos.show', $prestamo))
            ->assertOk()->assertSee('id="imprimir-prestamo"', false)->assertSee('Información del préstamo');
    }

    // ───────── Quincena en curso ─────────

    /**
     * @return array<string, array{string, int}>
     */
    public static function fechasYQuincenaEnCurso(): array
    {
        return [
            'dentro de la quincena y antes del cierre' => ['2026-10-28 10:00:00', 20],
            'el día del cierre sigue en curso' => ['2026-10-29 10:00:00', 20],
            'entre el cierre y el fin pasa a la siguiente' => ['2026-10-30 10:00:00', 21],
            'el último día de la quincena' => ['2026-10-31 10:00:00', 21],
            'el 1 de la quincena siguiente' => ['2026-11-01 10:00:00', 21],
            'primera mitad, antes del cierre' => ['2026-11-10 10:00:00', 21],
            'primera mitad, tras el cierre' => ['2026-11-14 10:00:00', 22],
        ];
    }

    #[DataProvider('fechasYQuincenaEnCurso')]
    public function test_la_quincena_en_curso_es_la_del_rango_de_hoy_o_la_siguiente_tras_el_cierre(string $ahora, int $numero): void
    {
        $this->travelTo($ahora);

        $enCurso = app(QuincenaService::class)->quincenaEnCurso();

        $this->assertSame(2026, $enCurso['anio']);
        $this->assertSame($numero, $enCurso['numero']);
    }

    public function test_el_selector_trae_seleccionada_la_quincena_en_curso_y_las_etiquetas(): void
    {
        $this->travelTo('2026-10-28 10:00:00');
        $viejo = Prestamo::factory()->create(['estado' => EstadoPrestamo::Vigente, 'fecha_autorizacion' => '2026-08-01']);
        Cuota::factory()->create(['id_prestamo' => $viejo->id_prestamo, 'numero_cuota' => 1, 'fecha_vencimiento' => '2026-09-10', 'valor_total' => 50_000]);
        Cuota::factory()->create(['id_prestamo' => $viejo->id_prestamo, 'numero_cuota' => 2, 'fecha_vencimiento' => '2026-10-23', 'valor_total' => 50_000]);

        $html = $this->actingAs($this->funcionario)->get(route('quincenas.index'))->assertOk()->getContent();

        // La quincena 20 (16 al 31 de octubre) es la que incluye hoy y viene seleccionada.
        $this->assertMatchesRegularExpression('/<option value="[^"]*quincenas\/2026\/20"[^>]*selected[^>]*>Quincena 20 de 2026 \(16\/10 al 31\/10\) — En curso<\/option>/', $html);
        $this->assertStringContainsString('Quincena 17 de 2026 (01/09 al 15/09) — Pendiente anterior', $html);
        $this->assertStringContainsString('<span class="badge text-bg-primary">En curso</span>', $html);
        $this->assertStringContainsString('<span class="badge text-bg-warning">Pendiente anterior</span>', $html);
        $this->assertStringNotContainsString('Quincena 17 de 2026 (01/09 al 15/09) — En curso', $html);
    }

    public function test_una_quincena_confirmada_se_etiqueta_confirmada_y_las_demas_siguen_disponibles(): void
    {
        $this->travelTo('2026-10-20 10:00:00');
        $prestamo = Prestamo::factory()->create(['estado' => EstadoPrestamo::Vigente, 'fecha_autorizacion' => '2026-09-01']);
        $cuota = Cuota::factory()->create(['id_prestamo' => $prestamo->id_prestamo, 'numero_cuota' => 1, 'fecha_vencimiento' => '2026-10-10', 'valor_total' => 85_000]);
        $this->actingAs($this->funcionario)->post(route('quincenas.confirmar', [2026, 19]), [
            'vistas' => [$cuota->id_cuota => 85_000],
        ])->assertRedirect();

        $html = $this->actingAs($this->funcionario)->get(route('quincenas.index'))->assertOk()->getContent();

        $this->assertStringContainsString('<span class="badge text-bg-success">Confirmada</span>', $html);
        $this->assertStringContainsString('Quincena 19 de 2026 (01/10 al 15/10) — Confirmada', $html);
        $this->assertStringContainsString('Quincena 20 de 2026 (16/10 al 31/10) — En curso', $html);
    }

    public function test_entre_el_cierre_y_el_fin_la_seleccion_es_la_siguiente_y_no_se_puede_abrir_si_no_empezo(): void
    {
        $this->travelTo('2026-10-30 10:00:00');

        $html = $this->actingAs($this->funcionario)->get(route('quincenas.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<option value="[^"]*quincenas\/2026\/21"[^>]*data-abrible="0"[^>]*selected[^>]*>Quincena 21 de 2026 \(01\/11 al 15\/11\) — En curso<\/option>/', $html);
    }

    public function test_el_estado_de_las_quincenas_no_cambia_las_reglas_de_confirmacion(): void
    {
        $this->actingAs($this->funcionario)->get(route('quincenas.vista-previa', [2026, 22]))->assertRedirect(route('quincenas.index'));
        $this->assertSame(0, ProcesoQuincena::count());
    }
}
