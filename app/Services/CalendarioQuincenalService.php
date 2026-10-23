<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Calendario de quincenas del año, sin base de datos. La quincena es un número
 * de 1 a 24: la 1 va del 1 al 15 de enero, la 2 del 16 al 31 de enero, la 3
 * del 1 al 15 de febrero, y así hasta la 24 (16 al 31 de diciembre). Las pares
 * cierran el mes.
 *
 * Para saber qué día es «hoy» usa la zona America/Bogota, sin cambiar la zona
 * global de la aplicación.
 */
class CalendarioQuincenalService
{
    public const ZONA = 'America/Bogota';

    public const QUINCENAS_POR_ANIO = 24;

    /**
     * Quincena que contiene un día calendario. Una cadena «2026-01-16» o un
     * objeto de fecha se toman tal cual, sin convertir de zona: así una fecha
     * guardada en la base (por ejemplo la de vencimiento de una cuota) cae en
     * la quincena que muestra.
     *
     * @return array{anio: int, numero: int, inicio: CarbonImmutable, corte: CarbonImmutable}
     */
    public function quincenaDeFecha(string|DateTimeInterface $fecha): array
    {
        $dia = CarbonImmutable::createFromFormat('Y-m-d', $fecha instanceof DateTimeInterface ? $fecha->format('Y-m-d') : $fecha, self::ZONA);

        if ($dia === false) {
            throw new InvalidArgumentException("Fecha no válida: {$fecha}");
        }

        $mitad = $dia->day <= 15 ? 1 : 0;

        return $this->quincena($dia->year, $dia->month * 2 - $mitad);
    }

    /**
     * Quincena que contiene un instante, mirado en la zona de Bogotá.
     *
     * @return array{anio: int, numero: int, inicio: CarbonImmutable, corte: CarbonImmutable}
     */
    public function quincenaDeInstante(CarbonInterface $instante): array
    {
        return $this->quincenaDeFecha($this->diaEnBogota($instante));
    }

    /**
     * El día de Bogotá en que ocurre un instante (por ejemplo, las 03:00 UTC
     * del 10 de octubre son todavía el 9 en Bogotá).
     */
    public function diaEnBogota(CarbonInterface $instante): CarbonImmutable
    {
        return CarbonImmutable::instance($instante)->setTimezone(self::ZONA)->startOfDay();
    }

    public function hoy(): CarbonImmutable
    {
        return CarbonImmutable::now(self::ZONA)->startOfDay();
    }

    /**
     * @return array{anio: int, numero: int, inicio: CarbonImmutable, corte: CarbonImmutable}
     */
    public function quincenaActual(): array
    {
        return $this->quincenaDeFecha($this->hoy());
    }

    /**
     * La quincena número $numero (1 a 24) de un año, con su fecha de inicio y de corte.
     *
     * @return array{anio: int, numero: int, inicio: CarbonImmutable, corte: CarbonImmutable}
     */
    public function quincena(int $anio, int $numero): array
    {
        if ($numero < 1 || $numero > self::QUINCENAS_POR_ANIO) {
            throw new InvalidArgumentException("La quincena debe estar entre 1 y 24; se recibió {$numero}.");
        }

        $mes = intdiv($numero + 1, 2);
        $primeraMitad = $numero % 2 === 1;
        $primerDiaDelMes = CarbonImmutable::create($anio, $mes, 1, 0, 0, 0, self::ZONA);

        return [
            'anio' => $anio,
            'numero' => $numero,
            'inicio' => $primeraMitad ? $primerDiaDelMes : $primerDiaDelMes->setDay(16),
            'corte' => $primeraMitad ? $primerDiaDelMes->setDay(15) : $primerDiaDelMes->endOfMonth()->startOfDay(),
        ];
    }

    /**
     * @param  array{anio: int, numero: int, inicio: CarbonImmutable, corte: CarbonImmutable}  $quincena
     * @return array{anio: int, numero: int, inicio: CarbonImmutable, corte: CarbonImmutable}
     */
    public function quincenaSiguiente(array $quincena): array
    {
        return $quincena['numero'] === self::QUINCENAS_POR_ANIO
            ? $this->quincena($quincena['anio'] + 1, 1)
            : $this->quincena($quincena['anio'], $quincena['numero'] + 1);
    }

    /**
     * @param  array{anio: int, numero: int, inicio: CarbonImmutable, corte: CarbonImmutable}  $quincena
     * @return array{anio: int, numero: int, inicio: CarbonImmutable, corte: CarbonImmutable}
     */
    public function quincenaAnterior(array $quincena): array
    {
        return $quincena['numero'] === 1
            ? $this->quincena($quincena['anio'] - 1, self::QUINCENAS_POR_ANIO)
            : $this->quincena($quincena['anio'], $quincena['numero'] - 1);
    }

    /**
     * Vencimientos de calendario: el día 15 y el último día real de cada mes (28 o
     * 29 en febrero, 30 o 31 en los demás). Devuelve $cantidad fechas seguidas
     * (Y-m-d), la primera igual o posterior a la fecha base. Cada fecha se
     * calcula por su número de vencimiento contado desde el año 0, no sumando
     * meses a la anterior, para que el fin de mes no se corra.
     *
     * @return list<string>
     */
    public function vencimientosDesde(string|DateTimeInterface $fechaBase, int $cantidad): array
    {
        $base = CarbonImmutable::parse($fechaBase instanceof DateTimeInterface ? $fechaBase->format('Y-m-d') : $fechaBase, self::ZONA)->startOfDay();

        // Cada mes tiene dos vencimientos: el número par es el día 15 y el impar, el último día.
        $numero = ($base->year * 12 + $base->month - 1) * 2 + ($base->day <= 15 ? 0 : 1);
        $fechas = [];

        for ($i = 0; $i < $cantidad; $i++) {
            $actual = $numero + $i;
            $mes = intdiv($actual, 2);
            $primerDia = CarbonImmutable::create(intdiv($mes, 12), $mes % 12 + 1, 1, 0, 0, 0, self::ZONA);

            $fechas[] = ($actual % 2 === 0 ? $primerDia->setDay(15) : $primerDia->endOfMonth())->toDateString();
        }

        return $fechas;
    }

    /**
     * Fecha provisional de cierre de las novedades de nómina para un
     * vencimiento: los días calendario de config('quincena.cierre_novedades_dias_antes')
     * antes de la fecha. Es solo informativa: ninguna operación la consulta
     * para permitir o bloquear algo.
     */
    public function cierreDeNovedades(string|DateTimeInterface $vencimiento): CarbonImmutable
    {
        $fecha = CarbonImmutable::parse($vencimiento instanceof DateTimeInterface ? $vencimiento->format('Y-m-d') : $vencimiento, self::ZONA)->startOfDay();

        return $fecha->subDays(max(0, (int) config('quincena.cierre_novedades_dias_antes')));
    }

    /**
     * Las quincenas pares (2, 4, …, 24) cierran el mes.
     */
    public function esQuincenaDeCierreDeMes(int $numero): bool
    {
        return $numero % 2 === 0;
    }
}
