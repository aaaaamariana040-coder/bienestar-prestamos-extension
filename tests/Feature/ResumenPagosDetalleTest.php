<?php

namespace Tests\Feature;

use App\Enums\EstadoPrestamo;
use App\Enums\TipoPago;
use App\Models\Empleado;
use App\Models\Prestamo;
use App\Models\SolicitudCredito;
use App\Models\TipoPrestamo;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Resumen de pagos: lista toda modalidad con pagos en el periodo (por defecto, todos los pagos
 * registrados) y cada una tiene un detalle con los préstamos que recibieron esos pagos.
 */
class ResumenPagosDetalleTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $funcionario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-28 10:30:00');
        $this->funcionario = Usuario::factory()->create(['nombre' => 'Laura', 'apellido' => 'Gómez']);
    }

    /**
     * @param  list<array{string, float, TipoPago}>  $pagos  fecha, monto, tipo
     */
    private function prestamoConPagos(TipoPrestamo $modalidad, string $cedula, string $nombre, array $pagos, array $extra = []): Prestamo
    {
        Empleado::factory()->create(['cedula' => $cedula, 'nombre_completo' => $nombre]);
        $prestamo = Prestamo::factory()->create([
            'cedula_empleado' => $cedula,
            'id_tipo' => $modalidad->id_tipo,
            'estado' => EstadoPrestamo::Vigente,
            'monto_aprobado' => 5_000_000,
            'num_cuotas' => 24,
            'cuota_periodica' => 250_000,
            ...$extra,
        ]);

        foreach ($pagos as [$fecha, $monto, $tipo]) {
            $prestamo->pagos()->create([
                'fecha_pago' => $fecha,
                'monto' => $monto,
                'tipo' => $tipo,
                'monto_aplicado_interes' => round($monto * 0.1, 2),
                'monto_aplicado_capital' => round($monto * 0.9, 2),
                'cedula_usu' => $this->funcionario->cedula,
            ]);
        }

        return $prestamo;
    }

    private function modalidad(string $nombre): TipoPrestamo
    {
        return TipoPrestamo::factory()->create(['nombre' => $nombre]);
    }

    // ───────── Resumen ─────────

    public function test_aparecen_todas_las_modalidades_con_pagos_y_no_las_que_no_tienen(): void
    {
        $educacion = $this->modalidad('Educación');
        $vivienda = $this->modalidad('Vivienda — compra');
        $vehiculo = $this->modalidad('Vehículo');
        $this->modalidad('Calamidad sin pagos');
        $this->prestamoConPagos($educacion, '1000000001', 'Ana Uno', [['2026-08-19', 100_000, TipoPago::Regular]]);
        $this->prestamoConPagos($vivienda, '1000000002', 'Beto Dos', [['2026-10-22', 200_000, TipoPago::Regular]]);
        $this->prestamoConPagos($vehiculo, '1000000003', 'Carla Tres', [['2025-01-05', 300_000, TipoPago::Cancelacion]]);
        Prestamo::factory()->create(['id_tipo' => $this->modalidad('Sin préstamos')->id_tipo]);

        $respuesta = $this->actingAs($this->funcionario)->get(route('reportes.pagos'))->assertOk();

        $respuesta->assertSee('Educación')->assertSee('Vivienda — compra')->assertSee('Vehículo')
            ->assertDontSee('Calamidad sin pagos')->assertDontSee('Sin préstamos')
            ->assertSee('Periodo: todos los pagos registrados.');
        $this->assertSame(3, substr_count($respuesta->getContent(), 'Ver detalle'));
    }

    public function test_pagos_de_cuota_abonos_y_cancelaciones_cuentan(): void
    {
        $modalidad = $this->modalidad('Educación');
        $this->prestamoConPagos($modalidad, '1000000001', 'Ana Uno', [
            ['2026-01-10', 100_000, TipoPago::Regular],
            ['2026-02-10', 50_000, TipoPago::AbonoExtraordinario],
            ['2026-03-10', 25_000, TipoPago::Cancelacion],
        ]);

        $this->actingAs($this->funcionario)->get(route('reportes.pagos'))
            ->assertSee('175,000.00');
    }

    public function test_el_filtro_de_fechas_funciona_y_el_periodo_se_muestra(): void
    {
        $educacion = $this->modalidad('Educación');
        $vivienda = $this->modalidad('Vivienda — compra');
        $this->prestamoConPagos($educacion, '1000000001', 'Ana Uno', [['2026-01-10', 100_000, TipoPago::Regular]]);
        $this->prestamoConPagos($vivienda, '1000000002', 'Beto Dos', [['2026-06-10', 200_000, TipoPago::Regular]]);

        $respuesta = $this->actingAs($this->funcionario)->get(route('reportes.pagos', ['desde' => '2026-05-01', 'hasta' => '2026-07-01']))->assertOk();

        $respuesta->assertSee('Vivienda — compra')->assertDontSee('Educación')
            ->assertSee('Periodo: del 01/05/2026 al 01/07/2026.');
        $this->assertStringContainsString('desde=2026-05-01', $respuesta->getContent());
        $this->assertStringContainsString('hasta=2026-07-01', $respuesta->getContent());
    }

    public function test_sin_pagos_en_el_periodo_no_hay_modalidades(): void
    {
        $this->prestamoConPagos($this->modalidad('Educación'), '1000000001', 'Ana Uno', [['2026-01-10', 100_000, TipoPago::Regular]]);

        $this->actingAs($this->funcionario)->get(route('reportes.pagos', ['desde' => '2027-01-01']))
            ->assertSee('No hay pagos que coincidan con los filtros.')
            ->assertSee('Periodo: desde el 01/01/2027.');
    }

    // ───────── Detalle ─────────

    public function test_el_detalle_lista_las_personas_con_monto_cuotas_y_valor_de_la_cuota(): void
    {
        $modalidad = $this->modalidad('Educación');
        $solicitud = SolicitudCredito::factory()->create(['monto_solicitado' => 6_000_000]);
        $this->prestamoConPagos($modalidad, '1000000002', 'Óscar Zuluaga', [['2026-02-01', 100_000, TipoPago::Regular], ['2026-03-01', 50_000, TipoPago::AbonoExtraordinario]], [
            'id_solicitud' => $solicitud->id_solicitud, 'num_cuotas' => 36, 'cuota_periodica' => 180_000.50,
        ]);
        $this->prestamoConPagos($modalidad, '1000000001', 'Ana Álvarez', [['2026-02-01', 70_000, TipoPago::Regular]]);
        $otra = $this->modalidad('Vehículo');
        $this->prestamoConPagos($otra, '1000000003', 'Persona De Otra', [['2026-02-01', 999_000, TipoPago::Regular]]);

        $respuesta = $this->actingAs($this->funcionario)->get(route('reportes.pagos.detalle', $modalidad))->assertOk();
        $html = $respuesta->getContent();

        $respuesta->assertSee('Modalidad: ', false)->assertSee('Periodo: todos los pagos registrados.')
            ->assertSee('Ana Álvarez')->assertSee('Óscar Zuluaga')->assertDontSee('Persona De Otra')
            ->assertSee('$6.000.000,00')->assertSee('$5.000.000,00')->assertSee('$180.000,50')->assertSee('$250.000,00')
            ->assertSee('$150.000,00')->assertSee('$70.000,00')->assertSee('>36<', false)
            ->assertSee('Volver al resumen')->assertSee('Imprimir');

        // Ordenado por nombre.
        $this->assertLessThan(strpos($html, 'Óscar Zuluaga'), strpos($html, 'Ana Álvarez'));

        // Totales: 2 préstamos, 6.000.000 + 5.000.000 y 150.000 + 70.000 pagados.
        $this->assertMatchesRegularExpression('/id="totales-detalle".*?2 préstamos.*?\$11\.000\.000,00.*?\$220\.000,00/s', $html);
    }

    public function test_los_totales_del_detalle_coinciden_con_el_resumen(): void
    {
        $modalidad = $this->modalidad('Educación');
        $this->prestamoConPagos($modalidad, '1000000001', 'Ana Uno', [['2026-01-10', 100_000, TipoPago::Regular], ['2026-02-10', 40_000, TipoPago::AbonoExtraordinario]]);
        $this->prestamoConPagos($modalidad, '1000000002', 'Beto Dos', [['2026-01-12', 60_000, TipoPago::Regular]]);

        $this->actingAs($this->funcionario)->get(route('reportes.pagos'))->assertSee('200,000.00');
        $this->actingAs($this->funcionario)->get(route('reportes.pagos.detalle', $modalidad))->assertSee('$200.000,00');
    }

    public function test_el_detalle_respeta_el_periodo_y_dice_cuando_no_hay_datos(): void
    {
        $modalidad = $this->modalidad('Educación');
        $this->prestamoConPagos($modalidad, '1000000001', 'Ana Uno', [['2026-01-10', 100_000, TipoPago::Regular], ['2026-06-10', 30_000, TipoPago::Regular]]);

        $this->actingAs($this->funcionario)->get(route('reportes.pagos.detalle', [$modalidad, 'desde' => '2026-05-01']))
            ->assertSee('Periodo: desde el 01/05/2026.')->assertSee('$30.000,00')->assertDontSee('$130.000,00');

        $this->actingAs($this->funcionario)->get(route('reportes.pagos.detalle', [$modalidad, 'desde' => '2027-01-01']))
            ->assertSee('No hay pagos de esta modalidad en el periodo seleccionado.')
            ->assertDontSee('id="totales-detalle"', false);
    }

    public function test_el_enlace_volver_conserva_el_periodo(): void
    {
        $modalidad = $this->modalidad('Educación');
        $this->prestamoConPagos($modalidad, '1000000001', 'Ana Uno', [['2026-06-10', 30_000, TipoPago::Regular]]);

        $html = $this->actingAs($this->funcionario)->get(route('reportes.pagos.detalle', [$modalidad, 'desde' => '2026-05-01', 'hasta' => '2026-07-01']))->getContent();

        $this->assertStringContainsString('href="'.e(route('reportes.pagos', ['desde' => '2026-05-01', 'hasta' => '2026-07-01'])).'"', $html);
    }

    public function test_la_busqueda_solo_se_ofrece_con_muchas_filas_y_filtra_por_nombre_y_cedula(): void
    {
        $modalidad = $this->modalidad('Educación');

        foreach (range(1, 4) as $i) {
            $this->prestamoConPagos($modalidad, '10000000'.$i.'0', 'Persona '.chr(64 + $i), [['2026-02-01', 10_000, TipoPago::Regular]]);
        }

        $this->actingAs($this->funcionario)->get(route('reportes.pagos.detalle', $modalidad))->assertDontSee('filtros-detalle');

        foreach (range(5, 12) as $i) {
            $this->prestamoConPagos($modalidad, sprintf('2000000%03d', $i), 'Zeta Número '.chr(64 + $i), [['2026-02-01', 10_000, TipoPago::Regular]]);
        }
        $this->prestamoConPagos($modalidad, '3000000001', 'María Ñandú', [['2026-02-01', 10_000, TipoPago::Regular]]);

        $this->actingAs($this->funcionario)->get(route('reportes.pagos.detalle', $modalidad))
            ->assertSee('filtros-detalle')->assertSee('Buscar por cédula o nombre');

        $porNombre = $this->actingAs($this->funcionario)->get(route('reportes.pagos.detalle', [$modalidad, 'q' => 'MARIA nandu']))->assertOk();
        $porNombre->assertSee('María Ñandú')->assertDontSee('Zeta Número');

        $porCedula = $this->actingAs($this->funcionario)->get(route('reportes.pagos.detalle', [$modalidad, 'q' => '2000000']))->assertOk();
        $porCedula->assertSee('Zeta Número')->assertDontSee('María Ñandú');

        $this->actingAs($this->funcionario)->get(route('reportes.pagos.detalle', [$modalidad, 'q' => 'zzzz']))
            ->assertSee('No hay préstamos que coincidan con la búsqueda');
    }

    public function test_la_hoja_impresa_trae_encabezado_tabla_totales_y_pie_sin_botones(): void
    {
        $modalidad = $this->modalidad('Educación');
        $this->prestamoConPagos($modalidad, '1000000001', 'Ana Uno', [['2026-02-01', 100_000, TipoPago::Regular]]);

        $html = $this->actingAs($this->funcionario)->get(route('reportes.pagos.detalle', $modalidad))->getContent();

        $this->assertSame(1, preg_match('/<article class="hoja-detalle solo-impresion".*?<\/article>/s', $html, $coincidencia));
        $hoja = $coincidencia[0];

        foreach ([
            'Politécnico Colombiano Jaime Isaza Cadavid', 'Resumen de pagos — detalle de la modalidad', 'Modalidad: <strong>Educación</strong>',
            'Periodo: todos los pagos registrados.', 'Empleado', 'Cédula', 'N.º de préstamo', 'Monto solicitado', 'Valor de la cuota',
            'Cuotas pagadas', 'Total pagado en el periodo', 'Totales: 1 préstamo', 'Impreso el 28/10/2026 10:30 por Laura Gómez.',
        ] as $esperado) {
            $this->assertStringContainsString($esperado, $hoja, "La hoja debe traer «{$esperado}».");
        }

        foreach (['<form', '<button', '<input', '<a '] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $hoja);
        }

        $this->assertStringContainsString('@page detalle', $html);
        $this->assertStringContainsString('display: table-header-group', $html);
        $this->assertStringContainsString('position: fixed', $html);
        $this->assertStringContainsString('class="no-imprimir"', $html);
    }

    public function test_permisos_del_detalle(): void
    {
        $modalidad = $this->modalidad('Educación');
        $this->prestamoConPagos($modalidad, '1000000001', 'Ana Uno', [['2026-02-01', 100_000, TipoPago::Regular]]);

        $this->actingAs(Usuario::factory()->conPermisos(['empleados.ver'])->create())->get(route('reportes.pagos.detalle', $modalidad))->assertForbidden();
        $this->actingAs(Usuario::factory()->conPermisos(['reportes.ver'])->create())->get(route('reportes.pagos.detalle', $modalidad))->assertOk();
        auth()->logout();
        $this->get(route('reportes.pagos.detalle', $modalidad))->assertRedirect(route('login'));
    }
}
