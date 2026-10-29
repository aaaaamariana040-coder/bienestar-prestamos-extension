<?php

namespace Tests\Feature;

use App\Enums\EstadoCuota;
use App\Enums\EstadoPrestamo;
use App\Enums\TipoVinculacion;
use App\Models\Auditoria;
use App\Models\CuantiaSmmlv;
use App\Models\Cuota;
use App\Models\Desembolso;
use App\Models\Empleado;
use App\Models\Pago;
use App\Models\Prestamo;
use App\Models\PresupuestoAnual;
use App\Models\PresupuestoHistorial;
use App\Models\SmmlvAnual;
use App\Models\TasaInt;
use App\Models\TipoPrestamo;
use App\Models\Usuario;
use App\Services\CalculoFinancieroService;
use App\Services\CalendarioQuincenalService;
use App\Services\CargaPrestamosVigentesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Pantalla «Cargar préstamos vigentes»: plantilla, vista previa que no guarda, carga todo o nada,
 * reversión del lote y permiso. Las cédulas y los nombres son ficticios.
 */
class CargaPrestamosVigentesTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $administrador;

    private TipoPrestamo $educacion;

    private TipoPrestamo $calamidad;

    private Empleado $empleado;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->travelTo('2026-10-20 10:00:00');
        config(['quincena.regla_vencimiento' => 'calendario', 'quincena.modo_marcado' => 'confirmar']);

        $this->administrador = Usuario::factory()->administrador()->create();
        $this->educacion = TipoPrestamo::factory()->create(['nombre' => 'Educación', 'plazo_maximo' => 72]);
        $this->calamidad = TipoPrestamo::factory()->create(['nombre' => 'Calamidad doméstica y necesidades especiales', 'plazo_maximo' => 72]);

        TasaInt::factory()->create(['id_tipo' => $this->educacion->id_tipo, 'anio' => 2025, 'tasa_anual' => 8, 'created_at' => '2025-01-02 08:00:00']);
        TasaInt::factory()->create(['id_tipo' => $this->educacion->id_tipo, 'anio' => 2026, 'tasa_anual' => 10, 'created_at' => '2026-01-02 08:00:00']);
        TasaInt::factory()->create(['id_tipo' => $this->calamidad->id_tipo, 'anio' => 2026, 'tasa_anual' => 6, 'created_at' => '2026-01-02 08:00:00']);

        SmmlvAnual::factory()->create(['anio' => 2026, 'valor' => 1_750_905]);
        CuantiaSmmlv::factory()->create(['id_tipo' => $this->educacion->id_tipo, 'cuantia_maxima_smmlv' => 130]);
        CuantiaSmmlv::factory()->create(['id_tipo' => $this->calamidad->id_tipo, 'cuantia_maxima_smmlv' => 1]);

        $this->empleado = Empleado::factory()->create(['cedula' => '1000000002', 'salario' => 3_000_000, 'tipo_vinculacion' => TipoVinculacion::Vinculado]);
    }

    // ───────── Ayudas ─────────

    /**
     * @param  array<string, string>  $cambios
     * @return array<string, string>
     */
    private function fila(array $cambios = []): array
    {
        return array_merge([
            'cedula' => '1000000002',
            'modalidad' => 'Educación',
            'monto_inicial' => '3.000.000',
            'fecha_desembolso' => '2026-03-02',
            'plazo_quincenas' => '24',
            'cuotas_pagadas' => '2',
            'tasa_anual' => '',
            'puntos_adicionales' => '',
            'saldo_actual' => '',
        ], $cambios);
    }

    /**
     * @param  list<array<string, string>>  $filas
     */
    private function csv(array $filas): string
    {
        $lineas = [implode(';', CargaPrestamosVigentesService::COLUMNAS)];

        foreach ($filas as $fila) {
            $lineas[] = implode(';', array_map(fn (string $columna): string => $fila[$columna] ?? '', CargaPrestamosVigentesService::COLUMNAS));
        }

        return "\xEF\xBB\xBF".implode("\r\n", $lineas)."\r\n";
    }

    /**
     * @param  list<array<string, string>>  $filas
     */
    private function previa(array $filas, ?Usuario $actor = null): TestResponse
    {
        return $this->actingAs($actor ?? $this->administrador)->post(route('cargar-prestamos-vigentes.vista-previa'), [
            'archivo' => UploadedFile::fake()->createWithContent('cartera-ficticia.csv', $this->csv($filas)),
        ]);
    }

    /**
     * @param  list<array<string, string>>  $filas
     * @return array<string, mixed>
     */
    private function analisis(array $filas): array
    {
        return $this->previa($filas)->assertOk()->viewData('analisis');
    }

    /**
     * Sube el archivo, confirma y devuelve el identificador del lote creado.
     *
     * @param  list<array<string, string>>  $filas
     */
    private function cargar(array $filas): string
    {
        $token = $this->previa($filas)->assertOk()->viewData('token');

        $this->actingAs($this->administrador)->post(route('cargar-prestamos-vigentes.confirmar'), ['token' => $token, 'nombre_archivo' => 'cartera-ficticia.csv'])->assertRedirect();

        return (string) Prestamo::query()->orderByDesc('id_prestamo')->value('lote_carga');
    }

    private function conEstado(array $analisis, string $estado): int
    {
        return count(array_filter($analisis['filas'], fn (array $fila): bool => $fila['estado'] === $estado));
    }

    private function mensajeDe(array $analisis, int $indice, string $tipo = 'errores'): string
    {
        return implode(' | ', $analisis['filas'][$indice][$tipo]);
    }

    // ───────── Plantilla ─────────

    public function test_la_plantilla_es_un_csv_utf8_con_bom_punto_y_coma_columnas_y_tres_ejemplos_de_demostracion(): void
    {
        $respuesta = $this->actingAs($this->administrador)->get(route('cargar-prestamos-vigentes.plantilla'))->assertOk();

        $contenido = $respuesta->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $contenido);
        $this->assertStringContainsString('text/csv; charset=UTF-8', (string) $respuesta->headers->get('Content-Type'));
        $this->assertStringContainsString('plantilla-prestamos-vigentes.csv', (string) $respuesta->headers->get('Content-Disposition'));

        $lineas = preg_split('/\r\n/', trim(substr($contenido, 3)));
        $this->assertCount(4, $lineas);
        $this->assertSame(CargaPrestamosVigentesService::COLUMNAS, explode(';', $lineas[0]));
        $this->assertSame(['cedula', 'modalidad', 'monto_inicial', 'fecha_desembolso', 'plazo_quincenas', 'cuotas_pagadas', 'tasa_anual', 'puntos_adicionales', 'saldo_actual'], explode(';', $lineas[0]));

        foreach (array_slice($lineas, 1) as $linea) {
            $this->assertMatchesRegularExpression('/^1000000\d{3};/', $linea, 'Los ejemplos usan cédulas ficticias de demostración (1000000xxx).');
        }
    }

    public function test_la_plantilla_descargada_se_puede_subir_tal_cual_sin_errores_de_formato(): void
    {
        $plantilla = $this->actingAs($this->administrador)->get(route('cargar-prestamos-vigentes.plantilla'))->getContent();

        $analisis = $this->actingAs($this->administrador)->post(route('cargar-prestamos-vigentes.vista-previa'), [
            'archivo' => UploadedFile::fake()->createWithContent('plantilla.csv', $plantilla),
        ])->assertOk()->viewData('analisis');

        $this->assertSame([], $analisis['errores_archivo']);
        $this->assertSame(3, $analisis['resumen']['total']);
    }

    // ───────── Vista previa ─────────

    public function test_la_vista_previa_valida_sin_guardar_nada(): void
    {
        $analisis = $this->analisis([$this->fila()]);

        $this->assertSame(1, $this->conEstado($analisis, 'correcta'));
        $this->assertSame(0, Prestamo::count());
        $this->assertSame(0, Cuota::count());
        $this->assertSame(0, Desembolso::count());
        $this->assertSame(0, Auditoria::where('funcion_realizada', 'Carga de préstamos vigentes')->count());
    }

    public function test_la_vista_previa_muestra_los_tres_estados_con_el_mensaje_de_cada_problema(): void
    {
        $respuesta = $this->previa([
            $this->fila(),
            $this->fila(['modalidad' => 'Calamidad doméstica y necesidades especiales', 'monto_inicial' => '5.000.000', 'plazo_quincenas' => '12', 'cuotas_pagadas' => '1']),
            $this->fila(['cedula' => '9999999999']),
        ])->assertOk();

        $respuesta->assertSee('Correcta')->assertSee('Con advertencia')->assertSee('Con error')
            ->assertSee('El empleado con cédula 9999999999 no existe.')
            ->assertSee('El monto es mayor que la cuantía máxima de la modalidad');
        $this->assertSame(['total' => 3, 'correctas' => 1, 'advertencias' => 1, 'errores' => 1], $respuesta->viewData('analisis')['resumen']);
    }

    public function test_empleado_inexistente_es_error(): void
    {
        $analisis = $this->analisis([$this->fila(['cedula' => '1000000777'])]);

        $this->assertSame('error', $analisis['filas'][0]['estado']);
        $this->assertStringContainsString('no existe', $this->mensajeDe($analisis, 0));
    }

    public function test_modalidad_inexistente_es_error_y_el_nombre_se_reconoce_sin_importar_mayusculas_ni_tildes(): void
    {
        $analisis = $this->analisis([$this->fila(['modalidad' => 'Hipoteca inventada']), $this->fila(['modalidad' => 'EDUCACION', 'monto_inicial' => '2.000.000'])]);

        $this->assertSame('error', $analisis['filas'][0]['estado']);
        $this->assertStringContainsString('La modalidad «Hipoteca inventada» no existe', $this->mensajeDe($analisis, 0));
        $this->assertSame('correcta', $analisis['filas'][1]['estado']);
    }

    public function test_monto_o_plazo_invalidos_son_error(): void
    {
        $analisis = $this->analisis([
            $this->fila(['monto_inicial' => 'mucho']),
            $this->fila(['monto_inicial' => '0']),
            $this->fila(['monto_inicial' => '1.000.000,555']),
            $this->fila(['plazo_quincenas' => '0']),
            $this->fila(['plazo_quincenas' => 'doce']),
        ]);

        $this->assertSame(5, $this->conEstado($analisis, 'error'));
        $this->assertStringContainsString('El monto inicial no es válido', $this->mensajeDe($analisis, 0));
        $this->assertStringContainsString('El monto inicial no es válido', $this->mensajeDe($analisis, 1));
        $this->assertStringContainsString('El monto inicial no es válido', $this->mensajeDe($analisis, 2));
        $this->assertStringContainsString('El plazo no es válido', $this->mensajeDe($analisis, 3));
        $this->assertStringContainsString('El plazo no es válido', $this->mensajeDe($analisis, 4));
    }

    public function test_plazo_mayor_al_maximo_de_la_modalidad_es_error(): void
    {
        $analisis = $this->analisis([$this->fila(['plazo_quincenas' => '73', 'cuotas_pagadas' => '0'])]);

        $this->assertSame('error', $analisis['filas'][0]['estado']);
        $this->assertStringContainsString('es mayor que el máximo de la modalidad (72 quincenas)', $this->mensajeDe($analisis, 0));
    }

    public function test_fecha_invalida_es_error(): void
    {
        $analisis = $this->analisis([
            $this->fila(['fecha_desembolso' => '02/03/2026']),
            $this->fila(['fecha_desembolso' => '2026-02-30']),
            $this->fila(['fecha_desembolso' => '']),
        ]);

        $this->assertSame(3, $this->conEstado($analisis, 'error'));
        $this->assertStringContainsString('La fecha de desembolso no es válida', $this->mensajeDe($analisis, 0));
        $this->assertStringContainsString('La fecha de desembolso no es válida', $this->mensajeDe($analisis, 1));
    }

    public function test_cuotas_pagadas_mayor_al_plazo_es_error(): void
    {
        $analisis = $this->analisis([$this->fila(['plazo_quincenas' => '24', 'cuotas_pagadas' => '25']), $this->fila(['monto_inicial' => '2.000.000', 'cuotas_pagadas' => '-1'])]);

        $this->assertSame('error', $analisis['filas'][0]['estado']);
        $this->assertStringContainsString('Las cuotas pagadas (25) son más que el plazo (24 quincenas)', $this->mensajeDe($analisis, 0));
        $this->assertSame('error', $analisis['filas'][1]['estado']);
    }

    public function test_fila_duplicada_en_el_archivo_y_prestamo_ya_existente_son_error(): void
    {
        $analisis = $this->analisis([$this->fila(), $this->fila()]);

        $this->assertSame('correcta', $analisis['filas'][0]['estado']);
        $this->assertSame('error', $analisis['filas'][1]['estado']);
        $this->assertStringContainsString('Fila duplicada: es igual a la fila 2 del archivo', $this->mensajeDe($analisis, 1));

        $this->cargar([$this->fila()]);

        $analisis = $this->analisis([$this->fila()]);
        $this->assertSame('error', $analisis['filas'][0]['estado']);
        $this->assertStringContainsString('Ya existe un préstamo igual', $this->mensajeDe($analisis, 0));
    }

    public function test_docente_ocasional_es_error(): void
    {
        Empleado::factory()->create(['cedula' => '1000000008', 'tipo_vinculacion' => TipoVinculacion::DocenteOcasional]);

        $analisis = $this->analisis([$this->fila(['cedula' => '1000000008'])]);

        $this->assertSame('error', $analisis['filas'][0]['estado']);
        $this->assertStringContainsString('Los docentes ocasionales no son beneficiarios de crédito del Fondo.', $this->mensajeDe($analisis, 0));
    }

    public function test_ano_de_desembolso_sin_tasa_es_error_con_ese_mensaje_salvo_que_el_archivo_traiga_la_tasa(): void
    {
        $analisis = $this->analisis([
            $this->fila(['fecha_desembolso' => '2019-05-02']),
            $this->fila(['fecha_desembolso' => '2019-05-02', 'monto_inicial' => '2.000.000', 'tasa_anual' => '9,5']),
        ]);

        $this->assertSame('error', $analisis['filas'][0]['estado']);
        $this->assertStringContainsString('No hay una tasa de interés registrada para «Educación» en el año 2019 (año del desembolso)', $this->mensajeDe($analisis, 0));
        $this->assertNotSame('error', $analisis['filas'][1]['estado']);
        $this->assertSame(9.5, $analisis['filas'][1]['calculo']['tasa']);
    }

    public function test_las_advertencias_no_bloquean_la_carga(): void
    {
        $analisis = $this->analisis([
            $this->fila(['modalidad' => 'Calamidad doméstica y necesidades especiales', 'monto_inicial' => '5.000.000', 'plazo_quincenas' => '12', 'cuotas_pagadas' => '1']),
            $this->fila(['monto_inicial' => '2.000.000', 'saldo_actual' => '1.000.000']),
            $this->fila(['monto_inicial' => '2.500.000', 'fecha_desembolso' => '2026-01-01']),
        ]);

        $this->assertSame(3, $this->conEstado($analisis, 'advertencia'));
        $this->assertStringContainsString('mayor que la cuantía máxima', $this->mensajeDe($analisis, 0, 'advertencias'));
        $this->assertStringContainsString('difiere en más de 1 peso del calculado', $this->mensajeDe($analisis, 1, 'advertencias'));
        $this->assertStringContainsString('anterior al registro de la tasa de 2026 (2026-01-02)', $this->mensajeDe($analisis, 2, 'advertencias'));
        $this->assertNotNull($analisis['filas'][2]['calculo'], 'Una advertencia no impide calcular la fila.');
    }

    public function test_un_saldo_que_difiere_menos_de_un_peso_no_genera_advertencia(): void
    {
        $calculado = $this->analisis([$this->fila()])['filas'][0]['calculo']['saldo'];

        $analisis = $this->analisis([$this->fila(['saldo_actual' => number_format($calculado + 0.5, 2, ',', '.')])]);

        $this->assertSame('correcta', $analisis['filas'][0]['estado']);
    }

    public function test_los_formatos_colombianos_de_monto_y_tasa_se_entienden(): void
    {
        $analisis = $this->analisis([
            $this->fila(['monto_inicial' => '$ 4.500.000,50', 'tasa_anual' => '10,5']),
            $this->fila(['monto_inicial' => '4,500,000.25', 'tasa_anual' => '10.25 %', 'puntos_adicionales' => '2,5']),
            $this->fila(['monto_inicial' => '4500000', 'puntos_adicionales' => '1,5']),
        ]);

        $this->assertSame(4_500_000.5, $analisis['filas'][0]['calculo']['monto']);
        $this->assertSame(10.5, $analisis['filas'][0]['calculo']['tasa']);
        $this->assertSame(4_500_000.25, $analisis['filas'][1]['calculo']['monto']);
        $this->assertSame(10.25, $analisis['filas'][1]['calculo']['tasa'], 'Con la tasa dada no se suman los puntos.');
        $this->assertSame(11.5, $analisis['filas'][2]['calculo']['tasa'], 'Tasa del año (10) más 1,5 puntos.');
    }

    public function test_una_tasa_o_unos_puntos_mal_escritos_son_error(): void
    {
        $analisis = $this->analisis([$this->fila(['tasa_anual' => 'abc']), $this->fila(['monto_inicial' => '2.000.000', 'puntos_adicionales' => '150'])]);

        $this->assertStringContainsString('La tasa anual no es válida', $this->mensajeDe($analisis, 0));
        $this->assertStringContainsString('Los puntos adicionales no es válida', $this->mensajeDe($analisis, 1));
    }

    public function test_un_archivo_sin_columnas_obligatorias_o_vacio_se_rechaza_entero(): void
    {
        $sinColumnas = $this->actingAs($this->administrador)->post(route('cargar-prestamos-vigentes.vista-previa'), [
            'archivo' => UploadedFile::fake()->createWithContent('malo.csv', "cedula;modalidad\r\n1000000002;Educación\r\n"),
        ])->assertOk();

        $this->assertStringContainsString('Faltan columnas obligatorias', $sinColumnas->viewData('analisis')['errores_archivo'][0]);
        $this->assertNull($sinColumnas->viewData('token'));

        $soloEncabezado = $this->analisis([]);
        $this->assertStringContainsString('no tiene filas', $soloEncabezado['errores_archivo'][0]);
    }

    public function test_el_archivo_con_coma_o_en_latin1_tambien_se_lee(): void
    {
        $contenido = utf8_decode("cedula,modalidad,monto_inicial,fecha_desembolso,plazo_quincenas,cuotas_pagadas\n1000000002,Educación,\"3.000.000\",2026-03-02,24,2\n");

        $analisis = $this->actingAs($this->administrador)->post(route('cargar-prestamos-vigentes.vista-previa'), [
            'archivo' => UploadedFile::fake()->createWithContent('latin.csv', $contenido),
        ])->assertOk()->viewData('analisis');

        $this->assertSame('correcta', $analisis['filas'][0]['estado']);
    }

    public function test_sin_archivo_la_vista_previa_pide_el_archivo(): void
    {
        $this->actingAs($this->administrador)->from(route('cargar-prestamos-vigentes.index'))
            ->post(route('cargar-prestamos-vigentes.vista-previa'), [])
            ->assertSessionHasErrors('archivo');
    }

    // ───────── Carga ─────────

    public function test_confirmar_crea_prestamo_desembolso_y_cuotas_con_la_tasa_del_ano_de_desembolso(): void
    {
        $lote = $this->cargar([$this->fila(['fecha_desembolso' => '2025-06-02', 'monto_inicial' => '3.000.000,50', 'plazo_quincenas' => '24', 'cuotas_pagadas' => '3'])]);

        $prestamo = Prestamo::with(['cuotas', 'desembolsos'])->firstOrFail();
        $calculo = app(CalculoFinancieroService::class);
        $tasaPeriodica = $calculo->tasaPeriodicaQuincenal(8);
        $cuotaEsperada = $calculo->cuotaFija(3_000_000.50, $tasaPeriodica, 24);

        $this->assertSame('1000000002', $prestamo->cedula_empleado);
        $this->assertSame(EstadoPrestamo::Vigente, $prestamo->estado);
        $this->assertFalse($prestamo->pendiente_desembolso);
        $this->assertEquals(8, $prestamo->tasa_efectiva_anual);
        $this->assertSame(TasaInt::where('anio', 2025)->where('id_tipo', $this->educacion->id_tipo)->value('id_tasa'), $prestamo->id_tasa);
        $this->assertEquals(3_000_000.50, $prestamo->monto_aprobado);
        $this->assertSame(24, $prestamo->num_cuotas);
        $this->assertEquals($cuotaEsperada, $prestamo->cuota_periodica);
        $this->assertSame('2025-06-02', $prestamo->fecha_autorizacion->toDateString());

        $this->assertCount(1, $prestamo->desembolsos);
        $desembolso = $prestamo->desembolsos->first();
        $this->assertSame('2025-06-02', $desembolso->fecha->toDateString());
        $this->assertEquals(3_000_000.50, $desembolso->valor);
        $this->assertSame(1, $desembolso->numero_tramo);

        $this->assertCount(24, $prestamo->cuotas);
        $this->assertEquals($cuotaEsperada, $prestamo->cuotas->first()->valor_total);
        $this->assertEqualsWithDelta(3_000_000.50, $prestamo->cuotas->sum('valor_capital'), 0.01);
        $this->assertSame(0, Pago::count(), 'Los pagos históricos no se reconstruyen.');
        $this->assertSame($lote, $prestamo->lote_carga);
    }

    public function test_las_cuotas_siguen_el_calendario_con_la_regla_de_la_primera_cuota_del_desembolso_real(): void
    {
        $this->cargar([$this->fila(['fecha_desembolso' => '2026-03-02'])]);

        $calendario = app(CalendarioQuincenalService::class);
        $primera = $calendario->primeraCuotaConCierre('2026-03-02');
        $prestamo = Prestamo::with('cuotas')->firstOrFail();

        $this->assertSame($calendario->vencimientosDesde($primera, 24), $prestamo->cuotas->map(fn (Cuota $c): string => $c->fecha_vencimiento->toDateString())->all());
        $this->assertSame($primera, $prestamo->inicio_primera_cuota->toDateString());
        $this->assertNull($prestamo->motivo_primera_cuota);
    }

    public function test_las_primeras_n_cuotas_quedan_pagadas_y_el_saldo_es_el_de_la_formula(): void
    {
        $this->cargar([$this->fila(['cuotas_pagadas' => '5'])]);

        $prestamo = Prestamo::with('cuotas')->firstOrFail();

        $this->assertSame([1, 2, 3, 4, 5], $prestamo->cuotas->where('estado', EstadoCuota::Pagada)->pluck('numero_cuota')->all());
        $this->assertSame(19, $prestamo->cuotas->where('estado', EstadoCuota::Pendiente)->count());
        $this->assertSame('Cuota 5 de 24', $prestamo->progreso_cuotas);

        $tabla = app(CalculoFinancieroService::class)->tablaAmortizacion(3_000_000, app(CalculoFinancieroService::class)->tasaPeriodicaQuincenal(10), 24, (float) $prestamo->cuota_periodica);
        $saldoPendiente = round((float) $prestamo->cuotas->where('estado', EstadoCuota::Pendiente)->sum('valor_capital'), 2);

        $this->assertSame($tabla[4]['saldo'], $saldoPendiente);

        $analisis = $this->analisis([$this->fila(['monto_inicial' => '3.000.000', 'fecha_desembolso' => '2026-03-03', 'cuotas_pagadas' => '5', 'saldo_actual' => number_format($saldoPendiente, 2, ',', '.')])]);
        $this->assertSame('correcta', $analisis['filas'][0]['estado']);
        $this->assertSame($saldoPendiente, $analisis['filas'][0]['calculo']['saldo']);
    }

    public function test_los_puntos_adicionales_se_suman_a_la_tasa_del_ano(): void
    {
        $this->cargar([$this->fila(['puntos_adicionales' => '2'])]);

        $this->assertEquals(12, Prestamo::firstOrFail()->tasa_efectiva_anual);
    }

    public function test_todo_o_nada_una_fila_invalida_bloquea_la_carga_completa(): void
    {
        $token = $this->previa([$this->fila(), $this->fila(['cedula' => '1000000777'])])->assertOk()->viewData('token');

        $this->actingAs($this->administrador)->post(route('cargar-prestamos-vigentes.confirmar'), ['token' => $token])
            ->assertRedirect(route('cargar-prestamos-vigentes.index'))
            ->assertSessionHasErrors('archivo');

        $this->assertSame(0, Prestamo::count());
        $this->assertSame(0, Cuota::count());
        $this->assertSame(0, Desembolso::count());
        $this->assertSame(0, Auditoria::where('funcion_realizada', 'Carga de préstamos vigentes')->count());
    }

    public function test_la_vista_previa_con_errores_deja_el_boton_de_confirmar_deshabilitado(): void
    {
        $this->previa([$this->fila(), $this->fila(['cedula' => '1000000777'])])->assertOk()
            ->assertSee('La carga está bloqueada')
            ->assertDontSee(route('cargar-prestamos-vigentes.confirmar'));
    }

    public function test_si_falla_en_medio_de_la_carga_no_queda_nada_a_medias(): void
    {
        $servicio = app(CargaPrestamosVigentesService::class);
        $contenido = $this->csv([$this->fila(), $this->fila(['monto_inicial' => '2.000.000'])]);

        Cuota::creating(function (Cuota $cuota): void {
            if (Cuota::count() >= 30) {
                throw new \RuntimeException('Falla simulada.');
            }
        });

        try {
            $servicio->cargar($contenido, 'cartera-ficticia.csv', $this->administrador);
            $this->fail('Debió fallar.');
        } catch (\RuntimeException $excepcion) {
            $this->assertSame('Falla simulada.', $excepcion->getMessage());
        }

        $this->assertSame(0, Prestamo::count());
        $this->assertSame(0, Cuota::count());
        $this->assertSame(0, Desembolso::count());
    }

    public function test_la_carga_no_toca_el_presupuesto(): void
    {
        $presupuesto = PresupuestoAnual::factory()->create(['anio' => 2026, 'id_tipo' => $this->educacion->id_tipo, 'monto_asignado' => 10_000_000, 'monto_comprometido' => 1_000_000]);

        $this->cargar([$this->fila(['monto_inicial' => '9.000.000'])]);

        $presupuesto->refresh();
        $this->assertEquals(10_000_000, $presupuesto->monto_asignado);
        $this->assertEquals(1_000_000, $presupuesto->monto_comprometido);
        $this->assertSame(0, PresupuestoHistorial::count());
    }

    public function test_los_prestamos_cargados_llevan_origen_y_lote_y_los_demas_no(): void
    {
        $normal = Prestamo::factory()->create();

        $lote = $this->cargar([$this->fila(), $this->fila(['monto_inicial' => '2.000.000'])]);

        $cargados = Prestamo::where('origen', 'carga_inicial')->get();
        $this->assertCount(2, $cargados);
        $this->assertSame([$lote], $cargados->pluck('lote_carga')->unique()->values()->all());
        $this->assertMatchesRegularExpression('/^CARGA-\d{8}-\d{6}-[A-Z0-9]{4}$/', $lote);
        $this->assertNull($normal->fresh()->origen);
        $this->assertNull($normal->fresh()->lote_carga);
    }

    public function test_el_resultado_lista_los_prestamos_con_enlace_al_detalle_y_el_lote(): void
    {
        $token = $this->previa([$this->fila(), $this->fila(['monto_inicial' => '2.000.000'])])->viewData('token');

        $respuesta = $this->actingAs($this->administrador)->post(route('cargar-prestamos-vigentes.confirmar'), ['token' => $token, 'nombre_archivo' => 'cartera-ficticia.csv']);
        $lote = Prestamo::firstOrFail()->lote_carga;

        $respuesta->assertRedirect(route('cargar-prestamos-vigentes.lote', $lote));
        $this->actingAs($this->administrador)->get(route('cargar-prestamos-vigentes.lote', $lote))->assertOk()
            ->assertSee("Carga confirmada: 2 préstamo(s) creado(s) en el lote {$lote}.")
            ->assertSee($lote)
            ->assertSee(route('prestamos.show', Prestamo::firstOrFail()), false);

        $this->actingAs($this->administrador)->get(route('prestamos.show', Prestamo::firstOrFail()))->assertOk()->assertSee('Carga inicial de cartera vigente');
    }

    public function test_la_confirmacion_se_audita_con_archivo_lote_y_cantidad(): void
    {
        $lote = $this->cargar([$this->fila(), $this->fila(['monto_inicial' => '2.000.000'])]);

        $registro = Auditoria::where('funcion_realizada', 'Carga de préstamos vigentes')->firstOrFail();
        $this->assertSame($this->administrador->cedula, $registro->cedula_usu);
        $this->assertStringContainsString('cartera-ficticia.csv', $registro->detalle);
        $this->assertStringContainsString($lote, $registro->detalle);
        $this->assertStringContainsString('2 préstamo(s)', $registro->detalle);
    }

    public function test_la_vista_previa_vencida_o_ya_cargada_no_se_puede_confirmar_otra_vez(): void
    {
        $token = $this->previa([$this->fila()])->viewData('token');

        $this->actingAs($this->administrador)->post(route('cargar-prestamos-vigentes.confirmar'), ['token' => $token])->assertRedirect();
        $this->actingAs($this->administrador)->post(route('cargar-prestamos-vigentes.confirmar'), ['token' => $token])->assertSessionHasErrors('archivo');

        $this->assertSame(1, Prestamo::count());
    }

    // ───────── Quincena e informe ─────────

    public function test_los_prestamos_cargados_entran_a_la_quincena_segun_su_proxima_cuota_y_al_informe(): void
    {
        $this->cargar([$this->fila(['fecha_desembolso' => '2026-09-01', 'cuotas_pagadas' => '1', 'plazo_quincenas' => '12'])]);

        $prestamo = Prestamo::with('cuotas')->firstOrFail();
        $proxima = $prestamo->cuotas->firstWhere('estado', EstadoCuota::Pendiente);
        $pagada = $prestamo->cuotas->firstWhere('estado', EstadoCuota::Pagada);
        $quincena = app(CalendarioQuincenalService::class)->quincenaDeFecha($proxima->fecha_vencimiento);
        $ruta = route('quincenas.vista-previa', [$quincena['anio'], $quincena['numero']]);

        $this->actingAs($this->administrador)->get($ruta)->assertOk()->assertSee($this->empleado->nombre_completo);

        $informe = $this->actingAs($this->administrador)->post(route('quincenas.excel', [$quincena['anio'], $quincena['numero']]));
        $informe->assertOk();
        $this->assertStringContainsString('1000000002', $informe->streamedContent());
        $this->assertStringContainsString($proxima->fecha_vencimiento->toDateString(), $informe->streamedContent());

        $anteriorQuincena = app(CalendarioQuincenalService::class)->quincenaDeFecha($pagada->fecha_vencimiento);
        $contenidoAnterior = $this->actingAs($this->administrador)->post(route('quincenas.excel', [$anteriorQuincena['anio'], $anteriorQuincena['numero']]))->streamedContent();
        $this->assertStringNotContainsString('1000000002', $contenidoAnterior, 'La cuota marcada como pagada no se cobra otra vez.');
    }

    // ───────── Reversión ─────────

    public function test_revertir_un_lote_sin_pagos_elimina_prestamos_cuotas_y_desembolsos_y_se_audita(): void
    {
        $normal = Prestamo::factory()->create();
        $lote = $this->cargar([$this->fila(), $this->fila(['monto_inicial' => '2.000.000'])]);

        $this->actingAs($this->administrador)->post(route('cargar-prestamos-vigentes.revertir', $lote))
            ->assertRedirect(route('cargar-prestamos-vigentes.index'))
            ->assertSessionHas('status');

        $this->assertSame([$normal->id_prestamo], Prestamo::pluck('id_prestamo')->all(), 'Solo se eliminan los préstamos del lote.');
        $this->assertSame(0, Cuota::count());
        $this->assertSame(0, Desembolso::count());

        $registro = Auditoria::where('funcion_realizada', 'Reversión de carga de préstamos vigentes')->firstOrFail();
        $this->assertStringContainsString($lote, $registro->detalle);
        $this->assertStringContainsString('2 préstamo(s)', $registro->detalle);
    }

    public function test_revertir_se_rechaza_si_algun_prestamo_del_lote_tiene_pagos_y_explica_por_que(): void
    {
        $lote = $this->cargar([$this->fila(), $this->fila(['monto_inicial' => '2.000.000'])]);
        $conPago = Prestamo::where('monto_aprobado', 3_000_000)->firstOrFail();
        Pago::factory()->create(['id_prestamo' => $conPago->id_prestamo]);

        $this->actingAs($this->administrador)->post(route('cargar-prestamos-vigentes.revertir', $lote))
            ->assertRedirect(route('cargar-prestamos-vigentes.index'))
            ->assertSessionHasErrors('lote');

        $this->assertSame(2, Prestamo::count());
        $this->assertSame(48, Cuota::count());
        $this->assertSame(0, Auditoria::where('funcion_realizada', 'Reversión de carga de préstamos vigentes')->count());

        $this->actingAs($this->administrador)->get(route('cargar-prestamos-vigentes.index'))->assertOk()
            ->assertSee('No se puede revertir')
            ->assertSee("El préstamo #{$conPago->id_prestamo} tiene 1 pago(s) registrado(s) después de la carga.");
    }

    public function test_revertir_se_rechaza_con_una_cancelacion_o_un_desembolso_adicional(): void
    {
        $lote = $this->cargar([$this->fila()]);
        $prestamo = Prestamo::firstOrFail();

        $prestamo->update(['estado' => EstadoPrestamo::Cancelado]);
        $this->assertStringContainsString('cancelación', implode(' ', app(CargaPrestamosVigentesService::class)->motivosParaNoRevertir($lote)));

        $prestamo->update(['estado' => EstadoPrestamo::Vigente]);
        Desembolso::factory()->create(['id_prestamo' => $prestamo->id_prestamo, 'numero_tramo' => 2]);
        $this->assertStringContainsString('desembolsos adicionales', implode(' ', app(CargaPrestamosVigentesService::class)->motivosParaNoRevertir($lote)));
    }

    public function test_un_lote_inexistente_no_se_puede_revertir_ni_consultar(): void
    {
        $this->actingAs($this->administrador)->post(route('cargar-prestamos-vigentes.revertir', 'CARGA-20260101-000000-ZZZZ'))->assertSessionHasErrors('lote');
        $this->actingAs($this->administrador)->get(route('cargar-prestamos-vigentes.lote', 'CARGA-20260101-000000-ZZZZ'))->assertNotFound();
    }

    // ───────── Permisos ─────────

    public function test_el_funcionario_no_tiene_acceso_a_ninguna_pantalla_de_la_carga(): void
    {
        $funcionario = Usuario::factory()->create();
        $lote = $this->cargar([$this->fila()]);

        $this->actingAs($funcionario)->get(route('cargar-prestamos-vigentes.index'))->assertForbidden();
        $this->actingAs($funcionario)->get(route('cargar-prestamos-vigentes.plantilla'))->assertForbidden();
        $this->actingAs($funcionario)->post(route('cargar-prestamos-vigentes.vista-previa'))->assertForbidden();
        $this->actingAs($funcionario)->post(route('cargar-prestamos-vigentes.confirmar'))->assertForbidden();
        $this->actingAs($funcionario)->get(route('cargar-prestamos-vigentes.lote', $lote))->assertForbidden();
        $this->actingAs($funcionario)->post(route('cargar-prestamos-vigentes.revertir', $lote))->assertForbidden();

        $this->assertSame(1, Prestamo::count());
    }

    public function test_el_permiso_es_del_administrador_por_defecto_y_no_del_funcionario(): void
    {
        $this->assertTrue(Usuario::factory()->administrador()->create()->tienePermiso('prestamos.cargar_vigentes'));
        $this->assertFalse(Usuario::factory()->create()->tienePermiso('prestamos.cargar_vigentes'));
        $this->assertContains('!prestamos.cargar_vigentes', config('permisos.defaults.FUNCIONARIO_BIENESTAR'));
        $this->assertSame(['prestamos.ver'], config('permisos.dependencias')['prestamos.cargar_vigentes']);
    }

    public function test_el_permiso_no_cambia_que_usuarios_y_auditoria_sean_solo_del_administrador_principal(): void
    {
        $administrador = Usuario::factory()->administrador()->create();

        $this->assertTrue($administrador->tienePermiso('prestamos.cargar_vigentes'));
        $this->assertFalse($administrador->tienePermiso('usuarios.gestionar'));
        $this->assertFalse($administrador->tienePermiso('auditoria.ver'));
    }

    public function test_el_menu_muestra_la_opcion_junto_a_prestamos_solo_con_el_permiso(): void
    {
        $this->actingAs($this->administrador)->get(route('inicio'))->assertOk()->assertSeeInOrder(['Préstamos', 'Cargar préstamos vigentes', 'Proveedores']);
        $this->actingAs(Usuario::factory()->create())->get(route('inicio'))->assertOk()->assertDontSee('Cargar préstamos vigentes');
    }

    public function test_el_indice_lista_los_lotes_con_su_boton_de_revertir(): void
    {
        $lote = $this->cargar([$this->fila()]);

        $this->actingAs($this->administrador)->get(route('cargar-prestamos-vigentes.index'))->assertOk()
            ->assertSee($lote)
            ->assertSee('Revertir lote')
            ->assertSee(route('cargar-prestamos-vigentes.plantilla'), false);
    }
}
