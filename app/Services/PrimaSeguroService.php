<?php

namespace App\Services;

use App\Enums\ConceptoSeguro;
use App\Models\PrimaSeguro;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;

class PrimaSeguroService
{
    public function __construct(private readonly AuditoriaService $auditoriaService)
    {
        //
    }

    /**
     * Registra la prima de un concepto y un año, y deja en Auditoría el valor
     * anterior (ninguno) y el nuevo.
     *
     * @param  array{concepto: string, anio: int|string, tasa: float|string}  $datos
     */
    public function crear(array $datos, Usuario $actor): PrimaSeguro
    {
        return DB::transaction(function () use ($datos, $actor): PrimaSeguro {
            $prima = PrimaSeguro::create($datos);

            $this->auditoriaService->registrar(
                $actor,
                'Registro de prima de seguro',
                "Seguro {$prima->concepto->etiqueta()} — año {$prima->anio}: tasa anterior sin registro → nueva {$prima->tasa_legible}",
            );

            return $prima;
        });
    }

    /**
     * Cambia la tasa de una prima ya registrada (el concepto y el año no cambian)
     * y deja en Auditoría el valor anterior y el nuevo.
     *
     * @return bool false si el valor era el mismo y no se escribió nada
     */
    public function actualizar(PrimaSeguro $prima, float $tasa, Usuario $actor): bool
    {
        $anterior = $prima->tasa_legible;

        if (abs((float) $prima->tasa - $tasa) < 1e-12) {
            return false;
        }

        DB::transaction(function () use ($prima, $tasa, $actor, $anterior): void {
            $prima->update(['tasa' => $tasa]);

            $this->auditoriaService->registrar(
                $actor,
                'Edición de prima de seguro',
                "Seguro {$prima->concepto->etiqueta()} — año {$prima->anio}: tasa anterior {$anterior} → nueva {$prima->tasa_legible}",
            );
        });

        return true;
    }

    /**
     * La prima de un concepto para un año: la de ese año; si no hay, la más
     * reciente de un año anterior; y si no hay ninguna, el respaldo de
     * config('prestamos.seguro') para vida de deudores o cero para incendio y
     * terremoto (aún sin definir).
     *
     * @return array{tasa: float, anio: ?int, origen: 'anio'|'reciente'|'respaldo'|'sin_prima'}
     */
    public function tasaParaAnio(ConceptoSeguro $concepto, int $anio): array
    {
        $prima = PrimaSeguro::where('concepto', $concepto->value)->where('anio', $anio)->first();

        if ($prima !== null) {
            return ['tasa' => (float) $prima->tasa, 'anio' => $prima->anio, 'origen' => 'anio'];
        }

        $reciente = PrimaSeguro::where('concepto', $concepto->value)->where('anio', '<', $anio)->orderByDesc('anio')->first();

        if ($reciente !== null) {
            return ['tasa' => (float) $reciente->tasa, 'anio' => $reciente->anio, 'origen' => 'reciente'];
        }

        if ($concepto === ConceptoSeguro::VidaDeudores) {
            return ['tasa' => (float) config('prestamos.seguro.tasa_sobre_monto'), 'anio' => null, 'origen' => 'respaldo'];
        }

        return ['tasa' => 0.0, 'anio' => null, 'origen' => 'sin_prima'];
    }

    /**
     * Estimación del seguro por quincena para el simulador: monto × prima de vida
     * de deudores, más monto × prima de incendio y terremoto si es mayor que cero
     * y la modalidad la lleva (vivienda). Es una estimación: el valor real lo calcula
     * el área de seguros, y no se guarda en la cuota.
     *
     * @return array{total: float, vida: float, incendio: float, tasa_vida: float, tasa_incendio: float, anio_vida: ?int, origen_vida: string, anio_incendio: ?int, origen_incendio: string, anio_simulacion: int}
     */
    public function estimar(float $monto, int $anio, bool $incluyeIncendio): array
    {
        $vida = $this->tasaParaAnio(ConceptoSeguro::VidaDeudores, $anio);
        $incendio = $incluyeIncendio
            ? $this->tasaParaAnio(ConceptoSeguro::IncendioTerremoto, $anio)
            : ['tasa' => 0.0, 'anio' => null, 'origen' => 'sin_prima'];

        $valorVida = round($monto * $vida['tasa'], 2);
        $valorIncendio = $incendio['tasa'] > 0 ? round($monto * $incendio['tasa'], 2) : 0.0;

        return [
            'total' => round($valorVida + $valorIncendio, 2),
            'vida' => $valorVida,
            'incendio' => $valorIncendio,
            'tasa_vida' => $vida['tasa'],
            'tasa_incendio' => $incendio['tasa'],
            'anio_vida' => $vida['anio'],
            'origen_vida' => $vida['origen'],
            'anio_incendio' => $incendio['anio'],
            'origen_incendio' => $incendio['origen'],
            'anio_simulacion' => $anio,
        ];
    }
}
