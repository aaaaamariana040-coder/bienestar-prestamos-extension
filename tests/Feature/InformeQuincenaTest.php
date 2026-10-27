<?php

namespace Tests\Feature;

use App\Enums\EstadoCuota;
use App\Enums\EstadoPrestamo;
use App\Enums\EstadoProcesoQuincena;
use App\Enums\TipoVinculacion;
use App\Models\Auditoria;
use App\Models\Cuota;
use App\Models\Empleado;
use App\Models\Pago;
use App\Models\Prestamo;
use App\Models\ProcesoQuincena;
use App\Models\TipoPrestamo;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Informe de quincena por cuota (la «Q»), en CSV UTF-8 con BOM separado por punto y coma.
 */
class InformeQuincenaTest extends TestCase
{
    use RefreshDatabase;

    private const ANIO = 2026;

    /** Primera quincena de octubre de 2026: del 1 al 15 (ya terminó). */
    private const QUINCENA = 19;

    private Usuario $funcionario;

    private TipoPrestamo $educacion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-20 10:00:00');
        config(['quincena.regla_vencimiento' => 'calendario', 'quincena.modo_marcado' => 'confirmar']);

        $this->funcionario = Usuario::factory()->create(['nombre' => 'Laura', 'apellido' => 'Gómez']);
        $this->educacion = TipoPrestamo::factory()->create(['nombre' => 'Educación']);
    }

    // ───────── Vista previa ─────────

    public function test_la_vista_previa_genera_un_csv_que_se_lee_de_vuelta_con_columnas_y_encabezado(): void
    {
        $this->prestamoConTresCuotas('1001', 'Ana Pérez', TipoVinculacion::Docente);

        $respuesta = $this->descargarPrevia();
        $respuesta->assertOk()->assertDownload('informe-quincena-2026-Q19-2026-10-15-vista-previa.csv');
        $this->assertStringStartsWith("\xEF\xBB\xBF", $respuesta->streamedContent());
        $this->assertStringContainsString('text/csv; charset=UTF-8', (string) $respuesta->headers->get('Content-Type'));

        $lineas = $this->leer($respuesta);

        $this->assertSame(['Informe de quincena'], $lineas[0]);
        $this->assertSame(['Quincena', 'Q19 de 2026 (vista previa)'], $lineas[1]);
        $this->assertSame(['Fecha de vencimiento de la quincena', '2026-10-15'], $lineas[2]);
        $this->assertSame('Generado', $lineas[3][0]);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $lineas[3][1]);
        $this->assertSame(['Usuario', 'Laura Gómez ('.$this->funcionario->cedula.')'], $lineas[4]);

        $this->assertSame([
            'Cédula', 'Nombre', 'Tipo de vinculación', 'Modalidad', 'N.º de préstamo', 'Monto inicial', 'Plazo total (quincenas)',
            'Cuota n.º', 'Quincenas pagadas', 'Saldo inicial', 'Valor de la cuota', 'Capital', 'Intereses', 'Nuevo saldo',
            'Estado', 'Motivo de exclusión',
        ], $lineas[6]);
    }

    public function test_cada_fila_trae_los_valores_derivados_de_las_cuotas(): void
    {
        $prestamo = $this->prestamoConTresCuotas('1001', 'Ana Pérez', TipoVinculacion::Docente);

        $fila = $this->filas($this->leer($this->descargarPrevia()))[0];

        $this->assertSame(['1001', 'Ana Pérez', 'Docente', 'Educación', (string) $prestamo->id_prestamo, '600000,00', '3', '2', '1', '500000,00', '210000,00', '200000,00', '10000,00', '300000,00', 'Pendiente', ''], $fila);
    }

    public function test_los_montos_y_numeros_son_valores_numericos_y_no_texto(): void
    {
        $this->prestamoConTresCuotas('1001', 'Ana Pérez', TipoVinculacion::Vinculado);

        $fila = $this->filas($this->leer($this->descargarPrevia()))[0];

        foreach ([4, 5, 6, 7, 8, 9, 10, 11, 12, 13] as $indice) {
            $this->assertMatchesRegularExpression('/^\d+(,\d{2})?$/', $fila[$indice], "La columna {$indice} debe ser numérica.");
        }
    }

    public function test_los_totales_y_subtotales_son_la_suma_de_las_cuotas_y_las_excluidas_no_suman(): void
    {
        $uno = $this->prestamoConTresCuotas('1001', 'Ana Pérez', TipoVinculacion::Vinculado);
        $dos = $this->prestamoConTresCuotas('1002', 'Beto Ruiz', TipoVinculacion::Jubilado);
        $otra = TipoPrestamo::factory()->create(['nombre' => 'Calamidad']);
        $tres = $this->prestamoConTresCuotas('1003', 'Caro Díaz', TipoVinculacion::Vinculado, $otra);

        $excluida = $dos->cuotas()->where('numero_cuota', 2)->firstOrFail();
        $lineas = $this->leer($this->descargarPrevia([$excluida->id_cuota => 'Licencia no remunerada']));
        $filas = $this->filas($lineas);

        $this->assertCount(3, $filas);
        $estados = array_column($filas, 14, 0);
        $this->assertSame(['1001' => 'Pendiente', '1002' => 'Excluida', '1003' => 'Pendiente'], $estados);
        $this->assertSame('Licencia no remunerada', array_column($filas, 15, 0)['1002']);

        $indice = $this->buscar($lineas, 'A descontar (sin excluidas)');
        $this->assertSame(['A descontar (sin excluidas)', '2', '420000,00', '400000,00', '20000,00'], $lineas[$indice]);
        $this->assertSame(['Excluidas', '1', '210000,00', '200000,00', '10000,00'], $lineas[$indice + 1]);

        $inicio = $this->buscar($lineas, 'Resumen por modalidad') + 2;
        $this->assertSame(['Calamidad', '1', '210000,00', '200000,00', '10000,00', '0', '0,00'], $lineas[$inicio]);
        $this->assertSame(['Educación', '1', '210000,00', '200000,00', '10000,00', '1', '210000,00'], $lineas[$inicio + 1]);
        $this->assertSame(['Total', '2', '420000,00', '400000,00', '20000,00', '1', '210000,00'], $lineas[$inicio + 2]);

        $this->assertNotNull($uno);
        $this->assertNotNull($tres);
    }

    public function test_los_prestamos_no_desembolsados_no_aparecen(): void
    {
        $this->prestamoConTresCuotas('1001', 'Ana Pérez', TipoVinculacion::Vinculado);
        $pendiente = $this->prestamoConTresCuotas('1002', 'Beto Ruiz', TipoVinculacion::Vinculado);
        $pendiente->update(['pendiente_desembolso' => true]);

        $filas = $this->filas($this->leer($this->descargarPrevia()));

        $this->assertSame(['1001'], array_column($filas, 0));
    }

    public function test_una_quincena_sin_cuotas_genera_un_archivo_valido_con_aviso(): void
    {
        $respuesta = $this->descargarPrevia();
        $respuesta->assertOk();

        $lineas = $this->leer($respuesta);

        $this->assertSame(['Informe de quincena'], $lineas[0]);
        $this->assertContains(['La quincena no tiene cuotas.'], $lineas);
        $this->assertSame([], $this->filas($lineas));
        $this->assertSame(['Total', '0', '0,00', '0,00', '0,00', '0', '0,00'], $lineas[$this->buscar($lineas, 'Resumen por modalidad') + 2]);
    }

    public function test_un_texto_que_parece_formula_se_neutraliza(): void
    {
        $prestamo = $this->prestamoConTresCuotas('1001', 'Ana Pérez', TipoVinculacion::Vinculado);
        $cuota = $prestamo->cuotas()->where('numero_cuota', 2)->firstOrFail();

        $fila = $this->filas($this->leer($this->descargarPrevia([$cuota->id_cuota => '=1+1'])))[0];

        $this->assertSame("'=1+1", $fila[15]);
    }

    public function test_la_descarga_no_cambia_cuotas_pagos_ni_procesos(): void
    {
        $this->prestamoConTresCuotas('1001', 'Ana Pérez', TipoVinculacion::Vinculado);
        $antes = Cuota::orderBy('id_cuota')->get()->map->only(['id_cuota', 'estado', 'valor_total'])->toArray();

        $this->descargarPrevia()->assertOk();

        $this->assertEquals($antes, Cuota::orderBy('id_cuota')->get()->map->only(['id_cuota', 'estado', 'valor_total'])->toArray());
        $this->assertSame(0, Pago::count());
        $this->assertSame(0, ProcesoQuincena::count());
    }

    // ───────── Quincena confirmada ─────────

    public function test_la_quincena_confirmada_usa_lo_guardado_con_descontadas_y_excluidas_con_motivo(): void
    {
        $uno = $this->prestamoConTresCuotas('1001', 'Ana Pérez', TipoVinculacion::Vinculado);
        $dos = $this->prestamoConTresCuotas('1002', 'Beto Ruiz', TipoVinculacion::Docente);
        $excluida = $dos->cuotas()->where('numero_cuota', 2)->firstOrFail();
        $descontada = $uno->cuotas()->where('numero_cuota', 2)->firstOrFail();

        $this->actingAs($this->funcionario)->post(route('quincenas.confirmar', [self::ANIO, self::QUINCENA]), [
            'vistas' => [$descontada->id_cuota => 210_000, $excluida->id_cuota => 210_000],
            'excluir' => [$excluida->id_cuota],
            'motivo' => [$excluida->id_cuota => 'Licencia no remunerada'],
        ])->assertRedirect();

        $proceso = ProcesoQuincena::query()->confirmados()->firstOrFail();
        $respuesta = $this->actingAs($this->funcionario)->get(route('quincenas.proceso.excel', $proceso));
        $respuesta->assertOk()->assertDownload('informe-quincena-2026-Q19-2026-10-15.csv');

        $lineas = $this->leer($respuesta);
        $filas = $this->filas($lineas);

        $this->assertSame('Q19 de 2026 (confirmada)', $lineas[1][1]);
        $this->assertSame(['1001' => 'Descontada', '1002' => 'Excluida'], array_column($filas, 14, 0));
        $this->assertSame('Licencia no remunerada', array_column($filas, 15, 0)['1002']);
        $this->assertSame('', array_column($filas, 15, 0)['1001']);
        $this->assertSame(['A descontar (sin excluidas)', '1', '210000,00', '200000,00', '10000,00'], $lineas[$this->buscar($lineas, 'A descontar (sin excluidas)')]);
    }

    public function test_una_quincena_confirmada_antigua_sin_motivo_no_falla(): void
    {
        $prestamo = $this->prestamoConTresCuotas('1001', 'Ana Pérez', TipoVinculacion::Vinculado);
        $cuota = $prestamo->cuotas()->where('numero_cuota', 2)->firstOrFail();

        $proceso = ProcesoQuincena::factory()->create([
            'anio' => self::ANIO,
            'numero_quincena' => self::QUINCENA,
            'estado' => EstadoProcesoQuincena::Completado,
            'cedula_usu' => $this->funcionario->cedula,
            'finalizado_en' => now(),
            'resumen' => ['cuotas' => [], 'excluidas_manuales' => [['id_cuota' => $cuota->id_cuota, 'id_prestamo' => $prestamo->id_prestamo]]],
        ]);

        $filas = $this->filas($this->leer($this->actingAs($this->funcionario)->get(route('quincenas.proceso.excel', $proceso))->assertOk()));

        $this->assertSame('Excluida', $filas[0][14]);
        $this->assertSame('', $filas[0][15]);
    }

    public function test_una_quincena_confirmada_sin_cuotas_genera_un_archivo_valido(): void
    {
        $proceso = ProcesoQuincena::factory()->create([
            'anio' => self::ANIO,
            'numero_quincena' => self::QUINCENA,
            'estado' => EstadoProcesoQuincena::Completado,
            'cedula_usu' => $this->funcionario->cedula,
            'finalizado_en' => now(),
            'resumen' => ['cuotas' => [], 'total' => 0, 'pagos_creados' => 0],
        ]);

        $lineas = $this->leer($this->actingAs($this->funcionario)->get(route('quincenas.proceso.excel', $proceso))->assertOk());

        $this->assertContains(['La quincena no tiene cuotas.'], $lineas);
    }

    // ───────── Permisos y auditoría ─────────

    public function test_los_permisos_de_las_descargas(): void
    {
        $prestamo = $this->prestamoConTresCuotas('1001', 'Ana Pérez', TipoVinculacion::Vinculado);
        $proceso = ProcesoQuincena::factory()->create([
            'anio' => self::ANIO, 'numero_quincena' => 18, 'estado' => EstadoProcesoQuincena::Completado,
            'cedula_usu' => $this->funcionario->cedula, 'finalizado_en' => now(), 'resumen' => ['cuotas' => []],
        ]);

        $sinPermiso = Usuario::factory()->conPermisos(['empleados.ver'])->create();
        $this->actingAs($sinPermiso)->get(route('quincenas.proceso.excel', $proceso))->assertForbidden();
        $this->actingAs($sinPermiso)->post(route('quincenas.excel', [self::ANIO, self::QUINCENA]))->assertForbidden();

        $soloVer = Usuario::factory()->conPermisos(['quincenas.ver'])->create();
        $this->actingAs($soloVer)->get(route('quincenas.proceso.excel', $proceso))->assertOk();
        $this->actingAs($soloVer)->post(route('quincenas.excel', [self::ANIO, self::QUINCENA]))->assertForbidden();

        $confirma = Usuario::factory()->conPermisos(['quincenas.ver', 'quincenas.confirmar'])->create();
        $this->actingAs($confirma)->post(route('quincenas.excel', [self::ANIO, self::QUINCENA]))->assertOk();

        auth()->logout();
        $this->get(route('quincenas.proceso.excel', $proceso))->assertRedirect(route('login'));
        $this->assertNotNull($prestamo);
    }

    public function test_cada_descarga_queda_en_auditoria_con_la_quincena_y_el_tipo(): void
    {
        $this->prestamoConTresCuotas('1001', 'Ana Pérez', TipoVinculacion::Vinculado);

        $this->descargarPrevia()->assertOk();

        $auditoria = Auditoria::where('funcion_realizada', 'Descarga de informe de quincena')->sole();
        $this->assertSame($this->funcionario->cedula, $auditoria->cedula_usu);
        $this->assertSame('Quincena 19 de 2026 (vista previa): 1 cuotas, 0 excluidas', $auditoria->detalle);

        $proceso = ProcesoQuincena::factory()->create([
            'anio' => self::ANIO, 'numero_quincena' => 18, 'estado' => EstadoProcesoQuincena::Completado,
            'cedula_usu' => $this->funcionario->cedula, 'finalizado_en' => now(), 'resumen' => ['cuotas' => []],
        ]);
        $this->actingAs($this->funcionario)->get(route('quincenas.proceso.excel', $proceso))->assertOk();

        $this->assertSame(2, Auditoria::where('funcion_realizada', 'Descarga de informe de quincena')->count());
        $this->assertContains('Quincena 18 de 2026 (confirmada): 0 cuotas, 0 excluidas', Auditoria::pluck('detalle')->all());
    }

    // ───────── Pantallas ─────────

    public function test_los_botones_estan_en_la_vista_previa_el_detalle_y_el_historial(): void
    {
        $this->prestamoConTresCuotas('1001', 'Ana Pérez', TipoVinculacion::Vinculado);

        $this->actingAs($this->funcionario)->get(route('quincenas.vista-previa', [self::ANIO, self::QUINCENA]))
            ->assertOk()
            ->assertSee('id="descargar-excel"', false)
            ->assertSee(route('quincenas.excel', [self::ANIO, self::QUINCENA]), false);

        $this->actingAs($this->funcionario)->get(route('reportes.historial-pagos', ['anio' => self::ANIO, 'quincena' => self::QUINCENA]))
            ->assertOk()
            ->assertSee('Descargar informe de la quincena')
            ->assertSee(route('quincenas.excel', [self::ANIO, self::QUINCENA]), false);

        $proceso = ProcesoQuincena::factory()->create([
            'anio' => self::ANIO, 'numero_quincena' => self::QUINCENA, 'estado' => EstadoProcesoQuincena::Completado,
            'cedula_usu' => $this->funcionario->cedula, 'finalizado_en' => now(), 'resumen' => ['cuotas' => []],
        ]);

        $this->actingAs($this->funcionario)->get(route('quincenas.proceso', $proceso))->assertSee(route('quincenas.proceso.excel', $proceso), false);
        $this->actingAs($this->funcionario)->get(route('reportes.historial-pagos', ['anio' => self::ANIO, 'quincena' => self::QUINCENA]))
            ->assertSee(route('quincenas.proceso.excel', $proceso), false);
    }

    // ───────── Ayudas ─────────

    /**
     * Un préstamo de 600.000 en tres cuotas (capital 100.000, 200.000 y 300.000, interés 10.000), con la
     * cuota 1 pagada y la 2 venciendo en la quincena 19; la 3 vence después.
     */
    private function prestamoConTresCuotas(string $cedula, string $nombre, TipoVinculacion $tipo, ?TipoPrestamo $modalidad = null): Prestamo
    {
        Empleado::factory()->create(['cedula' => $cedula, 'nombre_completo' => $nombre, 'tipo_vinculacion' => $tipo]);

        $prestamo = Prestamo::factory()->create([
            'cedula_empleado' => $cedula,
            'id_tipo' => ($modalidad ?? $this->educacion)->id_tipo,
            'estado' => EstadoPrestamo::Vigente,
            'fecha_autorizacion' => '2026-09-01',
            'pendiente_desembolso' => false,
            'monto_aprobado' => 600_000,
            'num_cuotas' => 3,
        ]);

        foreach ([[1, '2026-09-30', 100_000, EstadoCuota::Pagada], [2, '2026-10-10', 200_000, EstadoCuota::Pendiente], [3, '2026-10-31', 300_000, EstadoCuota::Pendiente]] as [$numero, $fecha, $capital, $estado]) {
            Cuota::factory()->create([
                'id_prestamo' => $prestamo->id_prestamo,
                'numero_cuota' => $numero,
                'fecha_vencimiento' => $fecha,
                'valor_capital' => $capital,
                'valor_interes' => 10_000,
                'valor_total' => $capital + 10_000,
                'estado' => $estado,
            ]);
        }

        return $prestamo->fresh();
    }

    /**
     * @param  array<int, string>  $excluir  id_cuota => motivo
     */
    private function descargarPrevia(array $excluir = []): TestResponse
    {
        return $this->actingAs($this->funcionario)->post(route('quincenas.excel', [self::ANIO, self::QUINCENA]), [
            'excluir' => array_keys($excluir),
            'motivo' => $excluir,
        ]);
    }

    /**
     * @return list<list<string>>
     */
    private function leer(TestResponse $respuesta): array
    {
        $contenido = preg_replace('/^\xEF\xBB\xBF/', '', $respuesta->streamedContent());
        $lineas = [];

        foreach (preg_split('/\r?\n/', rtrim((string) $contenido)) as $linea) {
            $lineas[] = $linea === '' ? [] : str_getcsv($linea, ';', '"', '');
        }

        return $lineas;
    }

    /**
     * Las filas de cuotas: las que siguen al encabezado de columnas hasta la primera línea vacía.
     *
     * @param  list<list<string>>  $lineas
     * @return list<list<string>>
     */
    private function filas(array $lineas): array
    {
        $inicio = $this->buscar($lineas, 'Cédula') + 1;
        $filas = [];

        for ($i = $inicio; isset($lineas[$i]) && $lineas[$i] !== []; $i++) {
            $filas[] = $lineas[$i];
        }

        return $filas;
    }

    /**
     * @param  list<list<string>>  $lineas
     */
    private function buscar(array $lineas, string $primeraCelda): int
    {
        foreach ($lineas as $indice => $linea) {
            if (($linea[0] ?? null) === $primeraCelda) {
                return $indice;
            }
        }

        $this->fail("No se encontró la línea «{$primeraCelda}».");
    }
}
