<?php

namespace Tests\Feature;

use App\Services\DescuentosLeyService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DescuentosLeyServiceTest extends TestCase
{
    private const SMMLV = 1_000_000;

    /**
     * Cada tramo incluye su límite inferior y excluye el superior.
     *
     * @return array<string, array{0: float, 1: float}>
     */
    public static function limitesDeSolidaridad(): array
    {
        return [
            'justo bajo 4 SMMLV' => [3.99, 0.0],
            'exactamente 4 SMMLV' => [4.0, 1.0],
            'justo bajo 16 SMMLV' => [15.99, 1.0],
            'exactamente 16 SMMLV' => [16.0, 1.2],
            'exactamente 17 SMMLV' => [17.0, 1.4],
            'exactamente 18 SMMLV' => [18.0, 1.6],
            'exactamente 19 SMMLV' => [19.0, 1.8],
            'justo bajo 20 SMMLV' => [19.99, 1.8],
            'exactamente 20 SMMLV' => [20.0, 2.0],
            'justo sobre 20 SMMLV' => [20.01, 2.0],
        ];
    }

    #[DataProvider('limitesDeSolidaridad')]
    public function test_el_porcentaje_de_solidaridad_respeta_los_limites_de_los_tramos(float $salarioEnSmmlv, float $porcentajeEsperado): void
    {
        $this->assertSame($porcentajeEsperado, app(DescuentosLeyService::class)->porcentajeSolidaridad($salarioEnSmmlv));
    }

    #[DataProvider('limitesDeSolidaridad')]
    public function test_el_desglose_aplica_el_porcentaje_de_solidaridad_del_tramo(float $salarioEnSmmlv, float $porcentajeEsperado): void
    {
        $salario = $salarioEnSmmlv * self::SMMLV;

        $desglose = app(DescuentosLeyService::class)->calcular($salario, self::SMMLV);

        $this->assertSame($porcentajeEsperado, $desglose['solidaridad']['porcentaje']);
        $this->assertEqualsWithDelta($salario * $porcentajeEsperado / 100, $desglose['solidaridad']['valor'], 0.01);
    }

    public function test_el_desglose_resta_salud_pension_solidaridad_y_retencion_y_toma_el_50_por_ciento(): void
    {
        // 5 SMMLV: salud 4 %, pensión 4 % y solidaridad 1 %.
        $desglose = app(DescuentosLeyService::class)->calcular(5_000_000, self::SMMLV, 100_000);

        $this->assertSame(5_000_000.0, $desglose['salario']);
        $this->assertSame(200_000.0, $desglose['salud']['valor']);
        $this->assertSame(200_000.0, $desglose['pension']['valor']);
        $this->assertSame(50_000.0, $desglose['solidaridad']['valor']);
        $this->assertSame(100_000.0, $desglose['retencion']);
        $this->assertSame(550_000.0, $desglose['total_descuentos']);
        $this->assertSame(4_450_000.0, $desglose['base']);
        $this->assertSame(2_225_000.0, $desglose['capacidad']);
    }

    public function test_la_retencion_es_opcional_y_la_base_no_baja_de_cero(): void
    {
        $servicio = app(DescuentosLeyService::class);

        $this->assertSame(0.0, $servicio->calcular(2_000_000, self::SMMLV)['retencion']);
        $this->assertSame(0.0, $servicio->calcular(2_000_000, self::SMMLV, -50)['retencion']);

        $agotada = $servicio->calcular(2_000_000, self::SMMLV, 5_000_000);
        $this->assertSame(0.0, $agotada['base']);
        $this->assertSame(0.0, $agotada['capacidad']);
    }

    public function test_los_porcentajes_salen_de_la_configuracion(): void
    {
        config([
            'prestamos.simulador.descuentos_ley.salud' => 5.0,
            'prestamos.simulador.descuentos_ley.pension' => 0.0,
        ]);

        $desglose = app(DescuentosLeyService::class)->calcular(2_000_000, self::SMMLV);

        $this->assertSame(100_000.0, $desglose['salud']['valor']);
        $this->assertSame(0.0, $desglose['pension']['valor']);
        $this->assertSame(1_900_000.0, $desglose['base']);
    }
}
