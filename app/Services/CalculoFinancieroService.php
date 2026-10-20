<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * Cálculo financiero puro, sin acceso a base de datos. Las tasas efectivas
 * anuales (E.A.) entran como porcentaje (10,22 = 10,22 %); las tasas
 * periódicas salen y entran como fracción (0,008 = 0,8 % por periodo).
 */
class CalculoFinancieroService
{
    public const QUINCENAS_POR_ANIO = 24;

    public const MESES_POR_ANIO = 12;

    /**
     * Tasa periódica quincenal: (1 + E.A.)^(1/24) - 1.
     */
    public function tasaPeriodicaQuincenal(float $tasaEfectivaAnualPorcentaje): float
    {
        return (1 + $tasaEfectivaAnualPorcentaje / 100) ** (1 / self::QUINCENAS_POR_ANIO) - 1;
    }

    /**
     * Tasa periódica mensual: (1 + E.A.)^(1/12) - 1.
     */
    public function tasaPeriodicaMensual(float $tasaEfectivaAnualPorcentaje): float
    {
        return (1 + $tasaEfectivaAnualPorcentaje / 100) ** (1 / self::MESES_POR_ANIO) - 1;
    }

    /**
     * Tasa nominal anual en porcentaje: tasa periódica quincenal × 24.
     */
    public function tasaNominalAnual(float $tasaEfectivaAnualPorcentaje): float
    {
        return $this->tasaNominalDesdePeriodica($this->tasaPeriodicaQuincenal($tasaEfectivaAnualPorcentaje));
    }

    /**
     * Tasa nominal anual en porcentaje a partir de una tasa periódica quincenal.
     */
    public function tasaNominalDesdePeriodica(float $tasaPeriodicaQuincenal): float
    {
        return $tasaPeriodicaQuincenal * self::QUINCENAS_POR_ANIO * 100;
    }

    /**
     * Cuota fija (sistema francés) redondeada a 2 decimales.
     */
    public function cuotaFija(float $monto, float $tasaPeriodica, int $numPeriodos): float
    {
        if ($tasaPeriodica <= 0) {
            return round($monto / $numPeriodos, 2);
        }

        $factor = $tasaPeriodica / (1 - (1 + $tasaPeriodica) ** -$numPeriodos);

        return round($monto * $factor, 2);
    }

    /**
     * Número de periodos que tarda en amortizarse un saldo pagando una cuota
     * fija: n = ceil(−ln(1 − S·r / C) / ln(1 + r)), con tolerancia de 1e-6 para
     * que un resultado exacto no suba una cuota por error de coma flotante.
     * La cuota debe superar el interés del saldo (S·r) o nunca se amortiza.
     */
    public function numPeriodosParaCuota(float $saldo, float $tasaPeriodica, float $cuota): int
    {
        if ($tasaPeriodica <= 0) {
            return max(1, (int) ceil($saldo / $cuota));
        }

        $interes = $saldo * $tasaPeriodica;

        if ($cuota <= $interes) {
            throw new InvalidArgumentException('La cuota no alcanza a cubrir el interés del saldo.');
        }

        return max(1, (int) ceil(round(-log(1 - $interes / $cuota) / log(1 + $tasaPeriodica), 6)));
    }

    /**
     * Monto máximo que una cuota fija puede amortizar en un número de periodos
     * (valor presente de la anualidad). Es la inversa de cuotaFija(), sin
     * redondear: el llamador decide cómo redondear el monto.
     */
    public function montoMaximoParaCuota(float $cuota, float $tasaPeriodica, int $numPeriodos): float
    {
        if ($tasaPeriodica <= 0) {
            return $cuota * $numPeriodos;
        }

        return $cuota * (1 - (1 + $tasaPeriodica) ** -$numPeriodos) / $tasaPeriodica;
    }

    /**
     * Tabla de amortización con cuota fija. Si no se indica la cuota se calcula.
     * El interés se redondea a 2 decimales en cada periodo y la última cuota
     * absorbe el saldo restante, de modo que la tabla cierra en cero.
     *
     * @return list<array{numero: int, interes: float, capital: float, cuota: float, saldo: float}>
     */
    public function tablaAmortizacion(float $monto, float $tasaPeriodica, int $numPeriodos, ?float $cuotaFija = null): array
    {
        $cuotaFija ??= $this->cuotaFija($monto, $tasaPeriodica, $numPeriodos);
        $saldo = $monto;
        $tabla = [];

        for ($numero = 1; $numero <= $numPeriodos; $numero++) {
            $interes = round($saldo * $tasaPeriodica, 2);
            $capital = $numero === $numPeriodos ? $saldo : round($cuotaFija - $interes, 2);
            $saldo = round($saldo - $capital, 2);

            $tabla[] = [
                'numero' => $numero,
                'interes' => $interes,
                'capital' => $capital,
                'cuota' => round($capital + $interes, 2),
                'saldo' => $saldo,
            ];
        }

        return $tabla;
    }
}
