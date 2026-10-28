<?php

namespace App\Services;

use App\Models\TasaInt;
use App\Models\TasaVehiculo;

class TasaVehiculoService
{
    /** Plazo máximo del crédito de vehículo: 7 años (Acuerdo 19 de 2022, art. 38). */
    public const PLAZO_MAXIMO_QUINCENAS = 168;

    private const QUINCENAS_POR_ANIO = 24;

    public function tasaEfectiva(int $anio, int $plazoQuincenas, bool $esPrimeraSolicitud): ?float
    {
        $componentes = $plazoQuincenas > self::PLAZO_MAXIMO_QUINCENAS ? null : $this->componentes($anio, $plazoQuincenas, $esPrimeraSolicitud);

        return $componentes === null ? null : round($componentes['base'] + (float) $componentes['puntos'], 4);
    }

    /**
     * Tramo de la tabla de puntos que se aplica a un plazo en quincenas: el
     * tramo anterior (el mayor que no supera el plazo pedido) y, si el plazo
     * es menor que el primer tramo, el primero. Null si el plazo pasa del
     * máximo o no hay tramos registrados para ese tipo de solicitud.
     *
     * @return array{anios: int, puntos: float}|null
     */
    public function tramoPara(int $plazoQuincenas, bool $esPrimeraSolicitud): ?array
    {
        if ($plazoQuincenas > self::PLAZO_MAXIMO_QUINCENAS) {
            return null;
        }

        $tramos = TasaVehiculo::where('es_primera_solicitud', $esPrimeraSolicitud)->orderBy('plazo_anios')->get();

        if ($tramos->isEmpty()) {
            return null;
        }

        $aplicado = $tramos->last(fn (TasaVehiculo $tramo): bool => $tramo->plazo_anios * self::QUINCENAS_POR_ANIO <= $plazoQuincenas) ?? $tramos->first();

        return ['anios' => (int) $aplicado->plazo_anios, 'puntos' => (float) $aplicado->puntos_adicionales_ipc];
    }

    /**
     * Texto del tramo aplicado, p. ej. «Tramo de 5 años: IPC + 2 puntos (plazo solicitado: 6 años)».
     *
     * @param  array{anios: int, puntos: float}  $tramo
     */
    public function notaDelTramo(array $tramo, int $plazoQuincenas): string
    {
        $puntos = $tramo['puntos'] == 0.0 ? 'IPC' : 'IPC + '.$this->numero($tramo['puntos']).($tramo['puntos'] == 1.0 ? ' punto' : ' puntos');

        return "Tramo de {$tramo['anios']} ".($tramo['anios'] === 1 ? 'año' : 'años').": {$puntos} (plazo solicitado: ".$this->aniosLegibles($plazoQuincenas).')';
    }

    public function mensajePlazoExcedido(): string
    {
        return 'El plazo máximo del crédito de vehículo es de 7 años ('.self::PLAZO_MAXIMO_QUINCENAS.' quincenas).';
    }

    /**
     * Tasa base (IPC) y puntos adicionales por separado. `puntos` es null
     * cuando no hay tramo para ese plazo y tipo de solicitud (también si el
     * plazo pasa del máximo); tasaEfectiva() lo toma como 0, pero el simulador
     * lo avisa. Null si no hay tasa base.
     *
     * @return array{base: float, puntos: ?float, tramo: ?array{anios: int, puntos: float}}|null
     */
    public function componentes(int $anio, int $plazoQuincenas, bool $esPrimeraSolicitud): ?array
    {
        $tasaBase = TasaInt::whereHas('tipoPrestamo', fn ($query) => $query->where('nombre', 'Vehículo'))
            ->where('anio', $anio)
            ->value('tasa_anual');

        if ($tasaBase === null) {
            return null;
        }

        $tramo = $this->tramoPara($plazoQuincenas, $esPrimeraSolicitud);

        return ['base' => (float) $tasaBase, 'puntos' => $tramo['puntos'] ?? null, 'tramo' => $tramo];
    }

    private function aniosLegibles(int $plazoQuincenas): string
    {
        $anios = $plazoQuincenas / self::QUINCENAS_POR_ANIO;

        return $this->numero($anios).($anios == 1.0 ? ' año' : ' años');
    }

    private function numero(float $valor): string
    {
        return rtrim(rtrim(number_format($valor, 2, ',', ''), '0'), ',');
    }
}
