<?php

namespace Tests\Feature;

use App\Enums\EstadoPrestamo;
use App\Enums\EstadoSolicitud;
use App\Models\Cuota;
use App\Models\DocumentoSolicitud;
use App\Models\Empleado;
use App\Models\Prestamo;
use App\Models\PresupuestoAnual;
use App\Models\SmmlvAnual;
use App\Models\SolicitudCredito;
use App\Models\TipoPrestamo;
use App\Models\Usuario;
use Database\Seeders\DemoLimpiezaSeeder;
use Database\Seeders\DemoSeeder;
use Database\Seeders\TipoPrestamoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(TipoPrestamoSeeder::class);
        Usuario::factory()->administrador()->create(['cedula' => '1000000000']);
        $this->seed(DemoSeeder::class);
        $this->actingAs(Usuario::factory()->create());
    }

    /**
     * @return array<string, mixed>
     */
    private function modalidad(string $cedula, string $nombre): array
    {
        $idTipo = TipoPrestamo::where('nombre', $nombre)->firstOrFail()->id_tipo;

        return $this->getJson(route('simulador.modalidad', [$cedula, $idTipo]))->assertOk()->json();
    }

    private function viviendaCompra(): string
    {
        return 'Vivienda — compra de inmueble';
    }

    public function test_crea_los_ocho_escenarios_con_cedulas_ficticias_del_rango_reservado(): void
    {
        $cedulas = Empleado::orderBy('cedula')->pluck('cedula')->all();

        $this->assertCount(8, $cedulas);
        $this->assertSame('9000000001', $cedulas[0]);
        $this->assertSame('9000000008', $cedulas[7]);
    }

    public function test_es_idempotente_y_no_duplica_nada_al_correrlo_dos_veces(): void
    {
        $antes = [Empleado::count(), Prestamo::count(), SolicitudCredito::count(), Cuota::count(), DocumentoSolicitud::count(), PresupuestoAnual::sum('monto_comprometido')];

        $this->seed(DemoSeeder::class);

        $despues = [Empleado::count(), Prestamo::count(), SolicitudCredito::count(), Cuota::count(), DocumentoSolicitud::count(), PresupuestoAnual::sum('monto_comprometido')];
        $this->assertEquals($antes, $despues);
    }

    public function test_los_prestamos_vigentes_tienen_su_tabla_de_cuotas_real(): void
    {
        $prestamo = Prestamo::where('cedula_empleado', DemoSeeder::CEDULA_CALAMIDAD_VIGENTE)->firstOrFail();

        $this->assertSame(EstadoPrestamo::Vigente, $prestamo->estado);
        $this->assertSame(12, $prestamo->cuotas()->count());
        $this->assertEqualsWithDelta((float) $prestamo->monto_aprobado, (float) $prestamo->cuotas()->sum('valor_capital'), 0.01);
        $this->assertNotNull($prestamo->tasa_efectiva_anual);
    }

    public function test_escenario_a_antiguo_sin_prestamos_puede_pedir_vivienda_y_vehiculo_como_primera_solicitud(): void
    {
        $empleado = $this->getJson(route('simulador.empleado', DemoSeeder::CEDULA_ANTIGUO_SIN_PRESTAMOS))->assertOk()->json();

        $this->assertTrue($empleado['elegible']);
        $this->assertSame([], $empleado['prestamos']);
        $this->assertSame($empleado['capacidad']['total'], $empleado['capacidad']['disponible']);

        $this->assertTrue($this->modalidad(DemoSeeder::CEDULA_ANTIGUO_SIN_PRESTAMOS, $this->viviendaCompra())['puede']);
        $vehiculo = $this->modalidad(DemoSeeder::CEDULA_ANTIGUO_SIN_PRESTAMOS, 'Vehículo');
        $this->assertTrue($vehiculo['puede']);
        $this->assertEqualsWithDelta(DemoSeeder::IPC + 3, $vehiculo['tasa_efectiva_anual'], 0.0001);
    }

    public function test_escenario_b_el_prestamo_de_calamidad_consume_solo_una_parte_de_la_capacidad(): void
    {
        $empleado = $this->getJson(route('simulador.empleado', DemoSeeder::CEDULA_CALAMIDAD_VIGENTE))->assertOk()->json();

        $this->assertCount(1, $empleado['prestamos']);
        $this->assertSame('Calamidad doméstica y necesidades especiales', $empleado['prestamos'][0]['modalidad']);
        $this->assertGreaterThan(0, $empleado['capacidad']['comprometida']);
        $this->assertGreaterThan(0, $empleado['capacidad']['disponible']);
        $this->assertLessThan($empleado['capacidad']['total'], $empleado['capacidad']['disponible']);

        $vivienda = $this->modalidad(DemoSeeder::CEDULA_CALAMIDAD_VIGENTE, $this->viviendaCompra());
        $this->assertTrue($vivienda['puede']);
        $this->assertSame('capacidad', $vivienda['limitado_por']);
        $this->assertLessThanOrEqual($empleado['capacidad']['disponible'], $vivienda['cuota_quincenal'] * 2);
    }

    public function test_escenario_c_capacidad_agotada(): void
    {
        $empleado = $this->getJson(route('simulador.empleado', DemoSeeder::CEDULA_CAPACIDAD_AGOTADA))->assertOk()->json();

        $this->assertCount(2, $empleado['prestamos']);
        $this->assertSame(0.0, (float) $empleado['capacidad']['disponible']);

        $resultado = $this->modalidad(DemoSeeder::CEDULA_CAPACIDAD_AGOTADA, 'Educación');
        $this->assertFalse($resultado['puede']);
        $this->assertSame('capacidad_agotada', $resultado['motivo']);
    }

    public function test_escenario_d_menos_de_un_anio_se_rechaza_en_vivienda_y_vehiculo_pero_no_en_educacion(): void
    {
        foreach ([$this->viviendaCompra(), 'Vehículo'] as $modalidad) {
            $resultado = $this->modalidad(DemoSeeder::CEDULA_MENOS_DE_UN_ANIO, $modalidad);

            $this->assertFalse($resultado['puede']);
            $this->assertSame('antiguedad_insuficiente', $resultado['motivo']);
            $this->assertStringContainsString('No cumple el tiempo de servicio exigido', $resultado['mensaje']);
        }

        $this->assertTrue($this->modalidad(DemoSeeder::CEDULA_MENOS_DE_UN_ANIO, 'Educación')['puede']);
    }

    public function test_escenario_e_retirado_no_puede_solicitar(): void
    {
        $this->getJson(route('simulador.empleado', DemoSeeder::CEDULA_RETIRADO))
            ->assertOk()
            ->assertJson(['estado' => 'Retirado', 'elegible' => false, 'mensaje' => 'El empleado está retirado y no puede solicitar un crédito.']);
    }

    public function test_escenario_f_salario_alto_usa_el_tramo_de_mas_de_8_smmlv(): void
    {
        $smmlv = (float) SmmlvAnual::where('anio', now()->year)->value('valor');
        $empleado = $this->getJson(route('simulador.empleado', DemoSeeder::CEDULA_SALARIO_ALTO))->assertOk()->json();

        $this->assertGreaterThan(8 * $smmlv, $empleado['salario']);

        $vivienda = $this->modalidad(DemoSeeder::CEDULA_SALARIO_ALTO, $this->viviendaCompra());
        $this->assertTrue($vivienda['puede']);
        $this->assertEqualsWithDelta(floor(220 * $smmlv), $vivienda['monto_maximo'], 0.001);
        $this->assertSame('cuantia', $vivienda['limitado_por']);
    }

    public function test_escenario_g_vehiculo_pagado_tiene_otra_tasa_en_la_segunda_solicitud(): void
    {
        $prestamo = Prestamo::where('cedula_empleado', DemoSeeder::CEDULA_VEHICULO_PAGADO)->firstOrFail();
        $this->assertSame(EstadoPrestamo::Cancelado, $prestamo->estado);
        $this->assertSame(0, $prestamo->cuotas()->where('estado', 'Pendiente')->count());

        $segunda = $this->modalidad(DemoSeeder::CEDULA_VEHICULO_PAGADO, 'Vehículo');
        $primera = $this->modalidad(DemoSeeder::CEDULA_ANTIGUO_SIN_PRESTAMOS, 'Vehículo');

        $this->assertTrue($segunda['puede']);
        $this->assertEqualsWithDelta(DemoSeeder::IPC + 5, $segunda['tasa_efectiva_anual'], 0.0001);
        $this->assertEqualsWithDelta(DemoSeeder::IPC + 3, $primera['tasa_efectiva_anual'], 0.0001);

        $empleado = $this->getJson(route('simulador.empleado', DemoSeeder::CEDULA_VEHICULO_PAGADO))->json();
        $this->assertSame([], $empleado['prestamos']);
    }

    public function test_escenario_h_solicitud_pendiente_con_un_documento_adjunto(): void
    {
        $solicitud = SolicitudCredito::where('cedula_empleado', DemoSeeder::CEDULA_SOLICITUD_PENDIENTE)->firstOrFail();

        $this->assertSame(EstadoSolicitud::Radicada, $solicitud->estado);
        $this->assertSame(1, $solicitud->documentos()->count());

        $documento = $solicitud->documentos()->firstOrFail();
        $this->assertSame('cedula-demo.pdf', $documento->nombre_original);
        $this->assertSame('application/pdf', $documento->tipo_mime);
        Storage::disk('local')->assertExists($documento->ruta);

        $this->get(route('solicitudes.show', $solicitud))->assertOk()->assertSee('cedula-demo.pdf');
    }

    public function test_quitar_los_datos_borra_solo_el_rango_y_devuelve_el_presupuesto(): void
    {
        $real = Empleado::factory()->create(['cedula' => '1098000001']);
        $prestamoReal = Prestamo::factory()->create(['cedula_empleado' => $real->cedula]);
        $rutaDocumento = DocumentoSolicitud::firstOrFail()->ruta;
        $comprometidoReal = PresupuestoAnual::sum('monto_comprometido');
        $this->assertGreaterThan(0, $comprometidoReal);

        $this->seed(DemoLimpiezaSeeder::class);

        $this->assertSame(0, Empleado::whereBetween('cedula', ['9000000001', '9000000020'])->count());
        $this->assertSame(0, SolicitudCredito::whereBetween('cedula_empleado', ['9000000001', '9000000020'])->count());
        $this->assertSame(0, DocumentoSolicitud::count());
        $this->assertSame(0, Cuota::where('id_prestamo', '!=', $prestamoReal->id_prestamo)->count());
        Storage::disk('local')->assertMissing($rutaDocumento);
        $this->assertSame(0.0, (float) PresupuestoAnual::sum('monto_comprometido'));

        $this->assertNotNull(Empleado::find('1098000001'));
        $this->assertNotNull(Prestamo::find($prestamoReal->id_prestamo));

        $this->seed(DemoLimpiezaSeeder::class);
        $this->seed(DemoSeeder::class);
        $this->assertSame(8, Empleado::whereBetween('cedula', ['9000000001', '9000000020'])->count());
    }
}
