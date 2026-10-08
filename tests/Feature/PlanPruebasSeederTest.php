<?php

namespace Tests\Feature;

use App\Enums\EstadoCuota;
use App\Enums\EstadoSolicitud;
use App\Enums\RolUsuario;
use App\Models\Auditoria;
use App\Models\Empleado;
use App\Models\Prestamo;
use App\Models\PresupuestoAnual;
use App\Models\SolicitudCredito;
use App\Models\TipoPrestamo;
use App\Models\Usuario;
use App\Services\PrestamoService;
use App\Services\ReporteService;
use App\Services\SolicitudCreditoService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PlanPruebasSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Prueba de humo: confirma que PlanPruebasSeeder deja preparados los estados de
 * datos que requiere la ejecución manual de docs/plan-pruebas.md. No verifica
 * ningún caso PP en sí.
 */
class PlanPruebasSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(PlanPruebasSeeder::ANIO, 9, 23, 10));

        $this->seed(DatabaseSeeder::class);
        $this->seed(PlanPruebasSeeder::class);
    }

    public function test_crea_el_usuario_funcionario_con_un_prestamo_vigente(): void
    {
        $funcionario = Usuario::findOrFail(PlanPruebasSeeder::CEDULA_FUNCIONARIO);

        $this->assertSame(RolUsuario::FuncionarioBienestar, $funcionario->rol);
        $this->assertTrue(Prestamo::where('cedula_empleado', $funcionario->cedula)->exists());
    }

    public function test_la_modalidad_de_calamidad_queda_sin_presupuesto_disponible_y_con_una_solicitud_radicada(): void
    {
        $calamidad = TipoPrestamo::where('nombre', 'Calamidad doméstica y necesidades especiales')->firstOrFail();

        $presupuesto = PresupuestoAnual::where('id_tipo', $calamidad->id_tipo)->where('anio', PlanPruebasSeeder::ANIO)->firstOrFail();

        $this->assertSame('0.00', $presupuesto->monto_disponible);
        $this->assertTrue(SolicitudCredito::where('id_tipo', $calamidad->id_tipo)->where('estado', EstadoSolicitud::Radicada)->exists());
    }

    public function test_prepara_una_primera_y_una_segunda_solicitud_de_vehiculo(): void
    {
        $vehiculo = TipoPrestamo::where('nombre', 'Vehículo')->firstOrFail();

        $this->assertSame(1, Prestamo::where('cedula_empleado', PlanPruebasSeeder::CEDULA_VEHICULO_SEGUNDA)->where('id_tipo', $vehiculo->id_tipo)->count());
        $this->assertTrue(SolicitudCredito::where('cedula_empleado', PlanPruebasSeeder::CEDULA_VEHICULO_SEGUNDA)->where('estado', EstadoSolicitud::Radicada)->exists());
        $this->assertFalse(Prestamo::where('cedula_empleado', PlanPruebasSeeder::CEDULA_VEHICULO_PRIMERA)->exists());
        $this->assertTrue(SolicitudCredito::where('cedula_empleado', PlanPruebasSeeder::CEDULA_VEHICULO_PRIMERA)->where('estado', EstadoSolicitud::Radicada)->exists());

        $prestamoService = app(PrestamoService::class);
        $segundaSolicitud = SolicitudCredito::where('cedula_empleado', PlanPruebasSeeder::CEDULA_VEHICULO_SEGUNDA)->where('estado', EstadoSolicitud::Radicada)->firstOrFail();
        $primeraSolicitud = SolicitudCredito::where('cedula_empleado', PlanPruebasSeeder::CEDULA_VEHICULO_PRIMERA)->firstOrFail();

        $this->assertEqualsWithDelta(PlanPruebasSeeder::IPC + 2, $prestamoService->resolverTasa($segundaSolicitud, $vehiculo, PlanPruebasSeeder::ANIO)[1], 0.0001);
        $this->assertEqualsWithDelta(PlanPruebasSeeder::IPC, $prestamoService->resolverTasa($primeraSolicitud, $vehiculo, PlanPruebasSeeder::ANIO)[1], 0.0001);
    }

    public function test_el_empleado_de_capacidad_baja_tiene_una_solicitud_que_no_es_viable(): void
    {
        $this->assertSame('100000.00', Empleado::findOrFail(PlanPruebasSeeder::CEDULA_CAPACIDAD_BAJA)->capacidad_endeudamiento);

        $solicitud = SolicitudCredito::where('cedula_empleado', PlanPruebasSeeder::CEDULA_CAPACIDAD_BAJA)->firstOrFail();

        $this->assertContains(
            'La cuota resultante supera la capacidad de endeudamiento del empleado.',
            app(SolicitudCreditoService::class)->validar($solicitud),
        );
    }

    public function test_el_prestamo_de_estado_de_cuenta_esta_desembolsado_con_tres_cuotas_pagadas_y_sin_mora(): void
    {
        $prestamo = Prestamo::where('cedula_empleado', PlanPruebasSeeder::CEDULA_ESTADO_CUENTA)->firstOrFail();

        $this->assertSame(1, $prestamo->desembolsos()->count());
        $this->assertSame(3, $prestamo->cuotas()->where('estado', EstadoCuota::Pagada)->count());
        $this->assertSame(3, $prestamo->pagos()->count());
        $this->assertFalse($prestamo->cuotas()->where('estado', EstadoCuota::Pendiente)->where('fecha_vencimiento', '<', today()->toDateString())->exists());
    }

    public function test_la_cartera_vencida_muestra_dos_cuotas_del_prestamo_en_mora(): void
    {
        $filas = app(ReporteService::class)->carteraVencida();

        $this->assertCount(2, $filas);
        $this->assertSame([25, 10], $filas->pluck('dias_mora')->all());
        $this->assertSame(PlanPruebasSeeder::CEDULA_CUOTA_VENCIDA, $filas->first()->prestamo->cedula_empleado);
    }

    public function test_la_deshipoteca_esta_aprobada_sin_desembolsar_y_existe_el_proveedor(): void
    {
        $prestamo = Prestamo::where('cedula_empleado', PlanPruebasSeeder::CEDULA_DESHIPOTECA)->firstOrFail();

        $this->assertSame(0, $prestamo->desembolsos()->count());
        $this->assertDatabaseHas('proveedores', ['id_proveedor' => PlanPruebasSeeder::ID_PROVEEDOR_DESHIPOTECA]);
    }

    public function test_la_solicitud_lista_cumple_todas_las_condiciones_de_aprobacion(): void
    {
        $solicitud = SolicitudCredito::where('cedula_empleado', PlanPruebasSeeder::CEDULA_SOLICITUD_LISTA)->firstOrFail();

        $this->assertSame([], app(SolicitudCreditoService::class)->validar($solicitud));
    }

    public function test_los_usuarios_sembrados_tienen_el_nombre_y_apellido_confirmados(): void
    {
        $administrador = Usuario::findOrFail(PlanPruebasSeeder::CEDULA_ADMINISTRADOR);
        $funcionario = Usuario::findOrFail(PlanPruebasSeeder::CEDULA_FUNCIONARIO);

        $this->assertSame(['Administrador', 'Bienestar'], [$administrador->nombre, $administrador->apellido]);
        $this->assertSame(['Funcionario', 'Prueba'], [$funcionario->nombre, $funcionario->apellido]);
    }

    public function test_crea_empleados_de_volumen_y_trazas_de_auditoria_de_ambos_usuarios(): void
    {
        $this->assertSame(9 + PlanPruebasSeeder::CANTIDAD_EMPLEADOS_VOLUMEN, Empleado::count());
        $this->assertTrue(Auditoria::where('cedula_usu', PlanPruebasSeeder::CEDULA_ADMINISTRADOR)->exists());
        $this->assertTrue(Auditoria::where('cedula_usu', PlanPruebasSeeder::CEDULA_FUNCIONARIO)->exists());
    }

    public function test_no_se_ejecuta_dos_veces_sobre_la_misma_base_de_datos(): void
    {
        $empleados = Empleado::count();

        $this->seed(PlanPruebasSeeder::class);

        $this->assertSame($empleados, Empleado::count());
    }
}
