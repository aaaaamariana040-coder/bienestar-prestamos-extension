<?php

namespace Tests\Feature;

use App\Enums\EstadoCuota;
use App\Enums\EstadoSolicitud;
use App\Enums\TipoVinculacion;
use App\Models\Cuota;
use App\Models\Empleado;
use App\Models\Pago;
use App\Models\Prestamo;
use App\Models\PresupuestoAnual;
use App\Models\PrimaSeguro;
use App\Models\SmmlvAnual;
use App\Models\SolicitudCredito;
use App\Models\TasaDtf;
use App\Models\TasaInt;
use App\Models\TasaVehiculo;
use App\Models\TipoDocumento;
use App\Models\TipoPrestamo;
use App\Models\Usuario;
use App\Services\CalendarioQuincenalService;
use App\Services\CargaPrestamosVigentesService;
use App\Services\PrestamoService;
use App\Services\QuincenaService;
use App\Services\SolicitudCreditoService;
use Database\Seeders\PracticaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * Datos de práctica ficticios (PracticaSeeder), su archivo de préstamos vigentes y los escenarios
 * de solicitudes. El comando practica:reiniciar se prueba en PracticaReiniciarTest.
 */
class PracticaSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-29 10:00:00');
        config(['practica.habilitada' => true, 'quincena.regla_vencimiento' => 'calendario', 'quincena.modo_marcado' => 'confirmar']);
    }

    private function sembrar(): void
    {
        $this->seed(PracticaSeeder::class);
    }

    // ───────── Protección ─────────

    public function test_el_seeder_se_niega_sin_practica_habilitada_y_en_produccion(): void
    {
        config(['practica.habilitada' => false]);

        try {
            $this->seed(PracticaSeeder::class);
            $this->fail('Debió negarse.');
        } catch (RuntimeException $excepcion) {
            $this->assertStringContainsString('PRACTICA_HABILITADA=true', $excepcion->getMessage());
        }

        $this->assertSame(0, Empleado::count());

        config(['practica.habilitada' => true]);
        $this->app['env'] = 'production';

        $this->expectExceptionMessage('producción');
        PracticaSeeder::verificarHabilitada();
    }

    // ───────── Datos de referencia ─────────

    public function test_deja_todas_las_tablas_de_referencia_con_datos(): void
    {
        $this->sembrar();

        $this->assertSame(8, TipoPrestamo::count());
        $this->assertSame(8, TasaVehiculo::count());
        $this->assertGreaterThan(0, SmmlvAnual::count());
        $this->assertGreaterThan(0, PresupuestoAnual::count());
        $this->assertGreaterThan(0, PrimaSeguro::count());
        $this->assertGreaterThan(0, TasaDtf::count());
        $this->assertSame(1, TipoDocumento::count() >= 1 ? 1 : 0);
        $this->assertTrue(TipoPrestamo::all()->every(fn (TipoPrestamo $tipo): bool => $tipo->cuantias()->exists() && $tipo->plazo_maximo > 0));
    }

    public function test_smmlv_tasas_y_tope_de_vivienda(): void
    {
        $this->sembrar();

        $this->assertEquals(1_750_905, SmmlvAnual::valorParaAnio(2026));

        foreach (TipoPrestamo::all() as $tipo) {
            foreach ([2025, 2026] as $anio) {
                $this->assertNotNull(TasaInt::where('id_tipo', $tipo->id_tipo)->where('anio', $anio)->first(), "{$tipo->nombre} sin IPC {$anio}");
            }
        }

        $servicio = app(PrestamoService::class);
        $vivienda = TipoPrestamo::where('nombre', 'Vivienda — compra de inmueble')->firstOrFail();
        $educacion = TipoPrestamo::where('nombre', 'Educación')->firstOrFail();

        $detalle = $servicio->tasaDetallada($vivienda, 2026, 120, true, '2026-10-29');
        $this->assertTrue($detalle['limitada']);
        $this->assertSame(5.0, $detalle['tasa']);
        $this->assertSame(5.1, $detalle['registrada']);
        $this->assertSame(5.1, $servicio->tasaDetallada($educacion, 2026, 24, true, '2026-10-29')['tasa']);
    }

    public function test_presupuesto_2026_vivienda_70_por_ciento_con_el_reparto_sugerido(): void
    {
        $this->sembrar();

        $presupuestos = PresupuestoAnual::with('tipoPrestamo')->where('anio', 2026)->get();
        $this->assertCount(8, $presupuestos);

        $vivienda = $presupuestos->filter(fn ($p) => $p->tipoPrestamo->esVivienda());
        $this->assertEqualsWithDelta(0.70 * PracticaSeeder::PRESUPUESTO_TOTAL, $vivienda->sum('monto_asignado'), 1);
        $this->assertEqualsWithDelta(0.30 * PracticaSeeder::PRESUPUESTO_TOTAL, $presupuestos->sum('monto_asignado') - $vivienda->sum('monto_asignado'), 1);
        $this->assertEquals(0.595 * 0.70 * PracticaSeeder::PRESUPUESTO_TOTAL, $vivienda->first(fn ($p) => $p->tipoPrestamo->nombre === 'Vivienda — compra de inmueble')->monto_asignado);
        $this->assertEquals(0, $presupuestos->sum('monto_comprometido'), 'Los préstamos cargados no consumen presupuesto.');
    }

    public function test_primas_de_seguros_y_dtf(): void
    {
        $this->sembrar();

        foreach ([2025, 2026] as $anio) {
            $this->assertEquals(0.0003231, PrimaSeguro::where('concepto', 'vida_deudores')->where('anio', $anio)->firstOrFail()->tasa);
            $this->assertEquals(0, PrimaSeguro::where('concepto', 'incendio_terremoto')->where('anio', $anio)->firstOrFail()->tasa);
        }

        $dtf = TasaDtf::firstOrFail();
        $this->assertSame('2026-07-01', $dtf->fecha_vigencia->toDateString());
        $this->assertEquals(9.81, $dtf->dtf_nominal);
    }

    // ───────── Usuarios y empleados ─────────

    public function test_dos_usuarios_con_contrasenas_temporales(): void
    {
        $this->sembrar();

        $administrador = Usuario::findOrFail('1000000000');
        $funcionario = Usuario::findOrFail('1000000001');

        $this->assertTrue($administrador->esPrincipal());
        $this->assertTrue(Hash::check(PracticaSeeder::CLAVE_ADMINISTRADOR, $administrador->contrasena_hash));
        $this->assertTrue(Hash::check(PracticaSeeder::CLAVE_FUNCIONARIO, $funcionario->contrasena_hash));
        $this->assertFalse($funcionario->tienePermiso('prestamos.cargar_vigentes'));
        $this->assertFalse($funcionario->tienePermiso('usuarios.gestionar'));

        $guia = file_get_contents(base_path('docs/guia-practica.md'));
        $this->assertStringContainsString(PracticaSeeder::CLAVE_ADMINISTRADOR, $guia);
        $this->assertStringContainsString(PracticaSeeder::CLAVE_FUNCIONARIO, $guia);
    }

    public function test_los_empleados_son_ficticios_y_variados(): void
    {
        $this->sembrar();

        $empleados = Empleado::all();
        $this->assertCount(20, $empleados);

        foreach ($empleados as $empleado) {
            $this->assertGreaterThanOrEqual(PracticaSeeder::CEDULA_PRIMERA, (int) $empleado->cedula);
            $this->assertLessThanOrEqual(PracticaSeeder::CEDULA_ULTIMA, (int) $empleado->cedula);
            $this->assertStringContainsString('Prueba', $empleado->nombre_completo);
        }

        foreach (TipoVinculacion::cases() as $tipo) {
            $this->assertTrue($empleados->contains(fn (Empleado $e): bool => $e->tipo_vinculacion === $tipo), "Falta {$tipo->value}");
        }

        $this->assertTrue($empleados->contains('de_carrera', true));
        $this->assertTrue($empleados->contains('de_carrera', false));
        $this->assertSame(1, $empleados->filter(fn (Empleado $e): bool => (float) $e->salario <= PracticaSeeder::SMMLV)->count());
        $this->assertSame(19, $empleados->filter(fn (Empleado $e): bool => (float) $e->salario > PracticaSeeder::SMMLV * 1.5)->count());
        $this->assertTrue($empleados->contains(fn (Empleado $e): bool => $e->prestamos()->exists()));
        $this->assertTrue($empleados->contains(fn (Empleado $e): bool => ! $e->prestamos()->exists()));
    }

    // ───────── Préstamos vigentes ─────────

    public function test_el_csv_de_practica_pasa_la_vista_previa_sin_errores(): void
    {
        $this->sembrar();
        DB::table('desembolsos')->delete();
        DB::table('cuotas')->delete();
        DB::table('prestamos')->delete();

        $contenido = file_get_contents(base_path(PracticaSeeder::ARCHIVO_PRESTAMOS));
        $analisis = app(CargaPrestamosVigentesService::class)->analizar($contenido);

        $this->assertSame([], $analisis['errores_archivo']);
        $this->assertGreaterThanOrEqual(12, $analisis['resumen']['total']);
        $this->assertLessThanOrEqual(15, $analisis['resumen']['total']);
        $this->assertSame(0, $analisis['resumen']['errores']);
        $avisos = collect($analisis['filas'])->pluck('advertencias')->flatten();
        $this->assertTrue($avisos->every(fn (string $aviso): bool => str_contains($aviso, 'dos tramos')), 'Solo el aviso de vivienda por tramos: '.$avisos->implode(' | '));
        $this->assertSame(2, $avisos->count());
    }

    public function test_cubre_las_modalidades_con_distintas_fechas_plazos_y_cuotas_pagadas(): void
    {
        $this->sembrar();

        $prestamos = Prestamo::with('tipoPrestamo')->where('origen', 'carga_inicial')->get();
        $this->assertGreaterThanOrEqual(12, $prestamos->count());

        $this->assertEqualsCanonicalizing(TipoPrestamo::pluck('nombre')->all(), $prestamos->pluck('tipoPrestamo.nombre')->unique()->values()->all());
        $this->assertGreaterThan(5, $prestamos->pluck('fecha_autorizacion')->map->toDateString()->unique()->count());
        $this->assertGreaterThan(5, $prestamos->pluck('num_cuotas')->unique()->count());
        $this->assertGreaterThan(5, $prestamos->map->cuotasPagadas()->unique()->count());
        $this->assertSame(0, Pago::count());
    }

    public function test_las_proximas_cuotas_caen_en_varias_quincenas_y_la_en_curso_y_la_siguiente_tienen_varios_empleados(): void
    {
        $this->sembrar();

        $proximas = Cuota::where('estado', EstadoCuota::Pendiente)->get()->groupBy('id_prestamo')->map(fn ($cuotas) => $cuotas->sortBy('numero_cuota')->first()->fecha_vencimiento->toDateString());
        $fechas = $proximas->unique()->values();

        $this->assertGreaterThanOrEqual(5, $fechas->count());
        $this->assertTrue($fechas->contains(fn (string $f): bool => str_ends_with($f, '-15')), 'Alguna vence el 15.');
        $this->assertTrue($fechas->contains(fn (string $f): bool => ! str_ends_with($f, '-15')), 'Alguna vence a fin de mes.');

        $calendario = app(CalendarioQuincenalService::class);
        $quincenas = app(QuincenaService::class);
        $enCurso = $quincenas->quincenaEnCurso();
        $siguiente = $calendario->quincenaSiguiente($calendario->quincena($enCurso['anio'], $enCurso['numero']));

        foreach ([$enCurso, $siguiente] as $quincena) {
            $cuotas = $quincenas->procesar($quincena['anio'], $quincena['numero'])['cuotas'];
            $this->assertGreaterThanOrEqual(3, count($cuotas), "La quincena {$quincena['numero']} debe mostrar varios empleados.");
        }
    }

    // ───────── Solicitudes ─────────

    public function test_hay_cuatro_solicitudes_radicadas(): void
    {
        $this->sembrar();

        $this->assertSame(4, SolicitudCredito::count());
        $this->assertSame(4, SolicitudCredito::where('estado', EstadoSolicitud::Radicada)->count());
    }

    /**
     * @return list<string>
     */
    private function codigos(string $cedula): array
    {
        $solicitud = SolicitudCredito::where('cedula_empleado', $cedula)->firstOrFail();

        return array_column(app(SolicitudCreditoService::class)->incumplimientos($solicitud), 'codigo');
    }

    public function test_la_solicitud_normal_se_puede_aprobar(): void
    {
        $this->sembrar();

        $this->assertSame([], $this->codigos(PracticaSeeder::CEDULA_APROBABLE));

        $solicitud = SolicitudCredito::where('cedula_empleado', PracticaSeeder::CEDULA_APROBABLE)->firstOrFail();
        $prestamo = app(SolicitudCreditoService::class)->aprobar($solicitud, Usuario::findOrFail('1000000000'));

        $this->assertSame(EstadoSolicitud::Aprobada, $solicitud->fresh()->estado);
        $this->assertTrue($prestamo->pendiente_desembolso);
    }

    public function test_la_solicitud_sin_capacidad_no_cumple_solo_por_capacidad(): void
    {
        $this->sembrar();

        $this->assertSame(['capacidad_excedida'], $this->codigos(PracticaSeeder::CEDULA_SIN_CAPACIDAD));
        $this->expectException(RuntimeException::class);
        app(SolicitudCreditoService::class)->aprobar(SolicitudCredito::where('cedula_empleado', PracticaSeeder::CEDULA_SIN_CAPACIDAD)->firstOrFail(), Usuario::findOrFail('1000000000'));
    }

    public function test_la_solicitud_de_salario_minimo_esta_bloqueada(): void
    {
        $this->sembrar();

        $this->assertContains('salario_minimo', $this->codigos(PracticaSeeder::CEDULA_SALARIO_MINIMO));
        $this->expectException(RuntimeException::class);
        app(SolicitudCreditoService::class)->aprobar(SolicitudCredito::where('cedula_empleado', PracticaSeeder::CEDULA_SALARIO_MINIMO)->firstOrFail(), Usuario::findOrFail('1000000000'));
    }

    public function test_la_solicitud_del_docente_ocasional_esta_bloqueada(): void
    {
        $this->sembrar();

        $this->assertContains('tipo_vinculacion', $this->codigos(PracticaSeeder::CEDULA_DOCENTE_OCASIONAL));
        $this->expectException(RuntimeException::class);
        app(SolicitudCreditoService::class)->aprobar(SolicitudCredito::where('cedula_empleado', PracticaSeeder::CEDULA_DOCENTE_OCASIONAL)->firstOrFail(), Usuario::findOrFail('1000000000'));
    }

    // ───────── Repetir ─────────

    public function test_sembrar_dos_veces_no_duplica_ni_falla(): void
    {
        $this->sembrar();
        $prestamos = Prestamo::count();
        $this->sembrar();

        $this->assertSame($prestamos, Prestamo::count());
        $this->assertSame(20, Empleado::count());
        $this->assertSame(4, SolicitudCredito::count());
        $this->assertSame(8, TasaInt::where('anio', 2026)->count());
    }
}
