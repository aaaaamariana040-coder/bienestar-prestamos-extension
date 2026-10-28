<?php

namespace Tests\Feature;

use App\Models\Empleado;
use App\Models\Prestamo;
use App\Models\SolicitudCredito;
use App\Models\TasaInt;
use App\Models\TipoPrestamo;
use App\Services\PrestamoService;
use App\Services\TasaVehiculoService;
use Database\Seeders\TasaVehiculoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Crédito de vehículo (Acuerdo 19 de 2022, art. 38): los puntos sobre el IPC salen
 * del tramo anterior al plazo pedido; por debajo del primer tramo rige el de 3 años;
 * el máximo es 7 años (168 quincenas). Aclarado por Bienestar.
 */
class TasaVehiculoPorPlazoTest extends TestCase
{
    use RefreshDatabase;

    private const IPC = 5.0;

    private TipoPrestamo $vehiculo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vehiculo = TipoPrestamo::factory()->create(['nombre' => 'Vehículo', 'plazo_maximo' => 168]);
        TasaInt::factory()->create(['id_tipo' => $this->vehiculo->id_tipo, 'anio' => 2026, 'tasa_anual' => self::IPC]);
        $this->seed(TasaVehiculoSeeder::class);
    }

    /**
     * Plazo en quincenas, puntos en primera solicitud y puntos desde la segunda.
     *
     * @return array<string, array{int, int, int}>
     */
    public static function plazosPrimeraYSegundaSolicitud(): array
    {
        return [
            '24 (1 año) usa el tramo de 3' => [24, 0, 2],
            '48 (2 años) usa el tramo de 3' => [48, 0, 2],
            '72 (3 años exactos)' => [72, 0, 2],
            '84 (3,5 años) usa el de 3' => [84, 0, 2],
            '96 (4 años exactos)' => [96, 1, 3],
            '108 (4,5 años) usa el de 4' => [108, 1, 3],
            '120 (5 años exactos)' => [120, 2, 4],
            '132 (5,5 años) usa el de 5' => [132, 2, 4],
            '144 (6 años) usa el de 5' => [144, 2, 4],
            '150 usa el de 5' => [150, 2, 4],
            '167 usa el de 5' => [167, 2, 4],
            '168 (7 años, máximo)' => [168, 3, 5],
        ];
    }

    #[DataProvider('plazosPrimeraYSegundaSolicitud')]
    public function test_aplica_el_tramo_anterior_en_primera_y_segunda_solicitud(int $plazo, int $puntosPrimera, int $puntosSegunda): void
    {
        $servicio = app(TasaVehiculoService::class);

        $this->assertSame(self::IPC + $puntosPrimera, $servicio->tasaEfectiva(2026, $plazo, true));
        $this->assertSame(self::IPC + $puntosSegunda, $servicio->tasaEfectiva(2026, $plazo, false));
    }

    public function test_un_plazo_mayor_al_maximo_no_tiene_tasa_y_el_mensaje_es_claro(): void
    {
        $servicio = app(TasaVehiculoService::class);

        $this->assertNull($servicio->tramoPara(169, true));
        $this->assertNull($servicio->tramoPara(200, false));
        $this->assertNull($servicio->tasaEfectiva(2026, 169, true));
        $this->assertSame('El plazo máximo del crédito de vehículo es de 7 años (168 quincenas).', $servicio->mensajePlazoExcedido());
    }

    public function test_la_nota_del_tramo_esta_en_lenguaje_claro(): void
    {
        $servicio = app(TasaVehiculoService::class);

        $this->assertSame('Tramo de 5 años: IPC + 2 puntos (plazo solicitado: 6 años)', $servicio->notaDelTramo($servicio->tramoPara(144, true), 144));
        $this->assertSame('Tramo de 3 años: IPC (plazo solicitado: 1 año)', $servicio->notaDelTramo($servicio->tramoPara(24, true), 24));
        $this->assertSame('Tramo de 4 años: IPC + 1 punto (plazo solicitado: 4,5 años)', $servicio->notaDelTramo($servicio->tramoPara(108, true), 108));
    }

    public function test_la_solicitud_y_el_prestamo_usan_el_mismo_tramo(): void
    {
        $empleado = Empleado::factory()->create();
        $solicitud = SolicitudCredito::factory()->create([
            'cedula_empleado' => $empleado->cedula,
            'id_tipo' => $this->vehiculo->id_tipo,
            'plazo_solicitado' => 144,
            'monto_solicitado' => 10_000_000,
            'fecha_solicitud' => '2026-03-10',
        ]);

        $this->assertSame(7.0, app(PrestamoService::class)->resolverTasaDetallada($solicitud, $this->vehiculo, 2026)['tasa']);

        $prestamo = app(PrestamoService::class)->crearDesdeSolicitud($solicitud);

        $this->assertEquals(7.0, (float) $prestamo->tasa_efectiva_anual);
    }

    public function test_desde_la_segunda_solicitud_rige_la_tabla_de_segunda(): void
    {
        $empleado = Empleado::factory()->create();
        Prestamo::factory()->create(['cedula_empleado' => $empleado->cedula, 'id_tipo' => $this->vehiculo->id_tipo]);
        $solicitud = SolicitudCredito::factory()->create([
            'cedula_empleado' => $empleado->cedula,
            'id_tipo' => $this->vehiculo->id_tipo,
            'plazo_solicitado' => 144,
            'fecha_solicitud' => '2026-03-10',
        ]);

        $this->assertSame(9.0, app(PrestamoService::class)->resolverTasaDetallada($solicitud, $this->vehiculo, 2026)['tasa']);
    }

    public function test_el_prestamo_rechaza_un_plazo_mayor_a_168_quincenas(): void
    {
        $solicitud = SolicitudCredito::factory()->create([
            'id_tipo' => $this->vehiculo->id_tipo,
            'plazo_solicitado' => 169,
            'fecha_solicitud' => '2026-03-10',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('El plazo máximo del crédito de vehículo es de 7 años (168 quincenas).');

        app(PrestamoService::class)->crearDesdeSolicitud($solicitud);
    }

    public function test_otras_modalidades_no_cambian(): void
    {
        $educacion = TipoPrestamo::factory()->create(['nombre' => 'Educación', 'plazo_maximo' => 72]);
        TasaInt::factory()->create(['id_tipo' => $educacion->id_tipo, 'anio' => 2026, 'tasa_anual' => 4.85]);

        foreach ([24, 36, 72] as $plazo) {
            $this->assertSame(4.85, app(PrestamoService::class)->tasaDetallada($educacion, 2026, $plazo, true)['tasa']);
        }
    }
}
