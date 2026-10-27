<?php

namespace App\Services;

use App\Models\TasaDtf;
use App\Models\Usuario;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * DTF trimestre anticipado para la liquidación por retiro (Acuerdo 19, Art. 40).
 * Por ahora solo se captura y se consulta: nada lo consume todavía. Cuál DTF
 * usar está pendiente de confirmar (pregunta 39), así que soporta los dos
 * criterios y el configurado en config('prestamos.dtf.criterio') decide.
 */
class DtfService
{
    public const CRITERIO_VIGENTE_A_FECHA = 'vigente_a_fecha';

    public const CRITERIO_CIERRE_ANIO_ANTERIOR = 'cierre_anio_anterior';

    public function __construct(private readonly AuditoriaService $auditoriaService)
    {
        //
    }

    /**
     * DTF efectivo anual (%) a partir del nominal trimestre anticipado (%):
     * EA = (1 / (1 − TA/4))^4 − 1, redondeado a los 4 decimales que se guardan.
     */
    public function efectivoAnualDesdeNominal(float $nominal): float
    {
        return round(((1 / (1 - $nominal / 100 / 4)) ** 4 - 1) * 100, 4);
    }

    /**
     * Registra un DTF. Deja en Auditoría el valor anterior (ninguno) y el nuevo.
     *
     * @param  array{fecha_vigencia: string, dtf_nominal: float|string, fuente_normativa?: ?string}  $datos
     */
    public function crear(array $datos, Usuario $actor): TasaDtf
    {
        return DB::transaction(function () use ($datos, $actor): TasaDtf {
            $dtf = TasaDtf::create($this->conEfectivo($datos));

            $this->auditoriaService->registrar(
                $actor,
                'Registro de DTF',
                "DTF vigente desde {$dtf->fecha_vigencia->toDateString()}: anterior sin registro → nuevo {$this->resumen($dtf)}",
            );

            return $dtf;
        });
    }

    /**
     * Edita un DTF y deja en Auditoría el valor anterior y el nuevo.
     *
     * @param  array{fecha_vigencia: string, dtf_nominal: float|string, fuente_normativa?: ?string}  $datos
     * @return bool false si nada cambió y no se escribió nada
     */
    public function actualizar(TasaDtf $dtf, array $datos, Usuario $actor): bool
    {
        $anterior = $this->resumen($dtf);

        $dtf->fill($this->conEfectivo($datos));

        if (! $dtf->isDirty()) {
            return false;
        }

        DB::transaction(function () use ($dtf, $actor, $anterior): void {
            $dtf->save();

            $this->auditoriaService->registrar(
                $actor,
                'Edición de DTF',
                "DTF: anterior {$anterior} → nuevo {$this->resumen($dtf)}",
            );
        });

        return true;
    }

    /**
     * El DTF con la fecha de vigencia más reciente menor o igual a la fecha dada
     * (criterio «vigente a la fecha del retiro»). Null si no hay ninguno.
     */
    public function vigenteEn(CarbonInterface|string $fecha): ?TasaDtf
    {
        $dia = Carbon::parse($fecha)->toDateString();

        return TasaDtf::query()
            ->whereDate('fecha_vigencia', '<=', $dia)
            ->orderByDesc('fecha_vigencia')
            ->first();
    }

    /**
     * El DTF vigente al 31 de diciembre del año anterior (criterio del
     * Acuerdo 19, Art. 40, parágrafo 2). Null si no hay ninguno.
     */
    public function delCierreDeAnio(int $anio): ?TasaDtf
    {
        return $this->vigenteEn(($anio - 1).'-12-31');
    }

    /**
     * El DTF que corresponde a un retiro en la fecha dada, según el criterio
     * configurado. Null si no hay un DTF registrado que aplique.
     */
    public function dtfParaRetiro(CarbonInterface|string $fecha): ?TasaDtf
    {
        $fecha = Carbon::parse($fecha);

        return match (config('prestamos.dtf.criterio')) {
            self::CRITERIO_VIGENTE_A_FECHA => $this->vigenteEn($fecha),
            self::CRITERIO_CIERRE_ANIO_ANTERIOR => $this->delCierreDeAnio($fecha->year),
            default => throw new InvalidArgumentException('El criterio del DTF debe ser «vigente_a_fecha» o «cierre_anio_anterior».'),
        };
    }

    /**
     * @param  array{fecha_vigencia: string, dtf_nominal: float|string, fuente_normativa?: ?string}  $datos
     * @return array<string, mixed>
     */
    private function conEfectivo(array $datos): array
    {
        return [
            'fecha_vigencia' => $datos['fecha_vigencia'],
            'dtf_nominal' => $datos['dtf_nominal'],
            'valor' => $this->efectivoAnualDesdeNominal((float) $datos['dtf_nominal']),
            'fuente_normativa' => $datos['fuente_normativa'] ?? null,
        ];
    }

    private function resumen(TasaDtf $dtf): string
    {
        $fuente = $dtf->fuente_normativa === null || $dtf->fuente_normativa === '' ? 'sin fuente' : $dtf->fuente_normativa;

        return "vigencia {$dtf->fecha_vigencia->toDateString()}, nominal {$dtf->nominal_legible} %, efectivo anual {$dtf->efectivo_legible} %, {$fuente}";
    }
}
