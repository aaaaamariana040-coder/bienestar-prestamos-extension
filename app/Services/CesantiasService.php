<?php

namespace App\Services;

use App\Enums\MotivoReliquidacion;
use App\Models\AbonoCesantias;
use App\Models\Prestamo;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CesantiasService
{
    private const PORCENTAJE_LEGAL = 50;

    public function __construct(
        private readonly ReliquidacionService $reliquidacionService,
        private readonly AuditoriaService $auditoriaService,
    ) {
        //
    }

    /**
     * Abono bianual de cesantías, solo para préstamos de vivienda (Art. 13):
     * el 50% de las cesantías del año anterior se abona a la deuda y, a
     * diferencia del abono extraordinario común, siempre genera reliquidación
     * sin importar el monto (Art. 12 parágrafo 6).
     */
    public function registrarAbono(
        Prestamo $prestamo,
        int $anio,
        float $monto,
        string $fechaAbono,
        Usuario $actor,
        string $modoReliquidacion = 'plazo',
    ): AbonoCesantias {
        $prestamo->asegurarDesembolsado();

        if (! str_starts_with($prestamo->tipoPrestamo->nombre, 'Vivienda')) {
            throw new RuntimeException('El abono de cesantías solo aplica a préstamos de vivienda (Art. 13).');
        }

        if ($monto <= 0) {
            throw new RuntimeException('El monto del abono debe ser mayor que cero.');
        }

        return DB::transaction(function () use ($prestamo, $anio, $monto, $fechaAbono, $actor, $modoReliquidacion) {
            $abono = $prestamo->abonosCesantias()->create([
                'anio' => $anio,
                'porcentaje' => self::PORCENTAJE_LEGAL,
                'monto' => $monto,
                'fecha_abono' => $fechaAbono,
            ]);

            $this->reliquidacionService->reliquidar(
                $prestamo,
                $monto,
                MotivoReliquidacion::AbonoExtraordinario,
                $modoReliquidacion,
                $fechaAbono,
                $actor,
            );

            $this->auditoriaService->registrar(
                $actor,
                'Abono de cesantías',
                "Préstamo #{$prestamo->id_prestamo} — año {$anio}, monto {$monto}",
            );

            return $abono;
        });
    }
}
