<?php

namespace Tests\Unit;

use App\Services\CalculoFinancieroService;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Valores tomados de los Excel reales de Bienestar Social.
 */
class CalculoFinancieroServiceTest extends TestCase
{
    private const TOLERANCIA = 0.01;

    /**
     * @return array<string, array{0: string, 1: float, 2: float, 3: int, 4: float, 5: float}>
     */
    public static function casosReales(): array
    {
        return [
            'quincenal: 1.158.589 al 1,61 % E.A. en 7 quincenas' => ['quincenal', 1_158_589, 1.61, 7, 165_953.74, 771.29],
            'mensual: 2.251.738 al 10,22 % E.A. en 5 meses' => ['mensual', 2_251_738, 10.22, 5, 461_407.23, 18_333.61],
            'mensual: 29.087.609 al 10,22 % E.A. en 47 meses' => ['mensual', 29_087_609, 10.22, 47, 747_320.44, 236_830.80],
        ];
    }

    private function tasaPeriodica(CalculoFinancieroService $calculo, string $periodicidad, float $tasaEfectivaAnual): float
    {
        return $periodicidad === 'quincenal'
            ? $calculo->tasaPeriodicaQuincenal($tasaEfectivaAnual)
            : $calculo->tasaPeriodicaMensual($tasaEfectivaAnual);
    }

    #[DataProvider('casosReales')]
    public function test_cuota_fija_coincide_con_el_excel_real(string $periodicidad, float $monto, float $tasaEfectivaAnual, int $periodos, float $cuotaEsperada, float $interesEsperado): void
    {
        $calculo = new CalculoFinancieroService;
        $tasaPeriodica = $this->tasaPeriodica($calculo, $periodicidad, $tasaEfectivaAnual);

        $this->assertEqualsWithDelta($cuotaEsperada, $calculo->cuotaFija($monto, $tasaPeriodica, $periodos), self::TOLERANCIA);
    }

    #[DataProvider('casosReales')]
    public function test_primer_interes_coincide_con_el_excel_real(string $periodicidad, float $monto, float $tasaEfectivaAnual, int $periodos, float $cuotaEsperada, float $interesEsperado): void
    {
        $calculo = new CalculoFinancieroService;
        $tasaPeriodica = $this->tasaPeriodica($calculo, $periodicidad, $tasaEfectivaAnual);

        $tabla = $calculo->tablaAmortizacion($monto, $tasaPeriodica, $periodos);

        $this->assertEqualsWithDelta($interesEsperado, $tabla[0]['interes'], self::TOLERANCIA);
    }

    #[DataProvider('casosReales')]
    public function test_la_tabla_termina_en_cero_y_el_capital_suma_el_monto(string $periodicidad, float $monto, float $tasaEfectivaAnual, int $periodos, float $cuotaEsperada, float $interesEsperado): void
    {
        $calculo = new CalculoFinancieroService;
        $tasaPeriodica = $this->tasaPeriodica($calculo, $periodicidad, $tasaEfectivaAnual);

        $tabla = $calculo->tablaAmortizacion($monto, $tasaPeriodica, $periodos);

        $this->assertCount($periodos, $tabla);
        $this->assertEqualsWithDelta(0.0, $tabla[$periodos - 1]['saldo'], self::TOLERANCIA);
        $this->assertEqualsWithDelta($monto, array_sum(array_column($tabla, 'capital')), self::TOLERANCIA);
        $this->assertEqualsWithDelta($cuotaEsperada, $tabla[0]['cuota'], self::TOLERANCIA);
    }

    public function test_la_tasa_quincenal_es_la_efectiva_elevada_a_un_veinticuatroavo(): void
    {
        $calculo = new CalculoFinancieroService;

        $this->assertEqualsWithDelta(1.0161 ** (1 / 24) - 1, $calculo->tasaPeriodicaQuincenal(1.61), 1e-12);
    }

    public function test_la_tasa_mensual_es_la_efectiva_elevada_a_un_doceavo(): void
    {
        $this->assertEqualsWithDelta(1.1022 ** (1 / 12) - 1, (new CalculoFinancieroService)->tasaPeriodicaMensual(10.22), 1e-12);
    }

    public function test_la_tasa_nominal_anual_es_la_periodica_quincenal_por_24_en_porcentaje(): void
    {
        $calculo = new CalculoFinancieroService;
        $esperada = (1.05 ** (1 / 24) - 1) * 24 * 100;

        $this->assertEqualsWithDelta($esperada, $calculo->tasaNominalAnual(5), 1e-9);
        $this->assertEqualsWithDelta($esperada, $calculo->tasaNominalDesdePeriodica($calculo->tasaPeriodicaQuincenal(5)), 1e-9);
    }

    #[DataProvider('casosReales')]
    public function test_el_monto_maximo_para_una_cuota_es_la_inversa_de_la_cuota_fija(string $periodicidad, float $monto, float $tasaEfectivaAnual, int $periodos, float $cuotaEsperada, float $interesEsperado): void
    {
        $calculo = new CalculoFinancieroService;
        $tasaPeriodica = $this->tasaPeriodica($calculo, $periodicidad, $tasaEfectivaAnual);
        $cuota = $calculo->cuotaFija($monto, $tasaPeriodica, $periodos);

        // La cuota está redondeada a centavos, de ahí la tolerancia de un peso.
        $this->assertEqualsWithDelta($monto, $calculo->montoMaximoParaCuota($cuota, $tasaPeriodica, $periodos), 1.0);
        $this->assertSame(1_000_000.0, $calculo->montoMaximoParaCuota(250_000, 0.0, 4));
    }

    public function test_con_tasa_cero_la_cuota_es_el_monto_dividido_en_los_periodos(): void
    {
        $calculo = new CalculoFinancieroService;

        $this->assertSame(250_000.0, $calculo->cuotaFija(1_000_000, 0.0, 4));
        $this->assertEqualsWithDelta(0.0, $calculo->tablaAmortizacion(1_000_000, 0.0, 4)[3]['saldo'], self::TOLERANCIA);
    }

    /**
     * Casos de verificación de la reliquidación contra el Excel del área
     * (E.A. 1,61 %, cuotas quincenales).
     *
     * @return array<string, array{0: float, 1: int, 2: float}>
     */
    public static function casosReliquidacion(): array
    {
        return [
            'saldo 1.158.589 en 7 cuotas' => [1_158_589, 7, 165_953.74],
            'saldo 21.481.144 en 130 cuotas' => [21_481_144, 130, 172_547.78],
        ];
    }

    #[DataProvider('casosReliquidacion')]
    public function test_la_cuota_recalculada_coincide_con_el_excel(float $saldo, int $cuotas, float $cuotaEsperada): void
    {
        $calculo = new CalculoFinancieroService;
        $tasaPeriodica = $calculo->tasaPeriodicaQuincenal(1.61);

        $this->assertEqualsWithDelta($cuotaEsperada, $calculo->cuotaFija($saldo, $tasaPeriodica, $cuotas), self::TOLERANCIA);
    }

    public function test_la_cuota_se_redondea_a_dos_decimales(): void
    {
        $calculo = new CalculoFinancieroService;
        $cuota = $calculo->cuotaFija(1_000_000, $calculo->tasaPeriodicaQuincenal(1.61), 7);

        $this->assertSame(round($cuota, 2), $cuota);
        $this->assertNotSame(1_000_000 / 7, $cuota);
    }

    #[DataProvider('casosReliquidacion')]
    public function test_el_numero_de_cuotas_sugerido_devuelve_n_al_mantener_la_cuota_del_excel(float $saldo, int $cuotas, float $cuota): void
    {
        $calculo = new CalculoFinancieroService;

        // La cuota está redondeada a centavos; el redondeo a 6 decimales evita subir una cuota por ruido.
        $this->assertSame($cuotas, $calculo->numPeriodosParaCuota($saldo, $calculo->tasaPeriodicaQuincenal(1.61), $cuota));
    }

    public function test_el_numero_de_cuotas_sugerido_sube_al_entero_siguiente(): void
    {
        $calculo = new CalculoFinancieroService;

        // Con una cuota apenas menor a la de 7 cuotas, 7 ya no alcanza y se necesitan 8.
        $this->assertSame(8, $calculo->numPeriodosParaCuota(1_158_589, $calculo->tasaPeriodicaQuincenal(1.61), 165_000));
        $this->assertSame(4, $calculo->numPeriodosParaCuota(1_000_000, 0.0, 250_000));
    }

    public function test_el_numero_de_cuotas_sugerido_rechaza_una_cuota_que_no_cubre_el_interes(): void
    {
        $calculo = new CalculoFinancieroService;

        $this->expectException(InvalidArgumentException::class);

        $calculo->numPeriodosParaCuota(100_000_000, $calculo->tasaPeriodicaQuincenal(10), 1_000);
    }
}
