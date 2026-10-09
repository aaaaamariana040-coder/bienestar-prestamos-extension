<?php

namespace Tests\Unit;

use App\Services\CalendarioQuincenalService;
use Carbon\Carbon;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CalendarioQuincenalServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: int, 3: string, 4: string}>
     */
    public static function fechas(): array
    {
        return [
            '1 de enero' => ['2026-01-01', 2026, 1, '2026-01-01', '2026-01-15'],
            '15 de enero' => ['2026-01-15', 2026, 1, '2026-01-01', '2026-01-15'],
            '16 de enero' => ['2026-01-16', 2026, 2, '2026-01-16', '2026-01-31'],
            '31 de enero' => ['2026-01-31', 2026, 2, '2026-01-16', '2026-01-31'],
            'febrero no bisiesto, primera' => ['2026-02-10', 2026, 3, '2026-02-01', '2026-02-15'],
            'febrero no bisiesto, última' => ['2026-02-28', 2026, 4, '2026-02-16', '2026-02-28'],
            'febrero bisiesto, 28' => ['2028-02-28', 2028, 4, '2028-02-16', '2028-02-29'],
            'febrero bisiesto, 29' => ['2028-02-29', 2028, 4, '2028-02-16', '2028-02-29'],
            'mes de 30 días' => ['2026-04-30', 2026, 8, '2026-04-16', '2026-04-30'],
            '15 de diciembre' => ['2026-12-15', 2026, 23, '2026-12-01', '2026-12-15'],
            '31 de diciembre' => ['2026-12-31', 2026, 24, '2026-12-16', '2026-12-31'],
        ];
    }

    #[DataProvider('fechas')]
    public function test_quincena_de_una_fecha(string $fecha, int $anio, int $numero, string $inicio, string $corte): void
    {
        $quincena = (new CalendarioQuincenalService)->quincenaDeFecha($fecha);

        $this->assertSame($anio, $quincena['anio']);
        $this->assertSame($numero, $quincena['numero']);
        $this->assertSame($inicio, $quincena['inicio']->toDateString());
        $this->assertSame($corte, $quincena['corte']->toDateString());
    }

    public function test_una_fecha_con_hora_se_toma_como_dia_calendario_sin_cambiar_de_zona(): void
    {
        $servicio = new CalendarioQuincenalService;

        $this->assertSame(2, $servicio->quincenaDeFecha(Carbon::parse('2026-01-16 00:00:00', 'UTC'))['numero']);
        $this->assertSame(1, $servicio->quincenaDeFecha(Carbon::parse('2026-01-15 23:59:59', 'UTC'))['numero']);
    }

    public function test_un_instante_cerca_de_la_medianoche_se_convierte_a_la_zona_de_bogota(): void
    {
        $servicio = new CalendarioQuincenalService;
        $instante = Carbon::parse('2026-10-10 03:00:00', 'UTC');

        $quincena = $servicio->quincenaDeInstante($instante);

        $this->assertSame('2026-10-09', $servicio->diaEnBogota($instante)->toDateString());
        $this->assertSame(19, $quincena['numero']);
        $this->assertSame('2026-10-01', $quincena['inicio']->toDateString());
        $this->assertSame('2026-10-15', $quincena['corte']->toDateString());
    }

    public function test_el_cambio_de_quincena_ocurre_a_la_medianoche_de_bogota(): void
    {
        $servicio = new CalendarioQuincenalService;

        // 04:59 UTC del 16 sigue siendo el 15 a las 23:59 en Bogotá; 05:00 UTC ya es el 16.
        $this->assertSame(19, $servicio->quincenaDeInstante(Carbon::parse('2026-10-16 04:59:59', 'UTC'))['numero']);
        $this->assertSame(20, $servicio->quincenaDeInstante(Carbon::parse('2026-10-16 05:00:00', 'UTC'))['numero']);
    }

    public function test_hoy_usa_la_zona_de_bogota_sin_cambiar_la_zona_global(): void
    {
        $zonaGlobal = date_default_timezone_get();
        Carbon::setTestNow(Carbon::parse('2026-10-10 03:00:00', 'UTC'));
        $servicio = new CalendarioQuincenalService;

        $this->assertSame('2026-10-09', $servicio->hoy()->toDateString());
        $this->assertSame(19, $servicio->quincenaActual()['numero']);
        $this->assertSame($zonaGlobal, date_default_timezone_get());
    }

    public function test_quincena_siguiente_y_anterior_dentro_del_anio(): void
    {
        $servicio = new CalendarioQuincenalService;
        $quincena = $servicio->quincenaDeFecha('2026-01-10');

        $siguiente = $servicio->quincenaSiguiente($quincena);
        $this->assertSame([2026, 2, '2026-01-16', '2026-01-31'], [$siguiente['anio'], $siguiente['numero'], $siguiente['inicio']->toDateString(), $siguiente['corte']->toDateString()]);

        $siguienteDeLaSiguiente = $servicio->quincenaSiguiente($siguiente);
        $this->assertSame([2026, 3, '2026-02-01', '2026-02-15'], [$siguienteDeLaSiguiente['anio'], $siguienteDeLaSiguiente['numero'], $siguienteDeLaSiguiente['inicio']->toDateString(), $siguienteDeLaSiguiente['corte']->toDateString()]);

        $this->assertSame(1, $servicio->quincenaAnterior($siguiente)['numero']);
    }

    public function test_quincena_siguiente_y_anterior_cruzan_el_cambio_de_anio(): void
    {
        $servicio = new CalendarioQuincenalService;

        $siguiente = $servicio->quincenaSiguiente($servicio->quincenaDeFecha('2026-12-31'));
        $this->assertSame([2027, 1, '2027-01-01'], [$siguiente['anio'], $siguiente['numero'], $siguiente['inicio']->toDateString()]);

        $anterior = $servicio->quincenaAnterior($servicio->quincenaDeFecha('2027-01-05'));
        $this->assertSame([2026, 24, '2026-12-16', '2026-12-31'], [$anterior['anio'], $anterior['numero'], $anterior['inicio']->toDateString(), $anterior['corte']->toDateString()]);
    }

    public function test_el_anio_tiene_24_quincenas_consecutivas_sin_huecos_ni_traslapes(): void
    {
        $servicio = new CalendarioQuincenalService;
        $quincena = $servicio->quincenaDeFecha('2028-01-01');
        $dias = 0;

        for ($numero = 1; $numero <= 24; $numero++) {
            $this->assertSame($numero, $quincena['numero']);
            $dias += (int) $quincena['inicio']->diffInDays($quincena['corte']) + 1;
            $quincena = $servicio->quincenaSiguiente($quincena);
        }

        $this->assertSame(366, $dias);
        $this->assertSame([2029, 1], [$quincena['anio'], $quincena['numero']]);
    }

    public function test_son_quincenas_de_cierre_de_mes_las_pares(): void
    {
        $servicio = new CalendarioQuincenalService;

        foreach (range(1, 24) as $numero) {
            $this->assertSame($numero % 2 === 0, $servicio->esQuincenaDeCierreDeMes($numero), "quincena {$numero}");
        }

        $this->assertTrue($servicio->esQuincenaDeCierreDeMes($servicio->quincenaDeFecha('2026-02-28')['numero']));
        $this->assertFalse($servicio->esQuincenaDeCierreDeMes($servicio->quincenaDeFecha('2026-02-15')['numero']));
    }

    public function test_rechaza_una_quincena_fuera_del_rango_1_a_24(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new CalendarioQuincenalService)->quincena(2026, 25);
    }
}
