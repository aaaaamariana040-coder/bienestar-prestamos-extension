<?php

namespace Tests\Feature;

use App\Enums\TipoPago;
use App\Models\Cuota;
use App\Models\Empleado;
use App\Models\Pago;
use App\Models\Prestamo;
use App\Models\TipoPrestamo;
use App\Models\Usuario;
use App\Services\HistorialPagosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HistorialPagosTest extends TestCase
{
    use RefreshDatabase;

    private function empleado(string $cedula, ?string $nombre = null): Empleado
    {
        return Empleado::factory()->create(['cedula' => $cedula, 'nombre_completo' => $nombre ?? 'Empleado '.$cedula]);
    }

    private function prestamo(Empleado $empleado, TipoPrestamo $tipo, int $numCuotas = 36, float $cuota = 250_000): Prestamo
    {
        return Prestamo::factory()->create([
            'cedula_empleado' => $empleado->cedula,
            'id_tipo' => $tipo->id_tipo,
            'num_cuotas' => $numCuotas,
            'cuota_periodica' => $cuota,
        ]);
    }

    private function pagarCuota(Prestamo $prestamo, int $numero, string $fecha, float $monto = 250_000): Pago
    {
        $cuota = Cuota::factory()->pagada()->create(['id_prestamo' => $prestamo->id_prestamo, 'numero_cuota' => $numero]);

        return Pago::factory()->create([
            'id_prestamo' => $prestamo->id_prestamo,
            'id_cuota' => $cuota->id_cuota,
            'fecha_pago' => $fecha,
            'monto' => $monto,
            'tipo' => TipoPago::Regular,
            'monto_aplicado_interes' => 50_000,
            'monto_aplicado_capital' => $monto - 50_000,
        ]);
    }

    private function ver(array $consulta = [], ?Usuario $usuario = null)
    {
        return $this->actingAs($usuario ?? Usuario::factory()->create())
            ->get(route('reportes.historial-pagos', array_merge(['anio' => 2026, 'quincena' => 10], $consulta)));
    }

    private const TODAS_LAS_COLUMNAS = ['Cédula', 'Nombre', 'Programa', 'Cargo', 'Ingreso', 'Nacimiento', 'Nivel', 'Grado', 'Asignación', 'EPS', 'Pensión', 'Centro de Costos', 'Sede', 'Jornada', 'Tipo Contrato'];

    private const SIN_DATOS = ['Nacimiento', 'Nivel', 'Grado', 'EPS', 'Pensión', 'Centro de Costos', 'Sede', 'Jornada'];

    public function test_la_impresion_conserva_las_15_columnas_y_con_guion_las_que_no_existen_en_la_base(): void
    {
        $this->empleado('1000000001');

        $respuesta = $this->actingAs(Usuario::factory()->create())
            ->get(route('reportes.historial-pagos.imprimir', ['anio' => 2026, 'quincena' => 10]))
            ->assertOk();

        foreach (self::TODAS_LAS_COLUMNAS as $titulo) {
            $respuesta->assertSee('<th>'.$titulo.'</th>', false);
        }

        $respuesta->assertDontSee('historial-q-pantalla', false)->assertDontSee('Columnas sin datos aún')->assertSee('—');

        $faltantes = collect(HistorialPagosService::COLUMNAS)->filter(fn (array $columna): bool => $columna['campo'] === null)->pluck('titulo')->all();
        $this->assertSame(self::SIN_DATOS, $faltantes);
    }

    public function test_en_pantalla_no_aparecen_las_columnas_vacias_para_todos_y_se_avisa_cuales(): void
    {
        $this->empleado('1000000001');
        $this->empleado('1000000002');

        $respuesta = $this->ver()->assertOk();

        foreach (['Cédula', 'Nombre', 'Programa', 'Cargo', 'Ingreso', 'Asignación', 'Tipo Contrato'] as $titulo) {
            $respuesta->assertSee('<th>'.$titulo.'</th>', false);
        }

        foreach (self::SIN_DATOS as $titulo) {
            $respuesta->assertDontSee('<th>'.$titulo.'</th>', false);
        }

        $respuesta
            ->assertSee('Columnas sin datos aún: Nacimiento, Nivel, Grado, EPS, Pensión, Centro de Costos, Sede, Jornada (se mostrarán cuando existan).')
            ->assertSee('historial-q-pantalla', false);
    }

    public function test_una_columna_vacia_reaparece_sola_cuando_algun_empleado_tiene_el_dato(): void
    {
        $this->empleado('1000000001')->update(['cargo' => null, 'dependencia' => null]);
        $conDatos = $this->empleado('1000000002');
        $conDatos->update(['cargo' => null, 'dependencia' => null]);

        $this->ver()
            ->assertDontSee('<th>Cargo</th>', false)
            ->assertDontSee('<th>Programa</th>', false)
            ->assertSee('Columnas sin datos aún: Programa, Cargo, Nacimiento');

        $conDatos->update(['cargo' => 'Profesional universitario']);

        $this->ver()
            ->assertSee('<th>Cargo</th>', false)
            ->assertSee('Profesional universitario')
            ->assertDontSee('<th>Programa</th>', false)
            ->assertDontSee('Columnas sin datos aún: Programa, Cargo');
    }

    public function test_las_columnas_visibles_no_cambian_con_la_busqueda_en_vivo_ni_al_paginar(): void
    {
        $this->empleado('1000000001')->update(['cargo' => null]);
        $this->empleado('2000000002')->update(['cargo' => 'Docente de planta']);

        $titulos = function ($respuesta): array {
            preg_match_all('/<th>([^<]+)<\/th>/', $respuesta->getContent(), $coincidencias);

            return $coincidencias[1];
        };

        $completa = $this->ver();
        // El fragmento que pide el buscador en vivo es la misma página filtrada por cédula.
        $filtrada = $this->actingAs(Usuario::factory()->create())
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->get(route('reportes.historial-pagos', ['anio' => 2026, 'quincena' => 10, 'cedula' => '1000']));

        // «Cargo» tiene dato solo en un empleado que el filtro dejó fuera: la columna sigue visible.
        $completa->assertSee('<th>Cargo</th>', false);
        $filtrada->assertSee('<th>Cargo</th>', false)->assertSee('id="resultados-historial"', false)->assertDontSee('Docente de planta');
        $this->assertNotEmpty($titulos($completa));
        $this->assertSame($titulos($completa), $titulos($filtrada));
    }

    public function test_la_pantalla_declara_desplazamiento_horizontal_visible_columnas_fijas_y_ancho_minimo(): void
    {
        $css = file_get_contents(public_path('css/tema-institucional.css'));

        $this->assertMatchesRegularExpression('/@media screen\s*\{[^@]*\.historial-q-contenedor\s*\{[^}]*overflow-x:\s*auto/s', $css);
        $this->assertMatchesRegularExpression('/\.historial-q-contenedor::-webkit-scrollbar\s*\{[^}]*height:\s*14px/s', $css);
        $this->assertMatchesRegularExpression('/\.historial-q-pantalla th:first-child,\s*\.historial-q-pantalla td:first-child\s*\{[^}]*position:\s*sticky;[^}]*left:\s*0/s', $css);
        $this->assertMatchesRegularExpression('/\.historial-q-pantalla th:nth-child\(2\),\s*\.historial-q-pantalla td:nth-child\(2\)\s*\{[^}]*position:\s*sticky/s', $css);
        $this->assertMatchesRegularExpression('/\.historial-q-pantalla th:last-child,\s*\.historial-q-pantalla td:last-child\s*\{[^}]*min-width:\s*18rem/s', $css);
        $this->assertMatchesRegularExpression('/\.historial-q-pantalla\s*\{[^}]*font-size:\s*0\.8rem/s', $css);

        $this->empleado('1000000001');

        $this->ver()->assertSee('table table-striped table-sm bg-white align-middle historial-q historial-q-pantalla', false);
    }

    public function test_la_impresion_conserva_sus_reglas_actuales(): void
    {
        $this->empleado('1000000001');

        $html = $this->actingAs(Usuario::factory()->create())
            ->get(route('reportes.historial-pagos.imprimir', ['anio' => 2026, 'quincena' => 10]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('@page { size: A4 landscape; margin: 10mm; }', $html);
        $this->assertStringContainsString('thead { display: table-header-group; }', $html);
        $this->assertStringContainsString('page-break-inside: avoid', $html);
        $this->assertStringContainsString('table-layout: fixed', $html);
        $this->assertStringContainsString('font-size: 7.5pt', $html);
        $this->assertSame(16, substr_count($html, '.historial-q th:nth-child('));
        $this->assertSame(16, substr_count($html, '<th>'));
        $this->assertStringNotContainsString('historial-q-contenedor', $html);
    }

    public function test_muestra_las_cuotas_pagadas_sobre_el_total_hasta_el_cierre_de_la_quincena(): void
    {
        $tipo = TipoPrestamo::factory()->create(['nombre' => 'Vivienda propia']);
        $empleado = $this->empleado('1000000001');
        $prestamo = $this->prestamo($empleado, $tipo, 36, 250_000);

        // Q9 de 2026 (1 al 15 de mayo) cierra el 15: el pago del 16 de mayo todavía no cuenta.
        $this->pagarCuota($prestamo, 1, '2026-04-30');
        $this->pagarCuota($prestamo, 2, '2026-05-15');
        $this->pagarCuota($prestamo, 3, '2026-05-16');

        $this->ver(['quincena' => 9])
            ->assertOk()
            ->assertSee('Vivienda propia')
            ->assertSee('<span class="credito-cuotas">2/36</span>', false)
            ->assertSee('<span class="credito-valor">250,000.00</span>', false);

        $this->ver(['quincena' => 10])->assertSee('<span class="credito-cuotas">3/36</span>', false);
        $this->ver(['quincena' => 1])->assertSee('<span class="credito-cuotas">0/36</span>', false);
    }

    public function test_los_abonos_no_cuentan_como_cuotas_pagadas(): void
    {
        $tipo = TipoPrestamo::factory()->create();
        $prestamo = $this->prestamo($this->empleado('1000000001'), $tipo, 36);

        Pago::factory()->create(['id_prestamo' => $prestamo->id_prestamo, 'fecha_pago' => '2026-04-01', 'tipo' => TipoPago::AbonoExtraordinario]);

        $this->ver()->assertSee('<span class="credito-cuotas">0/36</span>', false);
    }

    public function test_un_empleado_con_varios_creditos_tiene_una_linea_por_credito(): void
    {
        $vivienda = TipoPrestamo::factory()->create(['nombre' => 'Vivienda propia']);
        $educacion = TipoPrestamo::factory()->create(['nombre' => 'Educación superior']);
        $empleado = $this->empleado('1000000001');

        $prestamoVivienda = $this->prestamo($empleado, $vivienda, 36, 250_000);
        $this->prestamo($empleado, $educacion, 24, 120_000);
        $this->pagarCuota($prestamoVivienda, 1, '2026-04-30');

        $respuesta = $this->ver()->assertOk();

        $respuesta
            ->assertSeeInOrder(['Vivienda propia', '1/36', '250,000.00', 'Educación superior', '0/24', '120,000.00'])
            ->assertSee('<div class="linea-credito">', false);

        $this->assertSame(2, substr_count($respuesta->getContent(), '<div class="linea-credito">'));
    }

    public function test_solo_se_muestran_los_creditos_vigentes_en_la_q(): void
    {
        $tipo = TipoPrestamo::factory()->create(['nombre' => 'Calamidad doméstica']);
        $empleado = $this->empleado('1000000001');

        Prestamo::factory()->cancelado()->create(['cedula_empleado' => $empleado->cedula, 'id_tipo' => $tipo->id_tipo]);

        $this->ver()->assertOk()->assertDontSee('Calamidad doméstica')->assertDontSee('linea-credito', false);
    }

    public function test_el_buscador_filtra_por_prefijo_de_cedula_y_no_por_contenido(): void
    {
        $this->empleado('1234567890');
        $this->empleado('1239999999');
        $this->empleado('9912345678');

        $this->ver(['cedula' => '123'])
            ->assertOk()
            ->assertSee('Empleado 1234567890')
            ->assertSee('Empleado 1239999999')
            ->assertDontSee('Empleado 9912345678');

        $this->ver(['cedula' => '234'])->assertSee('No hay empleados con esa cédula.');
    }

    public function test_usa_el_buscador_en_modo_filtro_y_conserva_anio_y_quincena(): void
    {
        $this->ver(['cedula' => '12'])
            ->assertSee('id="cedula"', false)
            ->assertSee('setTimeout(filtrar, 250)', false)
            ->assertSee('id="resultados-historial"', false)
            ->assertSee('<option value="10" selected>', false)
            ->assertSee('<option value="2026" selected>', false);
    }

    public function test_la_q_se_pagina_de_a_50_empleados(): void
    {
        foreach (range(1, 55) as $numero) {
            $this->empleado(str_pad((string) $numero, 10, '0', STR_PAD_LEFT));
        }

        $respuesta = $this->ver()->assertOk();

        $this->assertSame(50, substr_count($respuesta->getContent(), '<td>00000000'));
        $respuesta->assertSee('55 empleados')->assertSee('page=2', false);
    }

    public function test_por_omision_usa_la_quincena_actual(): void
    {
        $servicio = new HistorialPagosService;

        $this->assertSame(['anio' => 2026, 'quincena' => 19], $servicio->quincenaActual(Carbon::create(2026, 10, 10)));
        $this->assertSame(['anio' => 2026, 'quincena' => 20], $servicio->quincenaActual(Carbon::create(2026, 10, 16)));
        $this->assertSame('2026-02-28', $servicio->cierreDeQuincena(2026, 4)->toDateString());
        $this->assertSame('2026-12-31', $servicio->cierreDeQuincena(2026, 24)->toDateString());

        Carbon::setTestNow('2026-10-16');

        $this->actingAs(Usuario::factory()->create())
            ->get(route('reportes.historial-pagos'))
            ->assertOk()
            ->assertSee('Q20 de 2026');

        Carbon::setTestNow();
    }

    public function test_un_empleado_sin_creditos_muestra_un_mensaje_claro(): void
    {
        $this->empleado('1000000001');

        $this->ver(['cedula' => '1000000001'])
            ->assertOk()
            ->assertSee('Este empleado no tiene créditos.')
            ->assertDontSee('<select id="modalidad-individual"', false);
    }

    public function test_un_solo_empleado_muestra_su_informacion_y_el_menu_de_modalidades(): void
    {
        $vivienda = TipoPrestamo::factory()->create(['nombre' => 'Vivienda propia']);
        $educacion = TipoPrestamo::factory()->create(['nombre' => 'Educación superior']);
        $empleado = $this->empleado('1000000001', 'Laura Mejía');
        $this->prestamo($empleado, $vivienda);
        $this->prestamo($empleado, $educacion);
        $this->empleado('1000000002');

        $this->ver(['cedula' => '1000000001'])
            ->assertOk()
            ->assertSee('id="consulta-individual"', false)
            ->assertSee('Situación de crédito')
            ->assertSee('<select id="modalidad-individual"', false)
            ->assertSee('Vivienda propia')
            ->assertSee('Educación superior')
            ->assertDontSee('Este empleado no tiene créditos.');

        $this->assertMatchesRegularExpression('/id="rango-fechas"\s+hidden/', $this->ver(['cedula' => '1000000001'])->getContent());

        $this->ver(['cedula' => '10000000'])->assertDontSee('id="consulta-individual"', false);
    }

    public function test_lista_los_pagos_del_rango_con_su_total(): void
    {
        $vivienda = TipoPrestamo::factory()->create(['nombre' => 'Vivienda propia']);
        $educacion = TipoPrestamo::factory()->create(['nombre' => 'Educación superior']);
        $empleado = $this->empleado('1000000001');
        $prestamoVivienda = $this->prestamo($empleado, $vivienda);
        $prestamoEducacion = $this->prestamo($empleado, $educacion);

        $this->pagarCuota($prestamoVivienda, 1, '2026-03-15', 250_000);
        $this->pagarCuota($prestamoVivienda, 2, '2026-03-31', 250_000);
        $this->pagarCuota($prestamoVivienda, 3, '2026-06-01', 999_000);
        $this->pagarCuota($prestamoEducacion, 1, '2026-03-15', 777_000);

        $respuesta = $this->ver([
            'cedula' => '1000000001',
            'modalidad' => $vivienda->id_tipo,
            'desde' => '2026-03-15',
            'hasta' => '2026-03-31',
        ])->assertOk();

        $respuesta
            ->assertSee('Pagos de Vivienda propia')
            ->assertSee('2026-03-15')
            ->assertSee('2026-03-31')
            ->assertSee('<td class="text-end" id="total-pagado">500,000.00</td>', false)
            ->assertDontSee('999,000.00')
            ->assertDontSee('777,000.00')
            ->assertSee('Capital')
            ->assertSee('Interés')
            ->assertSee('id="imprimir-individual"', false);
    }

    public function test_avisa_cuando_no_hay_pagos_en_el_rango(): void
    {
        $tipo = TipoPrestamo::factory()->create();
        $empleado = $this->empleado('1000000001');
        $this->pagarCuota($this->prestamo($empleado, $tipo), 1, '2026-03-15');

        $this->ver(['cedula' => '1000000001', 'modalidad' => $tipo->id_tipo, 'desde' => '2026-07-01', 'hasta' => '2026-07-31'])
            ->assertOk()
            ->assertSee('No hay pagos de esta modalidad en el rango de fechas elegido.')
            ->assertDontSee('id="total-pagado"', false);
    }

    public function test_rechaza_fechas_invertidas_o_incompletas(): void
    {
        $tipo = TipoPrestamo::factory()->create();
        $empleado = $this->empleado('1000000001');
        $this->pagarCuota($this->prestamo($empleado, $tipo), 1, '2026-03-15');

        $this->ver(['cedula' => '1000000001', 'modalidad' => $tipo->id_tipo, 'desde' => '2026-04-01', 'hasta' => '2026-03-01'])
            ->assertOk()
            ->assertSee('La fecha «Desde» no puede ser posterior a «Hasta».')
            ->assertDontSee('id="total-pagado"', false)
            ->assertDontSee('No hay pagos de esta modalidad');

        $this->ver(['cedula' => '1000000001', 'modalidad' => $tipo->id_tipo])
            ->assertSee('Indica la fecha «Desde».')
            ->assertSee('Indica la fecha «Hasta».');
    }

    public function test_no_acepta_una_modalidad_que_no_es_del_empleado(): void
    {
        $propia = TipoPrestamo::factory()->create();
        $ajena = TipoPrestamo::factory()->create(['nombre' => 'Modalidad ajena']);
        $this->prestamo($this->empleado('1000000001'), $propia);

        $this->ver(['cedula' => '1000000001', 'modalidad' => $ajena->id_tipo, 'desde' => '2026-01-01', 'hasta' => '2026-12-31'])
            ->assertSee('La modalidad elegida no corresponde a este empleado.');
    }

    public function test_la_impresion_de_la_q_incluye_a_todos_los_empleados_con_logo_titulo_y_quincena(): void
    {
        foreach (range(1, 55) as $numero) {
            $this->empleado(str_pad((string) $numero, 10, '0', STR_PAD_LEFT));
        }

        $respuesta = $this->actingAs(Usuario::factory()->create())
            ->get(route('reportes.historial-pagos.imprimir', ['anio' => 2026, 'quincena' => 10]))
            ->assertOk()
            ->assertSee('images/logo-politecnico.png', false)
            ->assertSee('Bienestar Préstamos')
            ->assertSee('Q10 de 2026')
            ->assertSee('window.print()', false)
            ->assertDontSee('id="menu-lateral"', false);

        $this->assertSame(55, substr_count($respuesta->getContent(), '<td>00000000'));
    }

    public function test_la_impresion_individual_lleva_el_empleado_la_modalidad_y_el_total(): void
    {
        $tipo = TipoPrestamo::factory()->create(['nombre' => 'Vivienda propia']);
        $empleado = $this->empleado('1000000001', 'Laura Mejía');
        $this->pagarCuota($this->prestamo($empleado, $tipo), 1, '2026-03-15', 250_000);

        $this->actingAs(Usuario::factory()->create())
            ->get(route('reportes.historial-pagos.imprimir', [
                'tipo' => 'individual', 'anio' => 2026, 'quincena' => 10, 'cedula' => '1000000001',
                'modalidad' => $tipo->id_tipo, 'desde' => '2026-03-01', 'hasta' => '2026-03-31',
            ]))
            ->assertOk()
            ->assertSee('Laura Mejía')
            ->assertSee('Pagos de Vivienda propia')
            ->assertSee('250,000.00')
            ->assertSee('images/logo-politecnico.png', false)
            ->assertSee('Q10 de 2026');
    }

    public function test_la_impresion_de_la_q_es_a4_horizontal_con_encabezado_repetible_y_filas_enteras(): void
    {
        $this->empleado('1000000001');

        $html = $this->actingAs(Usuario::factory()->create())
            ->get(route('reportes.historial-pagos.imprimir', ['anio' => 2026, 'quincena' => 10]))
            ->assertOk()
            ->assertSee('class="impresion-historial impresion-q"', false)
            ->getContent();

        $this->assertStringContainsString('@page { size: A4 landscape; margin: 10mm; }', $html);
        $this->assertStringNotContainsString('A4 portrait', $html);
        $this->assertStringContainsString('thead { display: table-header-group; }', $html);
        $this->assertStringContainsString('page-break-inside: avoid', $html);
        $this->assertStringContainsString('table-layout: fixed', $html);
        $this->assertStringContainsString('width: 100%', $html);
        $this->assertStringContainsString('font-size: 7.5pt', $html);
        $this->assertStringContainsString('overflow-wrap: anywhere', $html);
        $this->assertStringContainsString('overflow: visible !important', $html);
        $this->assertStringContainsString('border: 0.5pt solid #555', $html);
        $this->assertStringContainsString('background: transparent !important', $html);
        $this->assertStringContainsString('<thead>', $html);
        $this->assertSame(16, substr_count($html, '.historial-q th:nth-child('));
    }

    public function test_la_impresion_individual_cabe_en_a4_vertical_con_las_mismas_reglas_de_tabla(): void
    {
        $tipo = TipoPrestamo::factory()->create();
        $this->pagarCuota($this->prestamo($this->empleado('1000000001'), $tipo), 1, '2026-03-15');

        $html = $this->actingAs(Usuario::factory()->create())
            ->get(route('reportes.historial-pagos.imprimir', [
                'tipo' => 'individual', 'anio' => 2026, 'quincena' => 10, 'cedula' => '1000000001',
                'modalidad' => $tipo->id_tipo, 'desde' => '2026-03-01', 'hasta' => '2026-03-31',
            ]))
            ->assertOk()
            ->assertSee('class="impresion-historial impresion-individual"', false)
            ->getContent();

        $this->assertStringContainsString('@page { size: A4 portrait; margin: 10mm; }', $html);
        $this->assertStringNotContainsString('A4 landscape', $html);
        $this->assertStringContainsString('thead { display: table-header-group; }', $html);
        $this->assertStringContainsString('page-break-inside: avoid', $html);
        $this->assertStringContainsString('table-layout: fixed', $html);
    }

    public function test_los_estilos_de_impresion_solo_estan_en_las_vistas_de_impresion(): void
    {
        $css = file_get_contents(public_path('css/tema-institucional.css'));

        $this->assertStringNotContainsString('table-header-group', $css);
        $this->assertStringNotContainsString('A4 landscape', $css);

        $this->ver()
            ->assertOk()
            ->assertDontSee('A4 landscape', false)
            ->assertDontSee('table-layout: fixed', false);
    }

    public function test_la_impresion_individual_sin_datos_validos_vuelve_a_la_pantalla(): void
    {
        $this->empleado('1000000001');

        $this->actingAs(Usuario::factory()->create())
            ->get(route('reportes.historial-pagos.imprimir', ['tipo' => 'individual', 'cedula' => '1000000001']))
            ->assertRedirect();
    }

    public function test_la_pantalla_trae_estilos_para_ocultar_menu_botones_y_buscadores_al_imprimir(): void
    {
        $css = file_get_contents(public_path('css/tema-institucional.css'));

        $this->assertMatchesRegularExpression('/@media print\s*\{[^@]*\.menu-lateral[^@]*\.no-imprimir[^@]*display:\s*none\s*!important/s', $css);
        $this->ver()
            ->assertSee('class="solo-impresion', false)
            ->assertSee('images/logo-politecnico.png', false)
            ->assertSee('class="row g-2 align-items-end mb-3 no-imprimir"', false);
    }

    public function test_el_numero_de_consultas_no_crece_con_mas_empleados(): void
    {
        $tipo = TipoPrestamo::factory()->create();
        $usuario = Usuario::factory()->create();

        $contar = function () use ($usuario): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($usuario)->get(route('reportes.historial-pagos', ['anio' => 2026, 'quincena' => 10]))->assertOk();
            $consultas = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $consultas;
        };

        foreach (range(1, 2) as $numero) {
            $empleado = $this->empleado('100000000'.$numero);
            $this->pagarCuota($this->prestamo($empleado, $tipo), 1, '2026-04-30');
            $this->prestamo($empleado, $tipo);
        }

        $pocas = $contar();

        foreach (range(3, 30) as $numero) {
            $empleado = $this->empleado(str_pad((string) (1000000000 + $numero), 10, '0', STR_PAD_LEFT));
            $this->pagarCuota($this->prestamo($empleado, $tipo), 1, '2026-04-30');
            $this->prestamo($empleado, $tipo);
        }

        $this->assertSame($pocas, $contar());
    }

    public function test_funcionario_y_administrador_acceden_y_los_demas_no(): void
    {
        $rutas = [route('reportes.historial-pagos'), route('reportes.historial-pagos.imprimir')];

        foreach ([Usuario::factory()->create(), Usuario::factory()->administrador()->create()] as $usuario) {
            foreach ($rutas as $ruta) {
                $this->actingAs($usuario)->get($ruta)->assertOk();
            }
        }

        $deshabilitado = Usuario::factory()->create(['habilitado' => false]);

        foreach ($rutas as $ruta) {
            $this->actingAs($deshabilitado)->get($ruta)->assertRedirect(route('login'));
        }

        auth()->logout();

        foreach ($rutas as $ruta) {
            $this->get($ruta)->assertRedirect(route('login'));
        }
    }

    public function test_el_menu_reportes_tiene_historial_de_pagos_y_ya_no_tiene_prestamos(): void
    {
        $respuesta = $this->actingAs(Usuario::factory()->create())->get(route('empleados.index'))->assertOk();

        $respuesta
            ->assertSee('href="'.route('reportes.historial-pagos').'"', false)
            ->assertSee('href="'.route('reportes.cartera').'"', false)
            ->assertSee('href="'.route('reportes.presupuesto').'"', false)
            ->assertSee('href="'.route('informes.index').'"', false)
            ->assertDontSee('href="'.route('reportes.prestamos').'"', false)
            // La ventana «Préstamos» de Solicitudes y préstamos no se toca.
            ->assertSee('href="'.route('prestamos.index').'"', false);

        $this->assertMatchesRegularExpression(
            '/id="grupo-reportes".*?<\/div>/s',
            $respuesta->getContent()
        );

        preg_match('/id="grupo-reportes".*?<\/div>/s', $respuesta->getContent(), $grupo);
        $this->assertStringNotContainsString('Préstamos', $grupo[0]);
        $this->assertStringContainsString('Historial de pagos', $grupo[0]);
    }
}
