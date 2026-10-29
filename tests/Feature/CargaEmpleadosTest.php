<?php

namespace Tests\Feature;

use App\Enums\TipoVinculacion;
use App\Http\Requests\StoreEmpleadoRequest;
use App\Models\Auditoria;
use App\Models\Empleado;
use App\Models\Prestamo;
use App\Models\SmmlvAnual;
use App\Models\SolicitudCredito;
use App\Models\Usuario;
use App\Services\CargaEmpleadosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * Pantalla «Cargar empleados»: plantilla, vista previa que no guarda, omitidos, confirmación todo
 * o nada, reversión del lote, permiso y privacidad. Todo con cédulas y nombres ficticios.
 */
class CargaEmpleadosTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $administrador;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->travelTo('2026-10-29 10:00:00');
        SmmlvAnual::factory()->create(['anio' => 2026, 'valor' => 1_750_905]);

        $this->administrador = Usuario::factory()->administrador()->create();
    }

    // ───────── Ayudas ─────────

    /**
     * @param  array<string, string>  $cambios
     * @return array<string, string>
     */
    private function fila(array $cambios = []): array
    {
        return array_merge([
            'cedula' => '9000000101',
            'nombre_completo' => 'Ejemplo Uno',
            'tipo_vinculacion' => 'vinculado',
            'de_carrera' => 'si',
            'cargo' => 'Auxiliar de ejemplo',
            'dependencia' => 'Dependencia de ejemplo',
            'salario' => '3.200.000',
            'fecha_vinculacion' => '15/03/2018',
            'capacidad_endeudamiento' => '',
        ], $cambios);
    }

    /**
     * @param  list<array<string, string>>  $filas
     */
    private function csv(array $filas, string $separador = ';', bool $bom = true): string
    {
        $lineas = [implode($separador, CargaEmpleadosService::COLUMNAS)];

        foreach ($filas as $fila) {
            $lineas[] = implode($separador, array_map(fn (string $columna): string => str_contains($fila[$columna] ?? '', $separador) ? '"'.$fila[$columna].'"' : ($fila[$columna] ?? ''), CargaEmpleadosService::COLUMNAS));
        }

        return ($bom ? "\xEF\xBB\xBF" : '').implode("\r\n", $lineas)."\r\n";
    }

    private function subir(string $contenido, ?Usuario $actor = null): TestResponse
    {
        return $this->actingAs($actor ?? $this->administrador)->post(route('cargar-empleados.vista-previa'), [
            'archivo' => UploadedFile::fake()->createWithContent('empleados-ficticios.csv', $contenido),
        ]);
    }

    /**
     * @param  list<array<string, string>>  $filas
     * @return array<string, mixed>
     */
    private function analisis(array $filas): array
    {
        return $this->subir($this->csv($filas))->assertOk()->viewData('analisis');
    }

    /**
     * Sube, confirma y devuelve el lote creado.
     *
     * @param  list<array<string, string>>  $filas
     */
    private function cargar(array $filas): string
    {
        $token = $this->subir($this->csv($filas))->assertOk()->viewData('token');

        $this->actingAs($this->administrador)->post(route('cargar-empleados.confirmar'), ['token' => $token, 'nombre_archivo' => 'empleados-ficticios.csv'])->assertRedirect();

        return (string) Empleado::query()->whereNotNull('lote_carga')->orderByDesc('created_at')->value('lote_carga');
    }

    private function mensajes(array $analisis, int $indice): string
    {
        return implode(' | ', [...$analisis['filas'][$indice]['errores'], ...$analisis['filas'][$indice]['advertencias']]);
    }

    private function archivosPendientes(): int
    {
        return count(Storage::disk('local')->allFiles('cargas-empleados/pendientes'));
    }

    // ───────── Plantilla ─────────

    public function test_la_plantilla_tiene_las_columnas_del_formulario_y_tres_ejemplos_ficticios(): void
    {
        $respuesta = $this->actingAs($this->administrador)->get(route('cargar-empleados.plantilla'))->assertOk();
        $contenido = $respuesta->getContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $contenido);
        $this->assertStringContainsString('plantilla-empleados.csv', (string) $respuesta->headers->get('Content-Disposition'));

        $lineas = preg_split('/\r\n/', trim(substr($contenido, 3)));
        $this->assertCount(4, $lineas);
        $this->assertSame(['cedula', 'nombre_completo', 'tipo_vinculacion', 'de_carrera', 'cargo', 'dependencia', 'salario', 'fecha_vinculacion', 'capacidad_endeudamiento'], explode(';', $lineas[0]));

        foreach (array_slice($lineas, 1) as $linea) {
            $this->assertMatchesRegularExpression('/^9000000\d{3};Ejemplo /', $linea);
        }
    }

    public function test_las_columnas_son_los_campos_del_formulario_de_nuevo_empleado(): void
    {
        $camposDelFormulario = array_diff(array_keys((new StoreEmpleadoRequest)->rules()), ['estado']);

        $this->assertEqualsCanonicalizing(array_values($camposDelFormulario), CargaEmpleadosService::COLUMNAS);
    }

    public function test_la_plantilla_se_sube_tal_cual_sin_errores(): void
    {
        $plantilla = $this->actingAs($this->administrador)->get(route('cargar-empleados.plantilla'))->getContent();

        $analisis = $this->subir($plantilla)->assertOk()->viewData('analisis');

        $this->assertSame([], $analisis['errores_archivo']);
        $this->assertSame(3, $analisis['resumen']['total']);
        $this->assertSame(0, $analisis['resumen']['errores']);
    }

    // ───────── Vista previa ─────────

    public function test_la_vista_previa_no_guarda_nada_ni_deja_archivos_si_hay_errores(): void
    {
        $respuesta = $this->subir($this->csv([$this->fila(), $this->fila(['cedula' => '9000000102', 'salario' => 'mucho'])]))->assertOk();

        $this->assertSame(0, Empleado::count());
        $this->assertSame(0, Auditoria::where('funcion_realizada', 'Carga de empleados')->count());
        $this->assertNull($respuesta->viewData('token'));
        $this->assertSame(0, $this->archivosPendientes(), 'Con errores no se deja ningún archivo en el disco.');
        $respuesta->assertSee('Correcta')->assertSee('Con error')->assertSee('La carga está bloqueada');
    }

    public function test_cedula_vacia_o_invalida_es_error(): void
    {
        $analisis = $this->analisis([
            $this->fila(['cedula' => '']),
            $this->fila(['cedula' => '90.000.0102']),
            $this->fila(['cedula' => '12345678901']),
            $this->fila(['cedula' => 'ABC123']),
        ]);

        $this->assertSame(4, $analisis['resumen']['errores']);
        $this->assertStringContainsString('La cédula está vacía.', $this->mensajes($analisis, 0));
        $this->assertStringContainsString('solo números, máximo 10 dígitos', $this->mensajes($analisis, 1));
        $this->assertStringContainsString('solo números, máximo 10 dígitos', $this->mensajes($analisis, 2));
        $this->assertStringContainsString('solo números, máximo 10 dígitos', $this->mensajes($analisis, 3));
    }

    public function test_cedula_repetida_dentro_del_archivo_es_error(): void
    {
        $analisis = $this->analisis([$this->fila(), $this->fila(['nombre_completo' => 'Ejemplo Dos'])]);

        $this->assertSame('correcta', $analisis['filas'][0]['estado']);
        $this->assertSame('error', $analisis['filas'][1]['estado']);
        $this->assertStringContainsString('repetida: ya aparece en la fila 2', $this->mensajes($analisis, 1));
    }

    public function test_campo_obligatorio_vacio_es_error(): void
    {
        $analisis = $this->analisis([
            $this->fila(['nombre_completo' => '']),
            $this->fila(['cedula' => '9000000102', 'tipo_vinculacion' => '']),
            $this->fila(['cedula' => '9000000103', 'salario' => '']),
        ]);

        $this->assertSame(3, $analisis['resumen']['errores']);
        $this->assertStringContainsString('nombre completo', $this->mensajes($analisis, 0));
        $this->assertStringContainsString('tipo de vinculación', $this->mensajes($analisis, 1));
        $this->assertStringContainsString('salario', $this->mensajes($analisis, 2));
    }

    public function test_tipo_de_vinculacion_inexistente_es_error_y_se_aceptan_etiquetas(): void
    {
        $analisis = $this->analisis([
            $this->fila(['tipo_vinculacion' => 'contratista']),
            $this->fila(['cedula' => '9000000102', 'tipo_vinculacion' => 'Docente ocasional']),
            $this->fila(['cedula' => '9000000103', 'tipo_vinculacion' => 'DOCENTE_OCASIONAL']),
            $this->fila(['cedula' => '9000000104', 'tipo_vinculacion' => 'Jubilado']),
        ]);

        $this->assertSame('error', $analisis['filas'][0]['estado']);
        $this->assertStringContainsString('tipo de vinculación', $this->mensajes($analisis, 0));
        $this->assertSame('docente_ocasional', $analisis['filas'][1]['datos_validos']['tipo_vinculacion']);
        $this->assertSame('docente_ocasional', $analisis['filas'][2]['datos_validos']['tipo_vinculacion']);
        $this->assertSame('jubilado', $analisis['filas'][3]['datos_validos']['tipo_vinculacion']);
    }

    public function test_fechas_dd_mm_aaaa_y_aaaa_mm_dd_se_aceptan_y_las_invalidas_son_error(): void
    {
        $analisis = $this->analisis([
            $this->fila(['fecha_vinculacion' => '15/03/2018']),
            $this->fila(['cedula' => '9000000102', 'fecha_vinculacion' => '2018-03-15']),
            $this->fila(['cedula' => '9000000103', 'fecha_vinculacion' => '31/02/2018']),
            $this->fila(['cedula' => '9000000104', 'fecha_vinculacion' => '15-03-2018']),
            $this->fila(['cedula' => '9000000105', 'fecha_vinculacion' => 'ayer']),
        ]);

        $this->assertSame('2018-03-15', $analisis['filas'][0]['datos_validos']['fecha_vinculacion']);
        $this->assertSame('2018-03-15', $analisis['filas'][1]['datos_validos']['fecha_vinculacion']);

        foreach ([2, 3, 4] as $i) {
            $this->assertSame('error', $analisis['filas'][$i]['estado']);
            $this->assertStringContainsString('La fecha de ingreso no es válida', $this->mensajes($analisis, $i));
        }
    }

    public function test_salario_invalido_es_error_y_los_formatos_colombianos_se_leen(): void
    {
        $analisis = $this->analisis([
            $this->fila(['salario' => 'abc']),
            $this->fila(['cedula' => '9000000102', 'salario' => '-5']),
            $this->fila(['cedula' => '9000000103', 'salario' => '4.850.000,50']),
            $this->fila(['cedula' => '9000000104', 'salario' => '$ 3.200.000']),
        ]);

        $this->assertSame('error', $analisis['filas'][0]['estado']);
        $this->assertSame('error', $analisis['filas'][1]['estado']);
        $this->assertEquals(4_850_000.5, $analisis['filas'][2]['datos_validos']['salario']);
        $this->assertEquals(3_200_000, $analisis['filas'][3]['datos_validos']['salario']);
    }

    public function test_el_nombre_con_numeros_es_error_como_en_el_formulario_manual(): void
    {
        $analisis = $this->analisis([$this->fila(['nombre_completo' => 'Ejemplo 123'])]);

        $this->assertSame('error', $analisis['filas'][0]['estado']);
        $this->assertStringContainsString('no admite números', $this->mensajes($analisis, 0));
    }

    public function test_de_carrera_acepta_si_y_no_y_rechaza_otros_valores(): void
    {
        $analisis = $this->analisis([
            $this->fila(['de_carrera' => 'Sí']),
            $this->fila(['cedula' => '9000000102', 'de_carrera' => 'no']),
            $this->fila(['cedula' => '9000000103', 'de_carrera' => '']),
            $this->fila(['cedula' => '9000000104', 'de_carrera' => 'quizás']),
        ]);

        $this->assertTrue($analisis['filas'][0]['datos_validos']['de_carrera']);
        $this->assertFalse($analisis['filas'][1]['datos_validos']['de_carrera']);
        $this->assertFalse($analisis['filas'][2]['datos_validos']['de_carrera']);
        $this->assertSame('error', $analisis['filas'][3]['estado']);
    }

    public function test_las_advertencias_no_bloquean(): void
    {
        $analisis = $this->analisis([
            $this->fila(['tipo_vinculacion' => 'docente_ocasional']),
            $this->fila(['cedula' => '9000000102', 'salario' => '1.750.905']),
            $this->fila(['cedula' => '9000000103', 'fecha_vinculacion' => '']),
            $this->fila(['cedula' => '9000000104', 'fecha_vinculacion' => '01/01/2030']),
        ]);

        $this->assertSame(4, $analisis['resumen']['advertencias']);
        $this->assertStringContainsString('Docente ocasional', $this->mensajes($analisis, 0));
        $this->assertStringContainsString('salario mínimo', $this->mensajes($analisis, 1));
        $this->assertStringContainsString('Sin fecha de ingreso', $this->mensajes($analisis, 2));
        $this->assertStringContainsString('futura', $this->mensajes($analisis, 3));
        $this->assertNotNull($analisis['filas'][0]['datos_validos']);
    }

    public function test_el_separador_coma_y_con_o_sin_bom_o_latin1_se_leen(): void
    {
        foreach ([$this->csv([$this->fila()], ',', true), $this->csv([$this->fila()], ',', false), $this->csv([$this->fila()], ';', false), utf8_decode($this->csv([$this->fila(['cargo' => 'Técnico'])], ';', false))] as $contenido) {
            $analisis = $this->subir($contenido)->assertOk()->viewData('analisis');

            $this->assertSame([], $analisis['errores_archivo']);
            $this->assertSame('correcta', $analisis['filas'][0]['estado']);
        }
    }

    public function test_un_archivo_sin_columnas_obligatorias_o_vacio_se_rechaza(): void
    {
        $respuesta = $this->subir("cedula;nombre_completo\r\n9000000101;Ejemplo\r\n")->assertOk();

        $this->assertStringContainsString('Faltan columnas obligatorias', $respuesta->viewData('analisis')['errores_archivo'][0]);
        $this->assertNull($respuesta->viewData('token'));
        $this->assertStringContainsString('no tiene filas de empleados', $this->analisis([])['errores_archivo'][0]);
    }

    // ───────── Omitidos ─────────

    public function test_una_cedula_existente_se_omite_sin_error_y_no_se_actualiza(): void
    {
        Empleado::factory()->create(['cedula' => '9000000101', 'nombre_completo' => 'Nombre Original', 'salario' => 5_000_000]);

        $analisis = $this->analisis([$this->fila(['nombre_completo' => 'Otro Nombre', 'salario' => '9.999.999']), $this->fila(['cedula' => '9000000102'])]);

        $this->assertSame('omitida', $analisis['filas'][0]['estado']);
        $this->assertStringContainsString('se omite y no se actualiza', $this->mensajes($analisis, 0));
        $this->assertSame(1, $analisis['resumen']['omitidas']);
        $this->assertSame(0, $analisis['resumen']['errores']);

        $this->cargar([$this->fila(['nombre_completo' => 'Otro Nombre', 'salario' => '9.999.999']), $this->fila(['cedula' => '9000000102'])]);

        $original = Empleado::findOrFail('9000000101');
        $this->assertSame('Nombre Original', $original->nombre_completo);
        $this->assertEquals(5_000_000, $original->salario);
        $this->assertNull($original->origen);
        $this->assertNotNull(Empleado::find('9000000102'));
    }

    public function test_si_todas_las_cedulas_ya_existen_no_hay_nada_que_confirmar(): void
    {
        Empleado::factory()->create(['cedula' => '9000000101']);

        $respuesta = $this->subir($this->csv([$this->fila()]))->assertOk();

        $this->assertNull($respuesta->viewData('token'));
        $respuesta->assertSee('No hay empleados nuevos para crear');
        $this->assertSame(0, $this->archivosPendientes());
    }

    // ───────── Confirmación ─────────

    public function test_confirmar_crea_los_empleados_con_origen_lote_estado_y_capacidad(): void
    {
        $lote = $this->cargar([$this->fila(), $this->fila(['cedula' => '9000000102', 'nombre_completo' => 'Ejemplo Dos', 'capacidad_endeudamiento' => '1.000.000', 'de_carrera' => 'no'])]);

        $uno = Empleado::findOrFail('9000000101');
        $dos = Empleado::findOrFail('9000000102');

        $this->assertMatchesRegularExpression('/^CARGA-\d{8}-\d{6}-[A-Z0-9]{4}$/', $lote);
        $this->assertSame('carga_inicial', $uno->origen);
        $this->assertSame($lote, $uno->lote_carga);
        $this->assertSame('Activo', $uno->estado);
        $this->assertSame(TipoVinculacion::Vinculado, $uno->tipo_vinculacion);
        $this->assertTrue($uno->de_carrera);
        $this->assertFalse($dos->de_carrera);
        $this->assertEquals(3_200_000, $uno->salario);
        $this->assertSame('2018-03-15', $uno->fecha_vinculacion->toDateString());
        $this->assertEquals(1_600_000, $uno->capacidad_endeudamiento, 'Sin capacidad en el archivo se sugiere el 50 % del salario, como en el formulario.');
        $this->assertEquals(1_000_000, $dos->capacidad_endeudamiento);
    }

    public function test_el_resultado_muestra_cantidad_creada_omitida_y_lote(): void
    {
        Empleado::factory()->create(['cedula' => '9000000101']);
        $token = $this->subir($this->csv([$this->fila(), $this->fila(['cedula' => '9000000102'])]))->viewData('token');

        $respuesta = $this->actingAs($this->administrador)->post(route('cargar-empleados.confirmar'), ['token' => $token, 'nombre_archivo' => 'empleados-ficticios.csv']);
        $lote = Empleado::where('cedula', '9000000102')->value('lote_carga');

        $respuesta->assertRedirect(route('cargar-empleados.lote', $lote));
        $this->actingAs($this->administrador)->get(route('cargar-empleados.lote', $lote))->assertOk()
            ->assertSee("Carga confirmada: 1 empleado(s) creado(s) y 1 omitido(s) por ya existir, en el lote {$lote}.", false)
            ->assertSee($lote);
    }

    public function test_la_carga_se_audita_con_lote_y_cantidades_sin_cedulas_ni_salarios(): void
    {
        $lote = $this->cargar([$this->fila(['salario' => '3.234.567']), $this->fila(['cedula' => '9000000102'])]);

        $registro = Auditoria::where('funcion_realizada', 'Carga de empleados')->firstOrFail();

        $this->assertStringContainsString($lote, $registro->detalle);
        $this->assertStringContainsString('2 empleado(s) creado(s), 0 omitido(s)', $registro->detalle);
        $this->assertSame($this->administrador->cedula, $registro->cedula_usu);

        foreach (['9000000101', '9000000102', 'Ejemplo Uno', '3234567', '3.234.567'] as $dato) {
            $this->assertStringNotContainsString($dato, (string) $registro->detalle);
        }
    }

    public function test_todo_o_nada_con_una_fila_con_error_no_se_crea_ninguno(): void
    {
        $servicio = app(CargaEmpleadosService::class);

        try {
            $servicio->cargar($this->csv([$this->fila(), $this->fila(['cedula' => '9000000102', 'salario' => 'x'])]), 'empleados-ficticios.csv', $this->administrador);
            $this->fail('Debió fallar.');
        } catch (RuntimeException $excepcion) {
            $this->assertStringContainsString('No se cargó nada', $excepcion->getMessage());
        }

        $this->assertSame(0, Empleado::count());
        $this->assertSame(0, Auditoria::where('funcion_realizada', 'Carga de empleados')->count());
    }

    public function test_todo_o_nada_si_falla_a_mitad_de_la_carga_no_queda_nada(): void
    {
        Empleado::creating(function (Empleado $empleado): void {
            if ($empleado->cedula === '9000000102') {
                throw new RuntimeException('Falla simulada.');
            }
        });

        try {
            app(CargaEmpleadosService::class)->cargar($this->csv([$this->fila(), $this->fila(['cedula' => '9000000102']), $this->fila(['cedula' => '9000000103'])]), 'empleados-ficticios.csv', $this->administrador);
            $this->fail('Debió fallar.');
        } catch (RuntimeException $excepcion) {
            $this->assertSame('Falla simulada.', $excepcion->getMessage());
        }

        $this->assertSame(0, Empleado::count());
    }

    public function test_confirmar_con_el_archivo_bloqueado_no_crea_nada(): void
    {
        $token = $this->subir($this->csv([$this->fila()]))->viewData('token');
        $this->assertSame(1, $this->archivosPendientes());

        Empleado::factory()->create(['cedula' => '9000000101']);

        $this->actingAs($this->administrador)->post(route('cargar-empleados.confirmar'), ['token' => $token])->assertSessionHasErrors('archivo');

        $this->assertSame(1, Empleado::count());
        $this->assertSame(0, $this->archivosPendientes(), 'El archivo se borra también cuando la confirmación falla.');
    }

    // ───────── Archivo pendiente ─────────

    public function test_el_archivo_pendiente_se_borra_al_confirmar(): void
    {
        $token = $this->subir($this->csv([$this->fila()]))->viewData('token');
        $this->assertSame(1, $this->archivosPendientes());

        $this->actingAs($this->administrador)->post(route('cargar-empleados.confirmar'), ['token' => $token])->assertRedirect();

        $this->assertSame(0, $this->archivosPendientes());
        $this->actingAs($this->administrador)->post(route('cargar-empleados.confirmar'), ['token' => $token])->assertSessionHasErrors('archivo');
        $this->assertSame(1, Empleado::count());
    }

    public function test_el_archivo_pendiente_se_borra_al_cancelar_y_no_se_guarda_nada(): void
    {
        $token = $this->subir($this->csv([$this->fila()]))->viewData('token');
        $this->assertSame(1, $this->archivosPendientes());

        $this->actingAs($this->administrador)->post(route('cargar-empleados.cancelar'), ['token' => $token])
            ->assertRedirect(route('cargar-empleados.index'))->assertSessionHas('status');

        $this->assertSame(0, $this->archivosPendientes());
        $this->assertSame(0, Empleado::count());
    }

    public function test_un_token_invalido_no_borra_ni_lee_nada_fuera_de_la_carpeta(): void
    {
        $this->subir($this->csv([$this->fila()]));

        $this->actingAs($this->administrador)->post(route('cargar-empleados.cancelar'), ['token' => '../../etc/passwd'])->assertSessionHasErrors('token');

        $this->assertSame(1, $this->archivosPendientes());
    }

    // ───────── Reversión ─────────

    public function test_revertir_un_lote_sin_registros_asociados_elimina_los_empleados_y_se_audita(): void
    {
        $otro = Empleado::factory()->create();
        $lote = $this->cargar([$this->fila(), $this->fila(['cedula' => '9000000102'])]);

        $this->actingAs($this->administrador)->post(route('cargar-empleados.revertir', $lote))
            ->assertRedirect(route('cargar-empleados.index'))->assertSessionHas('status');

        $this->assertSame([$otro->cedula], Empleado::pluck('cedula')->all(), 'Solo se eliminan los empleados del lote.');

        $registro = Auditoria::where('funcion_realizada', 'Reversión de carga de empleados')->firstOrFail();
        $this->assertStringContainsString($lote, $registro->detalle);
        $this->assertStringContainsString('2 empleado(s)', $registro->detalle);
        $this->assertStringNotContainsString('9000000101', $registro->detalle);
    }

    public function test_revertir_se_bloquea_si_un_empleado_tiene_solicitudes_o_prestamos_y_no_elimina_ninguno(): void
    {
        $lote = $this->cargar([$this->fila(), $this->fila(['cedula' => '9000000102']), $this->fila(['cedula' => '9000000103'])]);
        SolicitudCredito::factory()->create(['cedula_empleado' => '9000000101']);
        Prestamo::factory()->create(['cedula_empleado' => '9000000102']);

        $this->actingAs($this->administrador)->post(route('cargar-empleados.revertir', $lote))
            ->assertRedirect(route('cargar-empleados.index'))
            ->assertSessionHasErrors('lote');

        $this->assertSame(3, Empleado::where('lote_carga', $lote)->count());
        $this->assertSame(0, Auditoria::where('funcion_realizada', 'Reversión de carga de empleados')->count());

        $this->actingAs($this->administrador)->get(route('cargar-empleados.index'))->assertOk()
            ->assertSee('No se puede revertir')
            ->assertSee('El empleado 9000000101 tiene 1 solicitud(es)')
            ->assertSee('El empleado 9000000102 tiene 1 préstamo(s)')
            ->assertDontSee('El empleado 9000000103 tiene');
    }

    public function test_revertir_se_bloquea_si_un_empleado_tiene_cuenta_de_usuario(): void
    {
        $lote = $this->cargar([$this->fila()]);
        Usuario::factory()->create(['cedula' => '9000000101']);

        $this->assertStringContainsString('una cuenta de usuario', implode(' ', app(CargaEmpleadosService::class)->motivosParaNoRevertir($lote)));
        $this->actingAs($this->administrador)->post(route('cargar-empleados.revertir', $lote))->assertSessionHasErrors('lote');
        $this->assertSame(1, Empleado::where('lote_carga', $lote)->count());
    }

    public function test_el_mensaje_de_la_excepcion_de_revertir_no_lleva_cedulas(): void
    {
        $lote = $this->cargar([$this->fila()]);
        SolicitudCredito::factory()->create(['cedula_empleado' => '9000000101']);

        try {
            app(CargaEmpleadosService::class)->revertir($lote, $this->administrador);
            $this->fail('Debió negarse.');
        } catch (RuntimeException $excepcion) {
            $this->assertStringNotContainsString('9000000101', $excepcion->getMessage());
        }
    }

    public function test_un_lote_inexistente_no_se_puede_revertir_ni_consultar(): void
    {
        $this->actingAs($this->administrador)->post(route('cargar-empleados.revertir', 'CARGA-20260101-000000-ZZZZ'))->assertSessionHasErrors('lote');
        $this->actingAs($this->administrador)->get(route('cargar-empleados.lote', 'CARGA-20260101-000000-ZZZZ'))->assertNotFound();
    }

    // ───────── Permisos y privacidad ─────────

    public function test_el_funcionario_no_tiene_acceso_a_ninguna_ruta_de_la_carga(): void
    {
        $funcionario = Usuario::factory()->create();
        $lote = $this->cargar([$this->fila()]);

        $this->actingAs($funcionario)->get(route('cargar-empleados.index'))->assertForbidden();
        $this->actingAs($funcionario)->get(route('cargar-empleados.plantilla'))->assertForbidden();
        $this->actingAs($funcionario)->post(route('cargar-empleados.vista-previa'))->assertForbidden();
        $this->actingAs($funcionario)->post(route('cargar-empleados.confirmar'))->assertForbidden();
        $this->actingAs($funcionario)->post(route('cargar-empleados.cancelar'))->assertForbidden();
        $this->actingAs($funcionario)->get(route('cargar-empleados.lote', $lote))->assertForbidden();
        $this->actingAs($funcionario)->post(route('cargar-empleados.revertir', $lote))->assertForbidden();

        $this->assertSame(1, Empleado::count());
    }

    public function test_el_permiso_es_del_administrador_no_del_funcionario_y_no_cambia_usuarios_ni_auditoria(): void
    {
        $administrador = Usuario::factory()->administrador()->create();

        $this->assertTrue($administrador->tienePermiso('empleados.cargar_masivo'));
        $this->assertFalse(Usuario::factory()->create()->tienePermiso('empleados.cargar_masivo'));
        $this->assertFalse($administrador->tienePermiso('usuarios.gestionar'));
        $this->assertFalse($administrador->tienePermiso('auditoria.ver'));
        $this->assertTrue(Usuario::factory()->principal()->create()->tienePermiso('usuarios.gestionar'));
        $this->assertContains('!empleados.cargar_masivo', config('permisos.defaults.FUNCIONARIO_BIENESTAR'));
        $this->assertSame(['empleados.ver'], config('permisos.dependencias')['empleados.cargar_masivo']);
        $this->assertNotContains('empleados.cargar_masivo', config('permisos.solo_administrador'));
    }

    public function test_el_menu_de_empleados_muestra_el_acceso_solo_con_el_permiso(): void
    {
        $this->actingAs($this->administrador)->get(route('inicio'))->assertOk()->assertSeeInOrder(['Lista de empleados', 'Nuevo empleado', 'Cargar empleados']);
        $this->actingAs(Usuario::factory()->create())->get(route('inicio'))->assertOk()->assertDontSee('Cargar empleados');
        $this->actingAs($this->administrador)->get(route('empleados.index'))->assertOk()->assertSee(route('cargar-empleados.index'), false);
    }

    public function test_las_carpetas_de_datos_reales_estan_ignoradas_por_git(): void
    {
        $gitignore = (string) file_get_contents(base_path('.gitignore'));

        $this->assertStringContainsString('/storage/app/private/real/', $gitignore);
        $this->assertStringContainsString('/docs/practica/real/', $gitignore);

        $git = trim((string) @shell_exec('git --version 2>&1'));

        if (! str_starts_with($git, 'git version')) {
            $this->markTestIncomplete('git no está disponible: se comprobó solo el texto de .gitignore.');
        }

        foreach (['storage/app/private/real/empleados.csv', 'docs/practica/real/empleados.csv'] as $ruta) {
            exec('git -C '.escapeshellarg(base_path()).' check-ignore -q '.escapeshellarg($ruta), $salida, $codigo);

            $this->assertSame(0, $codigo, "{$ruta} debe estar ignorada por git.");
        }
    }

    public function test_el_indice_lista_los_lotes_con_su_boton_de_revertir(): void
    {
        $lote = $this->cargar([$this->fila()]);

        $this->actingAs($this->administrador)->get(route('cargar-empleados.index'))->assertOk()
            ->assertSee($lote)->assertSee('Revertir lote')->assertSee(route('cargar-empleados.plantilla'), false);
    }
}
