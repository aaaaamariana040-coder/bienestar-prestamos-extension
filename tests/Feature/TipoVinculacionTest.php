<?php

namespace Tests\Feature;

use App\Enums\EstadoSolicitud;
use App\Enums\TipoVinculacion;
use App\Models\CuantiaSmmlv;
use App\Models\Empleado;
use App\Models\PresupuestoAnual;
use App\Models\SmmlvAnual;
use App\Models\SolicitudCredito;
use App\Models\TasaInt;
use App\Models\TipoPrestamo;
use App\Models\Usuario;
use App\Services\EnvioComiteService;
use App\Services\SimuladorCreditoService;
use App\Services\SolicitudCreditoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Tipos de vinculación (enum) y la regla de los docentes ocasionales.
 */
class TipoVinculacionTest extends TestCase
{
    use RefreshDatabase;

    private const MENSAJE = 'Los docentes ocasionales no son beneficiarios de crédito del Fondo.';

    private const MENSAJE_REGISTRO = 'No se puede registrar: los docentes ocasionales no son beneficiarios de crédito del Fondo.';

    private Usuario $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usuario = Usuario::factory()->create();

        foreach (array_unique([2025, 2026, now()->year]) as $anio) {
            SmmlvAnual::factory()->create(['anio' => $anio, 'valor' => 1_750_905]);
        }
    }

    // ───────── Enum ─────────

    public function test_el_enum_guarda_valores_legibles_y_muestra_etiquetas_en_espanol(): void
    {
        $this->assertSame(['vinculado', 'docente', 'docente_ocasional', 'jubilado'], TipoVinculacion::valores());
        $this->assertSame(
            ['vinculado' => 'Vinculado', 'docente' => 'Docente', 'docente_ocasional' => 'Docente ocasional', 'jubilado' => 'Jubilado'],
            TipoVinculacion::opciones(),
        );
        $this->assertSame('Docente ocasional', TipoVinculacion::DocenteOcasional->etiqueta());
    }

    // ───────── Migración ─────────

    public function test_la_migracion_convierte_los_valores_y_no_pierde_filas(): void
    {
        $migracion = require database_path('migrations/2026_10_27_110000_convierte_tipo_vinculacion_y_agrega_de_carrera_a_empleados_table.php');
        $migracion->down();

        foreach (['Empleado' => '1', 'Docente' => '2', 'Contratista' => '3', 'Empleado ' => '4'] as $valor => $cedula) {
            DB::table('empleados')->insert([
                'cedula' => $cedula, 'nombre_completo' => "Persona {$cedula}", 'tipo_vinculacion' => $valor,
                'salario' => 3_000_000, 'estado' => 'Activo', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $migracion->up();

        $this->assertSame(4, DB::table('empleados')->count());
        $this->assertSame('vinculado', DB::table('empleados')->where('cedula', '1')->value('tipo_vinculacion'));
        $this->assertSame('docente', DB::table('empleados')->where('cedula', '2')->value('tipo_vinculacion'));
        $this->assertSame('vinculado', DB::table('empleados')->where('cedula', '3')->value('tipo_vinculacion'));
        $this->assertSame('vinculado', DB::table('empleados')->where('cedula', '4')->value('tipo_vinculacion'));
        $this->assertSame(0, DB::table('empleados')->where('de_carrera', true)->count());
        $this->assertSame(3_000_000.0, (float) DB::table('empleados')->where('cedula', '1')->value('salario'));
    }

    public function test_la_migracion_es_reversible(): void
    {
        $migracion = require database_path('migrations/2026_10_27_110000_convierte_tipo_vinculacion_y_agrega_de_carrera_a_empleados_table.php');
        Empleado::factory()->create(['cedula' => '10', 'tipo_vinculacion' => TipoVinculacion::Docente]);
        Empleado::factory()->create(['cedula' => '11', 'tipo_vinculacion' => TipoVinculacion::Vinculado]);

        $migracion->down();

        $this->assertSame('Docente', DB::table('empleados')->where('cedula', '10')->value('tipo_vinculacion'));
        $this->assertSame('Empleado', DB::table('empleados')->where('cedula', '11')->value('tipo_vinculacion'));
        $this->assertFalse(Schema::hasColumn('empleados', 'de_carrera'));
    }

    // ───────── Formulario, validación y lista ─────────

    public function test_el_formulario_ofrece_los_cuatro_tipos_por_nombre_y_el_campo_de_carrera(): void
    {
        $this->actingAs($this->usuario)->get(route('empleados.create'))
            ->assertOk()
            ->assertSee('<option value="docente_ocasional"', false)
            ->assertSeeInOrder(['Vinculado', 'Docente', 'Docente ocasional', 'Jubilado'])
            ->assertSee('Empleado de carrera');
    }

    public function test_se_crea_un_empleado_de_cada_tipo_y_de_carrera_se_guarda(): void
    {
        foreach (TipoVinculacion::cases() as $i => $tipo) {
            $this->actingAs($this->usuario)->post(route('empleados.store'), $this->datos(['cedula' => (string) (500 + $i), 'tipo_vinculacion' => $tipo->value, 'de_carrera' => $i % 2 === 0 ? '1' : '']))
                ->assertSessionHasNoErrors();

            $empleado = Empleado::find((string) (500 + $i));
            $this->assertSame($tipo, $empleado->tipo_vinculacion);
            $this->assertSame($i % 2 === 0, $empleado->de_carrera);
        }

        $this->assertSame(4, Empleado::count());
    }

    public function test_de_carrera_es_falso_por_defecto_y_se_edita(): void
    {
        $empleado = Empleado::factory()->create(['tipo_vinculacion' => TipoVinculacion::Docente]);
        $this->assertFalse($empleado->fresh()->de_carrera);

        $this->actingAs($this->usuario)->put(route('empleados.update', $empleado), $this->datos(['tipo_vinculacion' => 'jubilado', 'de_carrera' => '1']))
            ->assertSessionHasNoErrors();

        $this->assertSame(TipoVinculacion::Jubilado, $empleado->fresh()->tipo_vinculacion);
        $this->assertTrue($empleado->fresh()->de_carrera);

        $this->actingAs($this->usuario)->put(route('empleados.update', $empleado), $this->datos(['tipo_vinculacion' => 'jubilado']))->assertSessionHasNoErrors();
        $this->assertFalse($empleado->fresh()->de_carrera);
    }

    public function test_un_tipo_invalido_se_rechaza_incluidos_los_valores_antiguos(): void
    {
        foreach (['Empleado', 'Docente', 'contratista', ''] as $valor) {
            $this->actingAs($this->usuario)->post(route('empleados.store'), $this->datos(['cedula' => '900', 'tipo_vinculacion' => $valor]))
                ->assertSessionHasErrors('tipo_vinculacion');
        }

        $empleado = Empleado::factory()->create();
        $this->actingAs($this->usuario)->put(route('empleados.update', $empleado), $this->datos(['tipo_vinculacion' => 'Docente']))
            ->assertSessionHasErrors('tipo_vinculacion');

        $this->assertSame(1, Empleado::count());
    }

    public function test_la_lista_la_ficha_y_el_edit_muestran_el_nombre_y_nunca_el_valor_interno(): void
    {
        $ocasional = Empleado::factory()->create(['nombre_completo' => 'Persona Ocasional', 'tipo_vinculacion' => TipoVinculacion::DocenteOcasional, 'de_carrera' => true]);

        $this->actingAs($this->usuario)->get(route('empleados.index'))
            ->assertOk()->assertSee('<td>Docente ocasional</td>', false)->assertDontSee('<td>docente_ocasional</td>', false);

        $this->actingAs($this->usuario)->get(route('empleados.show', $ocasional))
            ->assertOk()->assertSee('Docente ocasional')->assertSee('Empleado de carrera')->assertDontSee('docente_ocasional');

        $this->actingAs($this->usuario)->get(route('empleados.edit', $ocasional))
            ->assertOk()->assertSee('<option value="docente_ocasional" selected>', false);
    }

    public function test_el_filtro_por_tipo_de_la_lista(): void
    {
        Empleado::factory()->create(['nombre_completo' => 'Ana Vinculada', 'tipo_vinculacion' => TipoVinculacion::Vinculado]);
        Empleado::factory()->create(['nombre_completo' => 'Beto Docente', 'tipo_vinculacion' => TipoVinculacion::Docente]);
        Empleado::factory()->create(['nombre_completo' => 'Caro Ocasional', 'tipo_vinculacion' => TipoVinculacion::DocenteOcasional]);
        Empleado::factory()->create(['nombre_completo' => 'Dani Jubilado', 'tipo_vinculacion' => TipoVinculacion::Jubilado]);

        $this->actingAs($this->usuario)->get(route('empleados.index', ['tipo' => 'docente_ocasional']))
            ->assertSee('Caro Ocasional')->assertDontSee('Ana Vinculada')->assertDontSee('Beto Docente')->assertDontSee('Dani Jubilado');

        $this->actingAs($this->usuario)->get(route('empleados.index', ['tipo' => 'docente']))
            ->assertSee('Beto Docente')->assertDontSee('Caro Ocasional');

        $this->actingAs($this->usuario)->get(route('empleados.index', ['tipo' => 'invalido']))
            ->assertOk()->assertSee('Ana Vinculada')->assertSee('Dani Jubilado');
    }

    // ───────── Docente ocasional sin crédito ─────────

    public function test_el_docente_ocasional_no_puede_registrar_una_solicitud(): void
    {
        $this->registrar($this->empleado(TipoVinculacion::DocenteOcasional), $this->modalidad())
            ->assertSessionHasErrors(['cedula_empleado' => self::MENSAJE_REGISTRO]);

        $this->assertSame(0, SolicitudCredito::count());
    }

    public function test_vinculado_docente_y_jubilado_siguen_registrando_solicitudes(): void
    {
        $modalidad = $this->modalidad();

        foreach ([TipoVinculacion::Vinculado, TipoVinculacion::Docente, TipoVinculacion::Jubilado] as $tipo) {
            $this->registrar($this->empleado($tipo), $modalidad)->assertSessionHasNoErrors()->assertRedirect();
        }

        $this->assertSame(3, SolicitudCredito::count());
    }

    public function test_validar_marca_a_un_docente_ocasional_en_una_solicitud_preexistente(): void
    {
        $modalidad = $this->modalidad();
        $servicio = app(SolicitudCreditoService::class);

        $ocasional = $this->solicitudPreexistente($this->empleado(TipoVinculacion::DocenteOcasional), $modalidad);
        $this->assertContains('tipo_vinculacion', array_column($servicio->incumplimientos($ocasional), 'codigo'));
        $this->assertContains(self::MENSAJE, $servicio->validar($ocasional));

        foreach ([TipoVinculacion::Vinculado, TipoVinculacion::Docente, TipoVinculacion::Jubilado] as $tipo) {
            $solicitud = $this->solicitudPreexistente($this->empleado($tipo), $modalidad);
            $this->assertSame([], $servicio->validar($solicitud), $tipo->value);
        }
    }

    public function test_el_simulador_dice_no_apto_al_docente_ocasional(): void
    {
        $modalidad = $this->modalidad();
        $servicio = app(SimuladorCreditoService::class);
        $ocasional = $this->empleado(TipoVinculacion::DocenteOcasional);

        $evaluacion = $servicio->evaluarModalidad($ocasional, $modalidad);
        $this->assertFalse($evaluacion['puede']);
        $this->assertSame('tipo_vinculacion', $evaluacion['motivo']);
        $this->assertSame(self::MENSAJE, $evaluacion['mensaje']);
        $this->assertSame([self::MENSAJE], $servicio->simular($ocasional, $modalidad, 2_000_000, 24)['errores']);

        $this->actingAs($this->usuario)
            ->getJson(route('simulador.calcular', ['cedula' => $ocasional->cedula, 'id_tipo' => $modalidad->id_tipo, 'monto' => '2.000.000', 'plazo' => 24]))
            ->assertStatus(422)
            ->assertJsonPath('errores.0', self::MENSAJE)
            ->assertJsonMissingPath('html_impresion');
    }

    public function test_el_simulador_sigue_funcionando_para_vinculado_docente_y_jubilado(): void
    {
        $modalidad = $this->modalidad();

        foreach ([TipoVinculacion::Vinculado, TipoVinculacion::Docente, TipoVinculacion::Jubilado] as $tipo) {
            $empleado = $this->empleado($tipo);

            $this->assertTrue(app(SimuladorCreditoService::class)->evaluarModalidad($empleado, $modalidad)['puede'], $tipo->value);
            $this->actingAs($this->usuario)
                ->getJson(route('simulador.calcular', ['cedula' => $empleado->cedula, 'id_tipo' => $modalidad->id_tipo, 'monto' => '2.000.000', 'plazo' => 24]))
                ->assertOk()->assertJsonPath('errores', []);
        }
    }

    public function test_la_hoja_impresa_muestra_el_nombre_del_tipo(): void
    {
        $modalidad = $this->modalidad();
        $empleado = $this->empleado(TipoVinculacion::Jubilado);

        $hoja = $this->actingAs($this->usuario)
            ->getJson(route('simulador.calcular', ['cedula' => $empleado->cedula, 'id_tipo' => $modalidad->id_tipo, 'monto' => '2.000.000', 'plazo' => 24]))
            ->assertOk()->json('html_impresion');

        $this->assertStringContainsString('<dd>Jubilado</dd>', $hoja);
        $this->assertStringNotContainsString('<dd>jubilado</dd>', $hoja);
    }

    public function test_el_correo_al_comite_muestra_el_nombre_del_tipo(): void
    {
        $empleado = $this->empleado(TipoVinculacion::Docente);
        $solicitud = $this->solicitudPreexistente($empleado, $this->modalidad());
        $solicitud->update(['estado' => EstadoSolicitud::Radicada]);

        $datos = (new \ReflectionMethod(EnvioComiteService::class, 'datosDeLaSolicitud'))
            ->invoke(app(EnvioComiteService::class), $solicitud);

        $this->assertSame('Docente', $datos['tipo_vinculacion']);

        $html = view('mail.solicitud-comite', ['datos' => $datos, 'introduccion' => 'Hola', 'documentos' => collect(), 'cierre' => 'Gracias'])->render();
        $this->assertStringContainsString('Tipo de vinculación', $html);
        $this->assertStringContainsString('>Docente<', $html);
    }

    // ───────── de_carrera no afecta ninguna regla ─────────

    public function test_de_carrera_no_cambia_ninguna_regla(): void
    {
        $modalidad = $this->modalidad();
        $servicio = app(SimuladorCreditoService::class);
        $resultados = [];

        foreach ([false, true] as $deCarrera) {
            $empleado = $this->empleado(TipoVinculacion::Docente);
            $empleado->update(['de_carrera' => $deCarrera]);
            $solicitud = $this->solicitudPreexistente($empleado, $modalidad);

            $resultados[] = [
                app(SolicitudCreditoService::class)->incumplimientos($solicitud),
                $servicio->evaluarModalidad($empleado->fresh(), $modalidad),
                $servicio->simular($empleado->fresh(), $modalidad, 2_000_000, 24),
            ];
        }

        $this->assertEquals($resultados[0], $resultados[1]);
    }

    // ───────── Ayudas ─────────

    /**
     * @param  array<string, mixed>  $sobrescribir
     * @return array<string, mixed>
     */
    private function datos(array $sobrescribir = []): array
    {
        return array_merge([
            'cedula' => '900000001',
            'nombre_completo' => 'Persona de Prueba',
            'tipo_vinculacion' => 'vinculado',
            'salario' => 3_000_000,
            'estado' => 'Activo',
            'capacidad_endeudamiento' => '',
        ], $sobrescribir);
    }

    private function modalidad(): TipoPrestamo
    {
        $modalidad = TipoPrestamo::factory()->create(['nombre' => 'Educación', 'plazo_maximo' => 72]);
        CuantiaSmmlv::factory()->create(['id_tipo' => $modalidad->id_tipo, 'tramo_desde_smmlv' => 0, 'tramo_hasta_smmlv' => null, 'cuantia_maxima_smmlv' => 100]);

        foreach (array_unique([2026, now()->year]) as $anio) {
            TasaInt::factory()->create(['id_tipo' => $modalidad->id_tipo, 'anio' => $anio, 'tasa_anual' => 4.85]);
            PresupuestoAnual::factory()->create(['id_tipo' => $modalidad->id_tipo, 'anio' => $anio, 'monto_asignado' => 500_000_000]);
        }

        return $modalidad;
    }

    private function empleado(TipoVinculacion $tipo): Empleado
    {
        return Empleado::factory()->create([
            'tipo_vinculacion' => $tipo,
            'salario' => 5_000_000,
            'capacidad_endeudamiento' => 4_000_000,
            'fecha_vinculacion' => now()->subYears(5),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function datosDeSolicitud(Empleado $empleado, TipoPrestamo $modalidad): array
    {
        return [
            'cedula_empleado' => $empleado->cedula,
            'id_tipo' => $modalidad->id_tipo,
            'monto_solicitado' => 2_000_000,
            'plazo_solicitado' => 24,
            'fecha_solicitud' => '2026-01-15',
        ];
    }

    private function registrar(Empleado $empleado, TipoPrestamo $modalidad): TestResponse
    {
        return $this->actingAs($this->usuario)->post(route('solicitudes.store'), $this->datosDeSolicitud($empleado, $modalidad));
    }

    private function solicitudPreexistente(Empleado $empleado, TipoPrestamo $modalidad): SolicitudCredito
    {
        return SolicitudCredito::factory()->create($this->datosDeSolicitud($empleado, $modalidad));
    }
}
