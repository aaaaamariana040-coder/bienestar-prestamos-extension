<?php

namespace Tests\Feature;

use App\Enums\EstadoPrestamo;
use App\Enums\EstadoSolicitud;
use App\Models\CuantiaSmmlv;
use App\Models\Empleado;
use App\Models\Prestamo;
use App\Models\PresupuestoAnual;
use App\Models\SmmlvAnual;
use App\Models\SolicitudCredito;
use App\Models\TasaInt;
use App\Models\TipoPrestamo;
use App\Models\Usuario;
use App\Services\SolicitudCreditoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReglasAprobacionTest extends TestCase
{
    use RefreshDatabase;

    private function modalidad(string $nombre = 'Educación', int $plazoMaximo = 480, int $anio = 2026): TipoPrestamo
    {
        $modalidad = TipoPrestamo::factory()->create(['nombre' => $nombre, 'plazo_maximo' => $plazoMaximo]);

        SmmlvAnual::firstOrCreate(['anio' => $anio], ['valor' => 1_300_000]);
        CuantiaSmmlv::factory()->create([
            'id_tipo' => $modalidad->id_tipo,
            'tramo_desde_smmlv' => 0,
            'tramo_hasta_smmlv' => null,
            'cuantia_maxima_smmlv' => 100,
        ]);
        TasaInt::factory()->create(['id_tipo' => $modalidad->id_tipo, 'anio' => $anio, 'tasa_anual' => 4.85]);
        PresupuestoAnual::factory()->create(['id_tipo' => $modalidad->id_tipo, 'anio' => $anio, 'monto_asignado' => 500_000_000]);

        return $modalidad;
    }

    private function solicitud(Empleado $empleado, TipoPrestamo $modalidad, string $fecha = '2026-01-15'): SolicitudCredito
    {
        return SolicitudCredito::factory()->create([
            'cedula_empleado' => $empleado->cedula,
            'id_tipo' => $modalidad->id_tipo,
            'monto_solicitado' => 2_000_000,
            'plazo_solicitado' => 24,
            'fecha_solicitud' => $fecha,
        ]);
    }

    private function empleado(string $fechaVinculacion = '2020-01-15'): Empleado
    {
        return Empleado::factory()->create(['salario' => 3_000_000, 'capacidad_endeudamiento' => 1_500_000, 'fecha_vinculacion' => $fechaVinculacion]);
    }

    private function prestamo(Empleado $empleado, TipoPrestamo $modalidad, float $cuota, EstadoPrestamo $estado): Prestamo
    {
        return Prestamo::factory()->create([
            'cedula_empleado' => $empleado->cedula,
            'id_tipo' => $modalidad->id_tipo,
            'cuota_periodica' => $cuota,
            'estado' => $estado,
        ]);
    }

    public function test_sin_prestamos_la_solicitud_se_valida_como_antes(): void
    {
        $modalidad = $this->modalidad();

        $this->assertSame([], app(SolicitudCreditoService::class)->validar($this->solicitud($this->empleado(), $modalidad)));
    }

    public function test_una_cuota_que_cabe_en_el_total_pero_no_en_el_disponible_se_rechaza_con_los_valores(): void
    {
        $modalidad = $this->modalidad();
        $empleado = $this->empleado();
        $this->prestamo($empleado, $modalidad, 500_000, EstadoPrestamo::Vigente);
        $this->prestamo($empleado, $modalidad, 200_000, EstadoPrestamo::EnMora);

        // Comprometida (500.000 + 200.000) × 2 = 1.400.000; disponible 100.000 de un total de 1.500.000.
        $problemas = app(SolicitudCreditoService::class)->validar($this->solicitud($empleado, $modalidad));

        $this->assertCount(1, $problemas);
        $this->assertStringContainsString('La cuota solicitada (', $problemas[0]);
        $this->assertStringContainsString('Capacidad total: $1.500.000; comprometida con préstamos vigentes: $1.400.000; disponible: $100.000.', $problemas[0]);
    }

    public function test_los_prestamos_cancelados_no_cuentan_para_la_capacidad(): void
    {
        $modalidad = $this->modalidad();
        $empleado = $this->empleado();
        $this->prestamo($empleado, $modalidad, 700_000, EstadoPrestamo::Cancelado);

        $this->assertSame([], app(SolicitudCreditoService::class)->validar($this->solicitud($empleado, $modalidad)));
    }

    public function test_el_prestamo_aprobado_entra_a_la_cuenta_de_la_capacidad_en_las_siguientes_consultas(): void
    {
        $administrador = Usuario::factory()->administrador()->create();
        $modalidad = $this->modalidad();
        $empleado = $this->empleado();
        $primera = $this->solicitud($empleado, $modalidad);

        $this->actingAs($administrador)->getJson(route('simulador.empleado', $empleado->cedula))
            ->assertJsonPath('capacidad.comprometida', 0);

        $this->actingAs($administrador)->patch(route('solicitudes.aprobar', $primera))->assertRedirect();

        $this->assertSame(EstadoSolicitud::Aprobada, $primera->fresh()->estado);
        $cuota = (float) $primera->prestamo()->firstOrFail()->cuota_periodica;

        $capacidad = $this->actingAs($administrador)->getJson(route('simulador.empleado', $empleado->cedula))
            ->assertJsonPath('prestamos.0.cuota_quincenal', $cuota)
            ->json('capacidad');

        $this->assertEqualsWithDelta($cuota * 2, $capacidad['comprometida'], 0.001);
        $this->assertEqualsWithDelta(1_500_000 - $cuota * 2, $capacidad['disponible'], 0.001);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function modalidadesConAntiguedad(): array
    {
        return [
            'vivienda compra' => ['Vivienda — compra de inmueble'],
            'vivienda construcción' => ['Vivienda — construcción'],
            'vivienda deshipoteca' => ['Vivienda — cancelación total de hipoteca (deshipoteca)'],
            'vivienda reparaciones' => ['Vivienda — reparaciones o mejoras'],
            'vivienda cambio' => ['Vivienda — cambio de vivienda'],
            'vehículo' => ['Vehículo'],
        ];
    }

    #[DataProvider('modalidadesConAntiguedad')]
    public function test_once_meses_de_servicio_rechazan_vivienda_y_vehiculo(string $nombre): void
    {
        $modalidad = $this->modalidad($nombre);
        $empleado = $this->empleado('2025-02-15');

        $problemas = app(SolicitudCreditoService::class)->validar($this->solicitud($empleado, $modalidad, '2026-01-15'));

        $this->assertSame(['Para esta modalidad se exige al menos un año de servicio; el empleado lleva 11 meses.'], $problemas);
    }

    #[DataProvider('modalidadesConAntiguedad')]
    public function test_un_año_exacto_de_servicio_cumple(string $nombre): void
    {
        $modalidad = $this->modalidad($nombre);
        $empleado = $this->empleado('2025-01-15');

        $this->assertSame([], app(SolicitudCreditoService::class)->validar($this->solicitud($empleado, $modalidad, '2026-01-15')));
    }

    public function test_un_dia_antes_del_año_todavia_no_cumple(): void
    {
        $modalidad = $this->modalidad('Vehículo');
        $empleado = $this->empleado('2025-01-16');

        $problemas = app(SolicitudCreditoService::class)->validar($this->solicitud($empleado, $modalidad, '2026-01-15'));

        $this->assertSame(['Para esta modalidad se exige al menos un año de servicio; el empleado lleva 11 meses.'], $problemas);
    }

    public function test_calamidad_y_educacion_no_exigen_antiguedad(): void
    {
        $empleado = $this->empleado('2025-12-15');
        $servicio = app(SolicitudCreditoService::class);

        foreach (['Calamidad doméstica y necesidades especiales', 'Educación'] as $nombre) {
            $this->assertSame([], $servicio->validar($this->solicitud($empleado, $this->modalidad($nombre), '2026-01-15')));
        }
    }

    public function test_sin_fecha_de_vinculacion_no_se_puede_comprobar_la_antiguedad(): void
    {
        $modalidad = $this->modalidad('Vehículo');
        $empleado = $this->empleado();
        $empleado->update(['fecha_vinculacion' => null]);

        $problemas = app(SolicitudCreditoService::class)->validar($this->solicitud($empleado, $modalidad));

        $this->assertSame(['Para esta modalidad se exige al menos un año de servicio; el empleado no tiene fecha de vinculación registrada.'], $problemas);
    }

    public function test_el_simulador_muestra_el_tiempo_de_servicio_como_motivo(): void
    {
        $this->actingAs(Usuario::factory()->create());
        $vivienda = $this->modalidad('Vivienda — compra de inmueble', anio: now()->year);
        $empleado = $this->empleado(now()->subMonths(11)->toDateString());

        $resultado = $this->getJson(route('simulador.modalidad', [$empleado->cedula, $vivienda->id_tipo]))->assertOk()->json();

        $this->assertFalse($resultado['puede']);
        $this->assertSame('antiguedad_insuficiente', $resultado['motivo']);
        $this->assertSame(
            'No cumple el tiempo de servicio exigido. Para esta modalidad se exige al menos un año de servicio; el empleado lleva 11 meses.',
            $resultado['mensaje'],
        );
        $this->assertNull($resultado['monto_maximo']);

        $empleado->update(['fecha_vinculacion' => now()->subYear()->toDateString()]);

        $this->getJson(route('simulador.modalidad', [$empleado->cedula, $vivienda->id_tipo]))->assertOk()->assertJsonPath('puede', true);
    }
}
