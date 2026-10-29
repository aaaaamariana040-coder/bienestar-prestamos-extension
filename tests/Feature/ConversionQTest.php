<?php

namespace Tests\Feature;

use App\Models\Empleado;
use App\Models\TasaInt;
use App\Models\TipoPrestamo;
use App\Services\CalculoFinancieroService;
use App\Services\CargaEmpleadosService;
use App\Services\CargaPrestamosVigentesService;
use App\Services\ConversionQService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * datos:convertir-q (TEMPORAL, docs/conversion-q.md): convierte los exportes Q09 y Q15 a las
 * plantillas de carga. Solo usa CSV ficticios armados aquí (cédulas 9000000xxx, «Ejemplo …») en
 * una carpeta temporal; nunca toca storage/app/private/real/.
 */
class ConversionQTest extends TestCase
{
    use RefreshDatabase;

    private string $carpeta;

    /** @var list<string> */
    private array $creados = [];

    private const ENCABEZADO_Q09 = 'Cédula;Nombre;Programa;Cargo;Ingreso;Nacimiento;Nivel;Grado;Asignación;EPS;Pensión;Centro de Costos;Sede;Jornada;Tipo Contrato';

    private const ENCABEZADO_Q15 = 'CEDULA_NUEVA;NOMBRE_NUEVO;CONCEPTO_NUEVO;FECHA_PRESTAVO;VALOR_MONTO;TOTAL_QUINCENA;QUINCENA_NUEVA;SALDO_NUEVO;VALOR DE CUOTA;AMORTIZA;INTERN;ANIO;TIPO';

    protected function setUp(): void
    {
        parent::setUp();

        $this->carpeta = sys_get_temp_dir().DIRECTORY_SEPARATOR.'conversion-q-prueba-'.bin2hex(random_bytes(4));
        mkdir($this->carpeta);
        config(['conversion_q.carpeta' => $this->carpeta, 'conversion_q.verificar_git' => false]);

        foreach (['Educación' => 6, 'Vehículo' => 8, 'Calamidad doméstica y necesidades especiales' => 7] as $nombre => $_) {
            $tipo = TipoPrestamo::factory()->create(['nombre' => $nombre, 'plazo_maximo' => 168]);
            TasaInt::factory()->create(['id_tipo' => $tipo->id_tipo, 'anio' => 2026, 'tasa_anual' => 10, 'created_at' => '2026-01-02 08:00:00']);
        }

        TipoPrestamo::factory()->create(['nombre' => 'Vivienda — compra de inmueble']);
        TipoPrestamo::factory()->create(['nombre' => 'Vivienda — construcción']);
        TipoPrestamo::factory()->create(['nombre' => 'Vivienda — cancelación total de hipoteca (deshipoteca)']);
    }

    protected function tearDown(): void
    {
        // La carpeta es temporal y propia de esta prueba: se borran sus archivos y ella.
        foreach (glob($this->carpeta.DIRECTORY_SEPARATOR.'*') ?: [] as $ruta) {
            @unlink($ruta);
        }

        @rmdir($this->carpeta);

        parent::tearDown();
    }

    // ───────── Ayudas ─────────

    private function escribir(string $nombre, string $contenido): void
    {
        $ruta = $this->carpeta.DIRECTORY_SEPARATOR.$nombre;
        file_put_contents($ruta, $contenido);
        $this->creados[] = $ruta;
    }

    /**
     * @param  list<list<string>>  $filas
     */
    private function q09(array $filas, string $separador = ';'): string
    {
        $lineas = ['Reporte_AS400', str_replace(';', $separador, self::ENCABEZADO_Q09)];

        foreach ($filas as $fila) {
            $lineas[] = implode($separador, $fila);
        }

        return implode("\r\n", $lineas)."\r\n";
    }

    /**
     * @return list<string>
     */
    private function empleado(string $cedula, string $nombre, string $cargo, string $asignacion, string $contrato = 'PLANTA', string $ingreso = '20150310'): array
    {
        return [$cedula, $nombre, 'Programa de ejemplo', $cargo, $ingreso, '19800101', 'N1', 'G1', $asignacion, 'EPS', 'PEN', 'CC1', 'Sede', 'Jornada', $contrato];
    }

    /**
     * @param  list<list<string>>  $filas
     */
    private function q15(array $filas, string $separador = ';'): string
    {
        $lineas = [str_replace(';', $separador, self::ENCABEZADO_Q15)];

        foreach ($filas as $fila) {
            $lineas[] = implode($separador, $fila);
        }

        return implode("\r\n", $lineas)."\r\n";
    }

    /**
     * @return list<string>
     */
    private function prestamo(string $cedula, string $concepto, string $monto, string $fecha, string $total, string $quincena, string $saldo, string $cuota = '0'): array
    {
        return [$cedula, 'Ejemplo', $concepto, $fecha, $monto, $total, $quincena, $saldo, $cuota, '0', '0', '2026', 'T'];
    }

    /**
     * @return array{codigo: int, salida: string}
     */
    private function correr(string|array $opciones = []): array
    {
        $codigo = Artisan::call('datos:convertir-q', is_string($opciones) ? ($opciones === '' ? [] : ['--interpretacion' => $opciones]) : $opciones);

        return ['codigo' => $codigo, 'salida' => Artisan::output()];
    }

    /**
     * @return list<array<string, string>>
     */
    private function leerSalida(string $nombre): array
    {
        $this->creados[] = $this->carpeta.DIRECTORY_SEPARATOR.$nombre;

        $contenido = (string) file_get_contents($this->carpeta.DIRECTORY_SEPARATOR.$nombre);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $contenido, 'La salida lleva BOM, como las plantillas.');

        $lineas = array_values(array_filter(preg_split('/\r\n/', substr($contenido, 3)) ?: []));
        $encabezado = str_getcsv(array_shift($lineas), ';', '"', '');

        return array_map(fn (string $linea): array => array_combine($encabezado, str_getcsv($linea, ';', '"', '')), $lineas);
    }

    private function saldoDespuesDe(float $monto, int $plazo, int $pagadas, float $tasa = 10): float
    {
        $servicio = app(CalculoFinancieroService::class);
        $periodica = $servicio->tasaPeriodicaQuincenal($tasa);
        $tabla = $servicio->tablaAmortizacion($monto, $periodica, $plazo, $servicio->cuotaFija($monto, $periodica, $plazo));

        return $pagadas === 0 ? $monto : $tabla[$pagadas - 1]['saldo'];
    }

    // ───────── Empleados (Q09) ─────────

    public function test_convierte_la_nomina_con_titulo_en_la_fila_1_y_las_columnas_de_la_plantilla(): void
    {
        $this->escribir('q09.csv', $this->q09([
            $this->empleado('9000000001', 'Ejemplo Uno', 'PROFESOR', '4.500.000,50', 'PLANTA', '20150310'),
            $this->empleado('9000000002', 'Ejemplo Dos', 'AUXILIAR', '3200000'),
        ]));
        $this->escribir('q15.csv', $this->q15([]));

        ['codigo' => $codigo] = $this->correr();
        $this->assertSame(0, $codigo);

        $filas = $this->leerSalida('empleados-q09.csv');
        $this->assertCount(2, $filas);
        $this->assertSame(CargaEmpleadosService::COLUMNAS, array_keys($filas[0]));
        $this->assertSame('9000000001', $filas[0]['cedula']);
        $this->assertSame('Ejemplo Uno', $filas[0]['nombre_completo']);
        $this->assertSame('docente', $filas[0]['tipo_vinculacion']);
        $this->assertSame('PROFESOR', $filas[0]['cargo']);
        $this->assertSame('Programa de ejemplo', $filas[0]['dependencia']);
        $this->assertSame('4500000.5', $filas[0]['salario']);
        $this->assertSame('2015-03-10', $filas[0]['fecha_vinculacion'], 'Ingreso aaaammdd pasa a aaaa-mm-dd.');
        $this->assertSame('vinculado', $filas[1]['tipo_vinculacion']);
        $this->assertSame('3200000', $filas[1]['salario']);
    }

    public function test_la_salida_de_empleados_pasa_la_vista_previa_de_cargar_empleados(): void
    {
        $this->escribir('q09.csv', $this->q09([$this->empleado('9000000001', 'Ejemplo Uno', 'PROFESOR', '4.500.000'), $this->empleado('9000000003', 'Ejemplo Tres', 'JUBILADO', '2.400.000')]));
        $this->escribir('q15.csv', $this->q15([]));
        $this->correr();

        $this->creados[] = $this->carpeta.DIRECTORY_SEPARATOR.'empleados-q09.csv';
        $analisis = app(CargaEmpleadosService::class)->analizar((string) file_get_contents($this->carpeta.DIRECTORY_SEPARATOR.'empleados-q09.csv'));

        $this->assertSame([], $analisis['errores_archivo']);
        $this->assertSame(0, $analisis['resumen']['errores']);
        $this->assertSame(2, $analisis['resumen']['total']);
    }

    public function test_cargo_y_tipo_de_contrato_definen_jubilado_docente_ocasional_y_vinculado(): void
    {
        $this->escribir('q09.csv', $this->q09([
            $this->empleado('9000000001', 'Ejemplo Uno', 'JUBILADO', '2000000'),
            $this->empleado('9000000002', 'Ejemplo Dos', 'PROFESOR', '3000000', 'PROFESOR OCASIONAL'),
            $this->empleado('9000000003', 'Ejemplo Tres', 'PROFESOR', '3000000', 'PLANTA'),
            $this->empleado('9000000004', 'Ejemplo Cuatro', 'Profesor', '3000000', 'OCASIONAL'),
            $this->empleado('9000000005', 'Ejemplo Cinco', 'SECRETARIA', '2500000', 'OCASIONAL'),
        ]));
        $this->escribir('q15.csv', $this->q15([]));
        $this->correr();

        $tipos = array_column($this->leerSalida('empleados-q09.csv'), 'tipo_vinculacion', 'cedula');

        $this->assertSame('jubilado', $tipos['9000000001']);
        $this->assertSame('docente_ocasional', $tipos['9000000002']);
        $this->assertSame('docente', $tipos['9000000003']);
        $this->assertSame('docente_ocasional', $tipos['9000000004'], 'El cargo se compara sin importar mayúsculas.');
        $this->assertSame('vinculado', $tipos['9000000005'], 'Solo los profesores pasan a docente ocasional.');
    }

    public function test_cada_codigo_de_tipo_contrato_define_el_tipo_de_vinculacion(): void
    {
        $this->escribir('q09.csv', $this->q09([
            $this->empleado('9000000001', 'Ejemplo Uno', 'SECRETARIA', '2000000', '6'),
            $this->empleado('9000000002', 'Ejemplo Dos', 'SECRETARIA', '2000000', '7'),
            $this->empleado('9000000003', 'Ejemplo Tres', 'SECRETARIA', '2000000', '2'),
            $this->empleado('9000000004', 'Ejemplo Cuatro', 'SECRETARIA', '2000000', '1'),
            $this->empleado('9000000005', 'Ejemplo Cinco', 'SECRETARIA', '2000000', '06'),
        ]));
        $this->escribir('q15.csv', $this->q15([]));

        $salida = $this->correr()['salida'];
        $tipos = array_column($this->leerSalida('empleados-q09.csv'), 'tipo_vinculacion', 'cedula');

        $this->assertSame('docente_ocasional', $tipos['9000000001']);
        $this->assertSame('jubilado', $tipos['9000000002']);
        $this->assertSame('docente', $tipos['9000000003']);
        $this->assertSame('vinculado', $tipos['9000000004']);
        $this->assertSame('docente_ocasional', $tipos['9000000005'], 'El código se lee sin ceros a la izquierda.');

        $this->assertStringContainsString('«6»: 1 — código → Docente ocasional', $salida);
        $this->assertStringContainsString('«7»: 1 — código → Jubilado', $salida);
        $this->assertStringContainsString('«2»: 1 — código → Docente', $salida);
        $this->assertStringContainsString('«1»: 1 — código → Vinculado', $salida);
        $this->assertStringNotContainsString('sin clasificar', $salida);
        $this->assertStringContainsString('tipo de vinculación Docente ocasional: 2', $salida);
        $this->assertStringContainsString('tipo de vinculación Jubilado: 1', $salida);
        $this->assertStringContainsString('tipo de vinculación Docente: 1', $salida);
        $this->assertStringContainsString('tipo de vinculación Vinculado: 1', $salida);
    }

    public function test_cuando_el_codigo_y_el_cargo_se_contradicen_manda_el_codigo_y_se_cuenta(): void
    {
        $this->escribir('q09.csv', $this->q09([
            $this->empleado('9000000001', 'Ejemplo Uno', 'JUBILADO', '2000000', '1'),
            $this->empleado('9000000002', 'Ejemplo Dos', 'PROFESOR', '3000000', '7'),
            $this->empleado('9000000003', 'Ejemplo Tres', 'PROFESOR', '3000000', '1'),
            $this->empleado('9000000004', 'Ejemplo Cuatro', 'PROFESOR', '3000000', '2'),
        ]));
        $this->escribir('q15.csv', $this->q15([]));

        $salida = $this->correr()['salida'];
        $tipos = array_column($this->leerSalida('empleados-q09.csv'), 'tipo_vinculacion', 'cedula');

        $this->assertSame('vinculado', $tipos['9000000001']);
        $this->assertSame('jubilado', $tipos['9000000002']);
        $this->assertSame('vinculado', $tipos['9000000003']);
        $this->assertSame('docente', $tipos['9000000004'], 'Sin contradicción, el código y el cargo coinciden.');
        $this->assertStringContainsString('contradice el cargo (manda el código): 3', $salida);
    }

    public function test_un_valor_que_no_es_codigo_conserva_la_regla_del_texto_ocasional(): void
    {
        $this->escribir('q09.csv', $this->q09([
            $this->empleado('9000000001', 'Ejemplo Uno', 'PROFESOR', '3000000', 'Ocasional por horas'),
            $this->empleado('9000000002', 'Ejemplo Dos', 'PROFESOR', '3000000', '9'),
        ]));
        $this->escribir('q15.csv', $this->q15([]));

        $salida = $this->correr()['salida'];
        $tipos = array_column($this->leerSalida('empleados-q09.csv'), 'tipo_vinculacion', 'cedula');

        $this->assertSame('docente_ocasional', $tipos['9000000001']);
        $this->assertSame('docente', $tipos['9000000002'], 'Un código sin mapeo no cambia el tipo por cargo.');
        $this->assertStringContainsString('«9»: 1 — sin clasificar', $salida);
    }

    public function test_de_carrera_sale_vacio_mientras_no_se_confirme_el_significado_y_se_configura_por_valor(): void
    {
        $this->escribir('q09.csv', $this->q09([
            $this->empleado('9000000001', 'Ejemplo Uno', 'SECRETARIA', '2500000', 'CARRERA ADMINISTRATIVA'),
            $this->empleado('9000000002', 'Ejemplo Dos', 'SECRETARIA', '2500000', 'LIBRE NOMBRAMIENTO'),
        ]));
        $this->escribir('q15.csv', $this->q15([]));

        $salida = $this->correr()['salida'];
        $this->assertSame(['', ''], array_column($this->leerSalida('empleados-q09.csv'), 'de_carrera'));
        $this->assertStringContainsString('«CARRERA ADMINISTRATIVA»: 1 — sin clasificar', $salida);
        $this->assertStringContainsString('«LIBRE NOMBRAMIENTO»: 1 — sin clasificar', $salida);

        config(['conversion_q.tipo_contrato.carrera' => ['carrera administrativa'], 'conversion_q.tipo_contrato.no_carrera' => ['LIBRE NOMBRAMIENTO']]);
        $salida = $this->correr()['salida'];

        $this->assertSame(['si', 'no'], array_column($this->leerSalida('empleados-q09.csv'), 'de_carrera'));
        $this->assertStringContainsString('de carrera', $salida);
        $this->assertStringContainsString('no de carrera', $salida);
    }

    public function test_omite_salario_vacio_o_cero_cedula_repetida_o_invalida_y_los_cuenta(): void
    {
        $this->escribir('q09.csv', $this->q09([
            $this->empleado('9000000001', 'Ejemplo Uno', 'PROFESOR', '3000000'),
            $this->empleado('9000000002', 'Ejemplo Dos', 'PROFESOR', '0'),
            $this->empleado('9000000003', 'Ejemplo Tres', 'PROFESOR', ''),
            $this->empleado('9000000001', 'Ejemplo Uno repetido', 'PROFESOR', '3000000'),
            $this->empleado('', 'Ejemplo Sin Cédula', 'PROFESOR', '3000000'),
            $this->empleado('9000000004', 'Ejemplo Cuatro', 'PROFESOR', '3000000', 'PLANTA', '20151340'),
        ]));
        $this->escribir('q15.csv', $this->q15([]));

        $salida = $this->correr()['salida'];

        $this->assertSame(['9000000001', '9000000004'], array_column($this->leerSalida('empleados-q09.csv'), 'cedula'));
        $this->assertSame('', $this->leerSalida('empleados-q09.csv')[1]['fecha_vinculacion'], 'Una fecha de ingreso inválida deja la columna vacía.');
        $this->assertStringContainsString('6 leídos, 2 convertidos, 4 omitidos', $salida);
        $this->assertStringContainsString('salario vacío o cero: 2', $salida);
        $this->assertStringContainsString('cédula repetida: 1', $salida);
        $this->assertStringContainsString('cedula vacía o inválida: 1', $salida);
        $this->assertStringContainsString('fechas de ingreso inválidas (columna vacía): 1', $salida);
    }

    public function test_lee_latin1_y_la_coma_como_separador(): void
    {
        $this->escribir('q09.csv', utf8_decode($this->q09([$this->empleado('9000000001', 'Ejemplo Muñoz', 'PROFESOR', '3000000')], ',')));
        $this->escribir('q15.csv', utf8_decode($this->q15([$this->prestamo('9000000001', '14', '2000000', '02/03/2026', '24', '24', '2000000')], ',')));

        ['codigo' => $codigo] = $this->correr();

        $this->assertSame(0, $codigo);
        $this->assertSame('Ejemplo Muñoz', $this->leerSalida('empleados-q09.csv')[0]['nombre_completo']);
        $this->assertCount(1, $this->leerSalida('prestamos-q15.csv'));
    }

    // ───────── Cartera (Q15) ─────────

    public function test_convierte_la_cartera_con_las_columnas_de_la_plantilla_de_vigentes(): void
    {
        $this->escribir('q09.csv', $this->q09([$this->empleado('9000000001', 'Ejemplo Uno', 'PROFESOR', '5000000')]));
        $saldo = $this->saldoDespuesDe(3_000_000, 24, 6);
        $this->escribir('q15.csv', $this->q15([$this->prestamo('9000000001', '14', '3.000.000', '02/03/2026', '24', '18', number_format($saldo, 2, ',', '.'))]));

        $this->correr();

        $filas = $this->leerSalida('prestamos-q15.csv');
        $this->assertCount(1, $filas);
        $this->assertSame(CargaPrestamosVigentesService::COLUMNAS, array_keys($filas[0]));
        $this->assertSame('9000000001', $filas[0]['cedula']);
        $this->assertSame('Educación', $filas[0]['modalidad']);
        $this->assertSame('3000000', $filas[0]['monto_inicial']);
        $this->assertSame('2026-03-02', $filas[0]['fecha_desembolso']);
        $this->assertSame('24', $filas[0]['plazo_quincenas']);
        $this->assertSame('6', $filas[0]['cuotas_pagadas']);
        $this->assertSame('', $filas[0]['tasa_anual'], 'La tasa queda vacía: el aplicativo usa la vigente al desembolso.');
        $this->assertSame('', $filas[0]['puntos_adicionales']);
        $this->assertSame((string) round($saldo, 2), $filas[0]['saldo_actual']);
    }

    public function test_la_salida_de_prestamos_pasa_la_vista_previa_de_cargar_vigentes_sin_advertencia_de_saldo(): void
    {
        $this->escribir('q09.csv', $this->q09([$this->empleado('9000000001', 'Ejemplo Uno', 'PROFESOR', '5000000')]));
        $this->escribir('q15.csv', $this->q15([$this->prestamo('9000000001', '14', '3000000', '02/03/2026', '24', '18', (string) $this->saldoDespuesDe(3_000_000, 24, 6))]));
        $this->correr();
        $this->creados[] = $this->carpeta.DIRECTORY_SEPARATOR.'prestamos-q15.csv';
        Empleado::factory()->create(['cedula' => '9000000001', 'salario' => 5_000_000]);

        $analisis = app(CargaPrestamosVigentesService::class)->analizar((string) file_get_contents($this->carpeta.DIRECTORY_SEPARATOR.'prestamos-q15.csv'));

        $this->assertSame(0, $analisis['resumen']['errores']);
        $this->assertSame([], array_merge(...array_column($analisis['filas'], 'advertencias')) ?: []);
    }

    public function test_cuando_quincena_nueva_son_restantes_lo_detecta_y_calcula_las_pagadas(): void
    {
        $this->escribir('q09.csv', $this->q09([$this->empleado('9000000001', 'Ejemplo Uno', 'PROFESOR', '5000000'), $this->empleado('9000000002', 'Ejemplo Dos', 'PROFESOR', '5000000')]));
        $this->escribir('q15.csv', $this->q15([
            $this->prestamo('9000000001', '14', '3000000', '02/03/2026', '24', '18', (string) $this->saldoDespuesDe(3_000_000, 24, 6)),
            $this->prestamo('9000000002', '15', '2000000', '16/03/2026', '12', '4', (string) $this->saldoDespuesDe(2_000_000, 12, 8)),
        ]));

        $salida = $this->correr()['salida'];

        $this->assertSame(['6', '8'], array_column($this->leerSalida('prestamos-q15.csv'), 'cuotas_pagadas'));
        $this->assertStringContainsString('restantes: 2 exactas', $salida);
        $this->assertStringContainsString('pagadas:   0 exactas', $salida);
        $this->assertStringContainsString('interpretación usada: restantes', $salida);
    }

    public function test_cuando_quincena_nueva_son_pagadas_lo_detecta_y_las_copia(): void
    {
        $this->escribir('q09.csv', $this->q09([$this->empleado('9000000001', 'Ejemplo Uno', 'PROFESOR', '5000000'), $this->empleado('9000000002', 'Ejemplo Dos', 'PROFESOR', '5000000')]));
        $this->escribir('q15.csv', $this->q15([
            $this->prestamo('9000000001', '14', '3000000', '02/03/2026', '24', '6', (string) $this->saldoDespuesDe(3_000_000, 24, 6)),
            $this->prestamo('9000000002', '15', '2000000', '16/03/2026', '12', '8', (string) $this->saldoDespuesDe(2_000_000, 12, 8)),
        ]));

        $salida = $this->correr()['salida'];

        $this->assertSame(['6', '8'], array_column($this->leerSalida('prestamos-q15.csv'), 'cuotas_pagadas'));
        $this->assertStringContainsString('restantes: 0 exactas', $salida);
        $this->assertStringContainsString('pagadas:   2 exactas', $salida);
        $this->assertStringContainsString('interpretación usada: pagadas', $salida);
    }

    public function test_sin_evidencia_usa_restantes_y_la_opcion_fuerza_la_interpretacion(): void
    {
        $this->escribir('q09.csv', $this->q09([$this->empleado('9000000001', 'Ejemplo Uno', 'PROFESOR', '5000000')]));
        $this->escribir('q15.csv', $this->q15([$this->prestamo('9000000001', '14', '3000000', '02/03/2026', '24', '18', '123')]));

        $this->assertStringContainsString('interpretación usada: restantes (sin evidencia', $this->correr()['salida']);
        $this->assertSame('6', $this->leerSalida('prestamos-q15.csv')[0]['cuotas_pagadas']);

        $this->correr('pagadas');
        $this->assertSame('18', $this->leerSalida('prestamos-q15.csv')[0]['cuotas_pagadas']);
    }

    public function test_excluye_seguros_y_computador_mapea_vivienda_y_deja_sin_mapeo_31_y_34(): void
    {
        $this->escribir('q09.csv', $this->q09([$this->empleado('9000000001', 'Ejemplo Uno', 'PROFESOR', '5000000')]));
        $this->escribir('q15.csv', $this->q15([
            $this->prestamo('9000000001', '14', '3000000', '02/03/2026', '24', '18', '1'),
            $this->prestamo('9000000001', '10', '1000000', '02/03/2026', '12', '6', '1'),
            $this->prestamo('9000000001', '10', '1100000', '02/03/2026', '12', '6', '1'),
            $this->prestamo('9000000001', '19', '1200000', '02/03/2026', '12', '6', '1'),
            $this->prestamo('9000000001', '31', '1300000', '02/03/2026', '12', '6', '1'),
            $this->prestamo('9000000001', '34', '1400000', '02/03/2026', '12', '6', '1'),
            $this->prestamo('9000000001', '30', '90000000', '02/03/2026', '120', '60', '1'),
            $this->prestamo('9000000001', '33', '80000000', '02/03/2026', '120', '60', '1'),
            $this->prestamo('9000000001', '26', '20000000', '02/03/2026', '48', '24', '1'),
        ]));

        $salida = $this->correr()['salida'];

        $modalidades = array_column($this->leerSalida('prestamos-q15.csv'), 'modalidad');
        $this->assertSame(['Educación', 'Vivienda — compra de inmueble', 'Vivienda — cancelación total de hipoteca (deshipoteca)', 'Vehículo'], $modalidades, '14, 30, 33 y 26 se convierten; 10 y 19 se excluyen; 31 y 34 quedan sin mapeo.');
        $this->assertStringContainsString('excluidos — concepto 10 (crédito de seguros (no es del Fondo)): 2', $salida);
        $this->assertStringContainsString('excluidos — concepto 19 (computador (no existe como modalidad)): 1', $salida);
        $this->assertStringContainsString('sin mapeo — concepto 31: 1', $salida);
        $this->assertStringContainsString('sin mapeo — concepto 34: 1', $salida);
        $this->assertStringNotContainsString('sin mapeo — concepto 30', $salida);
        $this->assertStringNotContainsString('sin mapeo — concepto 33', $salida);
        $this->assertStringContainsString('Vivienda — construcción', $salida);
    }

    public function test_el_concepto_30_es_una_aproximacion_y_el_documento_lo_dice(): void
    {
        $this->assertSame('Vivienda — compra de inmueble', config('conversion_q.mapeo.30'));
        $this->assertSame('Vivienda — cancelación total de hipoteca (deshipoteca)', config('conversion_q.mapeo.33'));
        $this->assertNull(config('conversion_q.mapeo.31'));
        $this->assertNull(config('conversion_q.mapeo.34'));
        $this->assertStringContainsString('aproximación', file_get_contents(base_path('docs/conversion-q.md')));
    }

    // ───────── Tasa deducida ─────────

    private function cuotaFrancesa(float $saldo, int $restantes, float $tasa): float
    {
        $servicio = app(CalculoFinancieroService::class);

        return $servicio->cuotaFija($saldo, $servicio->tasaPeriodicaQuincenal($tasa), $restantes);
    }

    /**
     * @return list<array<string, string>>
     */
    private function salidaPrestamos(): array
    {
        return $this->leerSalida('prestamos-q15.csv');
    }

    public function test_la_tasa_deducida_reproduce_la_tasa_con_la_que_se_calculo_la_cuota(): void
    {
        $this->escribir('q09.csv', $this->q09([$this->empleado('9000000001', 'Ejemplo Uno', 'PROFESOR', '5000000')]));
        $this->escribir('q15.csv', $this->q15([
            $this->prestamo('9000000001', '14', '3000000', '02/03/2026', '24', '18', '2000000', (string) $this->cuotaFrancesa(2_000_000, 18, 10)),
            $this->prestamo('9000000001', '15', '3000000', '02/03/2026', '24', '18', '2500000', (string) $this->cuotaFrancesa(2_500_000, 18, 7.5)),
            $this->prestamo('9000000001', '16', '30000000', '02/03/2026', '96', '60', '25000000', (string) $this->cuotaFrancesa(25_000_000, 60, 13.25)),
        ]));

        $salida = $this->correr(['--interpretacion' => 'restantes'])['salida'];

        $this->assertSame(['10', '7.5', '13.25'], array_column($this->salidaPrestamos(), 'tasa_anual'));
        $this->assertStringContainsString('3 tasa deducida, 0 tasa no deducible', $salida);
    }

    public function test_la_tasa_deducida_es_la_de_la_cuota_aunque_no_coincida_con_la_del_aplicativo(): void
    {
        $this->escribir('q09.csv', $this->q09([$this->empleado('9000000001', 'Ejemplo Uno', 'PROFESOR', '5000000')]));
        $this->escribir('q15.csv', $this->q15([$this->prestamo('9000000001', '14', '3000000', '02/03/2026', '24', '18', '2000000', (string) $this->cuotaFrancesa(2_000_000, 18, 12))]));

        $this->correr(['--interpretacion' => 'restantes']);

        $this->assertSame('12', $this->salidaPrestamos()[0]['tasa_anual'], 'La del aplicativo para 2026 sería 10; se deduce la del archivo.');
    }

    public function test_sin_datos_para_deducir_la_tasa_queda_vacia_y_se_cuenta(): void
    {
        $this->escribir('q09.csv', $this->q09([$this->empleado('9000000001', 'Ejemplo Uno', 'PROFESOR', '5000000')]));
        $this->escribir('q15.csv', $this->q15([
            $this->prestamo('9000000001', '14', '3000000', '02/03/2026', '24', '18', '2000000', '0'),
            $this->prestamo('9000000001', '14', '3100000', '02/03/2026', '24', '18', '2000000', ''),
            $this->prestamo('9000000001', '14', '3200000', '02/03/2026', '24', '18', '0', '150000'),
            $this->prestamo('9000000001', '14', '3300000', '02/03/2026', '24', '0', '2000000', '150000'),
        ]));

        $salida = $this->correr(['--interpretacion' => 'restantes'])['salida'];

        $this->assertSame(['', '', '', ''], array_column($this->salidaPrestamos(), 'tasa_anual'));
        $this->assertStringContainsString('0 tasa deducida, 4 tasa no deducible', $salida);
        $this->assertStringContainsString('tasa no deducible — sin_datos: 4', $salida);
    }

    public function test_una_cuota_fuera_del_rango_de_0_a_40_por_ciento_no_da_tasa(): void
    {
        $this->escribir('q09.csv', $this->q09([$this->empleado('9000000001', 'Ejemplo Uno', 'PROFESOR', '5000000')]));
        $this->escribir('q15.csv', $this->q15([
            $this->prestamo('9000000001', '14', '3000000', '02/03/2026', '24', '18', '2000000', (string) ($this->cuotaFrancesa(2_000_000, 18, 10) * 3)),
            $this->prestamo('9000000001', '14', '3100000', '02/03/2026', '24', '18', '2000000', '100000'),
            $this->prestamo('9000000001', '14', '3200000', '02/03/2026', '24', '18', '2000000', (string) $this->cuotaFrancesa(2_000_000, 18, 0)),
        ]));

        $salida = $this->correr(['--interpretacion' => 'restantes'])['salida'];

        $this->assertSame(['', '', '0'], array_column($this->salidaPrestamos(), 'tasa_anual'), 'Por encima del 40 % y por debajo del 0 % queda vacía; el 0 % exacto sí se acepta.');
        $this->assertStringContainsString('tasa no deducible — fuera_de_rango: 2', $salida);
        $this->assertStringContainsString('1 tasa deducida, 2 tasa no deducible', $salida);
    }

    public function test_el_limite_de_la_biseccion_acepta_el_40_por_ciento_y_rechaza_apenas_mas(): void
    {
        $servicio = app(ConversionQService::class);

        $this->assertSame(40.0, $servicio->deducirTasa(2_000_000, $this->cuotaFrancesa(2_000_000, 18, 40), 18)['tasa']);
        $this->assertSame('fuera_de_rango', $servicio->deducirTasa(2_000_000, $this->cuotaFrancesa(2_000_000, 18, 41), 18)['motivo']);
        $this->assertSame('sin_datos', $servicio->deducirTasa(2_000_000, 150000, 0)['motivo']);
    }

    public function test_en_vivienda_la_cuota_inflada_por_seguros_no_da_tasa_pero_en_otras_modalidades_si(): void
    {
        $this->escribir('q09.csv', $this->q09([$this->empleado('9000000001', 'Ejemplo Uno', 'PROFESOR', '9000000')]));
        $this->escribir('q15.csv', $this->q15([
            $this->prestamo('9000000001', '30', '120000000', '02/03/2026', '240', '200', '100000000', (string) $this->cuotaFrancesa(100_000_000, 200, 5)),
            $this->prestamo('9000000001', '33', '110000000', '02/03/2026', '240', '200', '100000000', (string) ($this->cuotaFrancesa(100_000_000, 200, 5) * 1.25)),
            $this->prestamo('9000000001', '14', '3000000', '02/03/2026', '24', '18', '2000000', (string) ($this->cuotaFrancesa(2_000_000, 18, 10) * 1.05)),
        ]));

        $salida = $this->correr(['--interpretacion' => 'restantes'])['salida'];

        $tasas = array_column($this->salidaPrestamos(), 'tasa_anual', 'modalidad');
        $this->assertSame('5', $tasas['Vivienda — compra de inmueble'], 'La cuota sin seguros da la tasa del tope.');
        $this->assertSame('', $tasas['Vivienda — cancelación total de hipoteca (deshipoteca)'], 'La cuota inflada se aparta más de 2 puntos de la tasa del aplicativo: se descarta.');
        $this->assertGreaterThan(10.0, (float) $tasas['Educación'], 'Fuera de vivienda no se descarta por la diferencia.');
        $this->assertStringContainsString('tasa no deducible — vivienda_descartada: 1', $salida);
    }

    public function test_la_interpretacion_se_decide_solo_con_la_tasa_deducida_cuando_faltan_tasas_del_ano(): void
    {
        // 2019 no tiene tasa en la base: no hay saldos que comparar, solo la deducción.
        $this->escribir('q09.csv', $this->q09([$this->empleado('9000000001', 'Ejemplo Uno', 'PROFESOR', '5000000')]));
        $this->escribir('q15.csv', $this->q15([
            $this->prestamo('9000000001', '14', '3000000', '02/03/2019', '24', '18', '2000000', (string) $this->cuotaFrancesa(2_000_000, 18, 8)),
            $this->prestamo('9000000001', '14', '3100000', '03/03/2019', '24', '18', '2500000', (string) $this->cuotaFrancesa(2_500_000, 18, 9)),
            $this->prestamo('9000000001', '14', '3200000', '04/03/2019', '24', '18', '1500000', (string) $this->cuotaFrancesa(1_500_000, 18, 10)),
        ]));

        $salida = $this->correr()['salida'];

        $this->assertStringContainsString('interpretación usada: restantes (tasa deducida plausible', $salida);
        $this->assertStringContainsString('restantes 3, pagadas 0', $salida);
        $this->assertSame(['6', '6', '6'], array_column($this->salidaPrestamos(), 'cuotas_pagadas'));
        $this->assertSame(['8', '9', '10'], array_column($this->salidaPrestamos(), 'tasa_anual'));
    }

    public function test_si_quincena_nueva_son_pagadas_la_tasa_deducida_tambien_lo_detecta(): void
    {
        $this->escribir('q09.csv', $this->q09([$this->empleado('9000000001', 'Ejemplo Uno', 'PROFESOR', '5000000')]));
        $this->escribir('q15.csv', $this->q15([
            $this->prestamo('9000000001', '14', '3000000', '02/03/2019', '24', '6', '2000000', (string) $this->cuotaFrancesa(2_000_000, 18, 8)),
            $this->prestamo('9000000001', '14', '3100000', '03/03/2019', '24', '6', '2500000', (string) $this->cuotaFrancesa(2_500_000, 18, 9)),
            $this->prestamo('9000000001', '14', '3200000', '04/03/2019', '24', '6', '1500000', (string) $this->cuotaFrancesa(1_500_000, 18, 10)),
        ]));

        $salida = $this->correr()['salida'];

        $this->assertStringContainsString('interpretación usada: pagadas (tasa deducida plausible', $salida);
        $this->assertSame(['6', '6', '6'], array_column($this->salidaPrestamos(), 'cuotas_pagadas'));
        $this->assertSame(['8', '9', '10'], array_column($this->salidaPrestamos(), 'tasa_anual'), 'Con 18 quincenas restantes (24 − 6) la tasa es la misma.');
    }

    public function test_la_opcion_interpretacion_fuerza_la_lectura_aunque_la_tasa_deducida_diga_otra_cosa(): void
    {
        $this->escribir('q09.csv', $this->q09([$this->empleado('9000000001', 'Ejemplo Uno', 'PROFESOR', '5000000')]));
        $this->escribir('q15.csv', $this->q15([$this->prestamo('9000000001', '14', '3000000', '02/03/2019', '24', '18', '2000000', (string) $this->cuotaFrancesa(2_000_000, 18, 8))]));

        $salida = $this->correr('pagadas')['salida'];

        $this->assertStringContainsString('interpretación usada: pagadas (indicada con --interpretacion)', $salida);
        $this->assertSame('18', $this->salidaPrestamos()[0]['cuotas_pagadas']);
        $this->assertSame('', $this->salidaPrestamos()[0]['tasa_anual'], 'Leída como pagadas quedarían 6 quincenas: la cuota no cuadra con ninguna tasa válida.');
    }

    public function test_el_informe_resume_la_tasa_deducida_por_ano_sin_identificar_a_nadie(): void
    {
        $this->escribir('q09.csv', $this->q09([$this->empleado('9000000001', 'Ejemplo Uno', 'PROFESOR', '5000000')]));
        $this->escribir('q15.csv', $this->q15([
            $this->prestamo('9000000001', '14', '3000000', '02/03/2019', '24', '18', '2000000', (string) $this->cuotaFrancesa(2_000_000, 18, 8)),
            $this->prestamo('9000000001', '14', '3100000', '03/03/2019', '24', '18', '2500000', (string) $this->cuotaFrancesa(2_500_000, 18, 9)),
            $this->prestamo('9000000001', '14', '3200000', '04/03/2019', '24', '18', '1500000', (string) $this->cuotaFrancesa(1_500_000, 18, 11)),
            $this->prestamo('9000000001', '14', '3300000', '02/03/2020', '24', '18', '1500000', (string) $this->cuotaFrancesa(1_500_000, 18, 14)),
        ]));

        $salida = $this->correr(['--interpretacion' => 'restantes'])['salida'];

        $this->assertStringContainsString('tasa deducida por año del préstamo 2019: 3 préstamos, mínimo 8 %, mediana 9 %, máximo 11 %', $salida);
        $this->assertStringContainsString('tasa deducida por año del préstamo 2020: 1 préstamo(s) (menos de 3 en el año: no se detalla)', $salida);
        $this->assertStringNotContainsString('14 %', $salida, 'Un año con un solo préstamo no muestra su tasa.');
        $this->assertStringContainsString('4 tasa deducida, 0 tasa no deducible', $salida);
    }

    public function test_tasa_vacia_deja_tasa_anual_en_blanco_y_una_opcion_invalida_falla(): void
    {
        $this->escribir('q09.csv', $this->q09([$this->empleado('9000000001', 'Ejemplo Uno', 'PROFESOR', '5000000')]));
        $this->escribir('q15.csv', $this->q15([$this->prestamo('9000000001', '14', '3000000', '02/03/2026', '24', '18', '2000000', (string) $this->cuotaFrancesa(2_000_000, 18, 10))]));

        $salida = $this->correr(['--interpretacion' => 'restantes', '--tasa' => 'vacia'])['salida'];

        $this->assertSame('', $this->salidaPrestamos()[0]['tasa_anual']);
        $this->assertStringContainsString('Tasa anual (modo vacia): 0 tasa deducida', $salida);

        ['codigo' => $codigo, 'salida' => $mensaje] = $this->correr(['--tasa' => 'inventada']);
        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('--tasa debe ser derivada o vacia', $mensaje);
    }

    public function test_por_defecto_la_tasa_es_derivada(): void
    {
        $this->escribir('q09.csv', $this->q09([$this->empleado('9000000001', 'Ejemplo Uno', 'PROFESOR', '5000000')]));
        $this->escribir('q15.csv', $this->q15([$this->prestamo('9000000001', '14', '3000000', '02/03/2026', '24', '18', '2000000', (string) $this->cuotaFrancesa(2_000_000, 18, 10))]));

        $salida = $this->correr(['--interpretacion' => 'restantes'])['salida'];

        $this->assertSame('10', $this->salidaPrestamos()[0]['tasa_anual']);
        $this->assertStringContainsString('Tasa anual (modo derivada): 1 tasa deducida', $salida);
    }

    public function test_el_informe_de_tasas_no_trae_datos_personales(): void
    {
        $this->escribir('q09.csv', $this->q09([$this->empleado('9000000001', 'Ejemplo Uno Secreto', 'PROFESOR', '4.321.987')]));
        $this->escribir('q15.csv', $this->q15([$this->prestamo('9000000001', '14', '3.456.789', '02/03/2019', '24', '18', '2.987.654', (string) $this->cuotaFrancesa(2_987_654, 18, 9))]));

        $salida = $this->correr()['salida'];

        foreach (['9000000001', 'Ejemplo Uno', 'Secreto', '4321987', '3456789', '3.456.789', '2987654', '2.987.654'] as $dato) {
            $this->assertStringNotContainsString($dato, $salida);
        }
    }

    public function test_una_modalidad_del_mapeo_que_no_existe_en_la_base_sale_en_el_informe_y_no_se_convierte(): void
    {
        config(['conversion_q.mapeo.30' => 'Vivienda inventada']);
        $this->escribir('q09.csv', $this->q09([$this->empleado('9000000001', 'Ejemplo Uno', 'PROFESOR', '5000000')]));
        $this->escribir('q15.csv', $this->q15([$this->prestamo('9000000001', '30', '90000000', '02/03/2026', '120', '60', '1')]));

        $salida = $this->correr()['salida'];

        $this->assertSame([], $this->leerSalida('prestamos-q15.csv'));
        $this->assertStringContainsString('modalidad del mapeo que no existe en la base — Vivienda inventada: 1', $salida);
    }

    public function test_las_cedulas_de_la_cartera_sin_empleado_van_al_informe_y_no_al_csv(): void
    {
        $this->escribir('q09.csv', $this->q09([$this->empleado('9000000001', 'Ejemplo Uno', 'PROFESOR', '5000000'), $this->empleado('9000000002', 'Ejemplo Dos', 'PROFESOR', '0')]));
        $this->escribir('q15.csv', $this->q15([
            $this->prestamo('9000000001', '14', '3000000', '02/03/2026', '24', '18', '1'),
            $this->prestamo('9000000099', '14', '3000000', '02/03/2026', '24', '18', '1'),
            $this->prestamo('9000000099', '15', '2000000', '02/03/2026', '24', '18', '1'),
            $this->prestamo('9000000002', '14', '3000000', '02/03/2026', '24', '18', '1'),
        ]));

        $salida = $this->correr()['salida'];

        $this->assertSame(['9000000001'], array_column($this->leerSalida('prestamos-q15.csv'), 'cedula'));
        $this->assertStringContainsString('cédulas de la cartera sin empleado convertido: 2', $salida, 'La 99 (no está en Q09) y la 02 (omitida por salario cero).');
    }

    public function test_los_datos_invalidos_de_la_cartera_se_cuentan_y_no_se_convierten(): void
    {
        $this->escribir('q09.csv', $this->q09([$this->empleado('9000000001', 'Ejemplo Uno', 'PROFESOR', '5000000')]));
        $this->escribir('q15.csv', $this->q15([
            $this->prestamo('9000000001', '14', 'mucho', '02/03/2026', '24', '18', '1'),
            $this->prestamo('9000000001', '14', '3000000', '2026-03-02', '24', '18', '1'),
            $this->prestamo('9000000001', '14', '3000000', '02/03/2026', '0', '0', '1'),
            $this->prestamo('9000000001', '14', '3000000', '02/03/2026', '24', '30', '1'),
        ]));

        $salida = $this->correr()['salida'];

        $this->assertSame([], $this->leerSalida('prestamos-q15.csv'));
        $this->assertStringContainsString('datos inválidos — monto: 1', $salida);
        $this->assertStringContainsString('datos inválidos — fecha: 1', $salida);
        $this->assertStringContainsString('datos inválidos — plazo: 1', $salida);
        $this->assertStringContainsString('datos inválidos — cuotas: 1', $salida);
    }

    // ───────── Seguridad, privacidad y alcance ─────────

    public function test_falla_con_aviso_claro_si_faltan_los_archivos(): void
    {
        $this->escribir('q09.csv', $this->q09([]));

        ['codigo' => $codigo, 'salida' => $salida] = $this->correr();

        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('Falta el archivo q15.csv', $salida);
    }

    public function test_falla_si_la_carpeta_no_esta_ignorada_por_git(): void
    {
        config(['conversion_q.verificar_git' => true, 'conversion_q.carpeta' => base_path('tests/_carpeta_no_ignorada_conversion_q')]);

        ['codigo' => $codigo, 'salida' => $salida] = $this->correr();

        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('no está ignorada por git', $salida);
        $this->assertDirectoryDoesNotExist(base_path('tests/_carpeta_no_ignorada_conversion_q'));
    }

    public function test_la_carpeta_real_por_defecto_esta_ignorada_por_git(): void
    {
        $this->assertSame(storage_path('app/private/real'), (require base_path('config/conversion_q.php'))['carpeta']);

        config(['conversion_q.verificar_git' => true, 'conversion_q.carpeta' => storage_path('app/private/real/__carpeta_de_prueba_inexistente__')]);

        ['salida' => $salida] = $this->correr();

        $this->assertStringContainsString('Falta el archivo q09.csv', $salida, 'Pasó la verificación de git y se detuvo por los archivos, que no existen.');
        $this->assertStringNotContainsString('no está ignorada', $salida);
    }

    public function test_el_informe_solo_trae_agregados_y_ningun_dato_personal(): void
    {
        $this->escribir('q09.csv', $this->q09([$this->empleado('9000000001', 'Ejemplo Uno Secreto', 'PROFESOR', '4.321.987', 'PLANTA'), $this->empleado('9000000002', 'Ejemplo Dos', 'PROFESOR', '0')]));
        $this->escribir('q15.csv', $this->q15([$this->prestamo('9000000001', '14', '3.456.789', '02/03/2026', '24', '18', '2.987.654'), $this->prestamo('9000000099', '14', '1.111.111', '02/03/2026', '24', '18', '1')]));

        $salida = $this->correr()['salida'];

        foreach (['9000000001', '9000000002', '9000000099', 'Ejemplo Uno', 'Secreto', 'Ejemplo Dos', '4321987', '4.321.987', '3456789', '3.456.789', '2987654', '2.987.654', '1111111'] as $dato) {
            $this->assertStringNotContainsString($dato, $salida);
        }
    }

    public function test_un_archivo_con_formato_inesperado_falla_sin_mostrar_su_contenido(): void
    {
        $this->escribir('q09.csv', "Titulo\r\nColumna1;Columna2\r\n9000000001;Ejemplo Secreto\r\n");
        $this->escribir('q15.csv', $this->q15([]));

        ['codigo' => $codigo, 'salida' => $salida] = $this->correr();

        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('No se encontró la fila de encabezados esperada en q09', $salida);
        $this->assertStringNotContainsString('Secreto', $salida);
    }

    public function test_no_es_parte_del_producto_no_hay_rutas_ni_permisos_de_la_conversion(): void
    {
        foreach (Route::getRoutes()->getRoutes() as $ruta) {
            $this->assertStringNotContainsString('conversion', strtolower($ruta->uri()));
            $this->assertStringNotContainsString('convertir', strtolower($ruta->uri()));
        }

        foreach (array_keys(config('permisos.catalogo')) as $permiso) {
            $this->assertStringNotContainsString('convers', $permiso);
        }

        $this->assertStringContainsString('TEMPORAL', file_get_contents(base_path('docs/conversion-q.md')));
    }
}
