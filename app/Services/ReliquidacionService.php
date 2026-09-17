<?php

namespace App\Services;

use App\Enums\EstadoCuota;
use App\Enums\MotivoReliquidacion;
use App\Models\Prestamo;
use App\Models\Reliquidacion;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ReliquidacionService
{
    public function __construct(private readonly PrestamoService $prestamoService)
    {
        //
    }

    /**
     * Umbral del Art. 12 parágrafo 5: un abono extraordinario que supere el
     * valor de 10 cuotas quincenales dispara una nueva liquidación de la
     * cuota según el plazo pendiente.
     */
    public function superaUmbral(Prestamo $prestamo, float $monto): bool
    {
        return (float) $prestamo->cuota_periodica > 0
            && $monto / (float) $prestamo->cuota_periodica > 10;
    }

    /**
     * Recalcula la tabla de amortización pendiente tras reducir el saldo en
     * $montoAbono, manteniendo el plazo (cuota más baja) o manteniendo la
     * cuota (plazo más corto), según elija el beneficiario (Art. 12 §3-4).
     * La tasa periódica se deriva de la propia cuota pendiente más próxima
     * en vez de re-consultar tasa_int, porque los préstamos de vehículo no
     * persisten su tasa anual (se calcula dinámicamente al aprobar).
     */
    public function reliquidar(
        Prestamo $prestamo,
        float $montoAbono,
        MotivoReliquidacion $motivo,
        string $modo,
        string $fecha,
        Usuario $actor,
    ): Reliquidacion {
        $cuotasPendientes = $prestamo->cuotas()->where('estado', EstadoCuota::Pendiente)->orderBy('numero_cuota')->get();

        if ($cuotasPendientes->isEmpty()) {
            throw new RuntimeException('Este préstamo no tiene cuotas pendientes que reliquidar.');
        }

        $saldoAnterior = (float) $cuotasPendientes->sum('valor_capital');

        if ($montoAbono <= 0 || $montoAbono >= $saldoAnterior) {
            throw new RuntimeException('El monto del abono debe ser mayor que cero y menor que el saldo pendiente; para pagar el saldo completo use la cancelación total.');
        }

        $primeraPendiente = $cuotasPendientes->first();
        $tasaPeriodica = $saldoAnterior > 0 ? (float) $primeraPendiente->valor_interes / $saldoAnterior : 0.0;
        $saldoNuevo = round($saldoAnterior - $montoAbono, 2);

        return DB::transaction(function () use ($prestamo, $cuotasPendientes, $primeraPendiente, $saldoAnterior, $saldoNuevo, $tasaPeriodica, $motivo, $modo, $fecha, $actor) {
            $numeroInicial = $primeraPendiente->numero_cuota;

            if ($modo === 'cuota') {
                $numCuotasRestantes = $cuotasPendientes->count();
                $nuevaCuotaPeriodica = $this->prestamoService->calcularCuotaFrancesa($saldoNuevo, $tasaPeriodica, $numCuotasRestantes);
            } else {
                $cuotaActual = (float) $prestamo->cuota_periodica;
                $numCuotasRestantes = $this->numCuotasParaCuotaFija($saldoNuevo, $tasaPeriodica, $cuotaActual);
                $nuevaCuotaPeriodica = $cuotaActual;
            }

            $prestamo->cuotas()->where('estado', EstadoCuota::Pendiente)->delete();

            $this->prestamoService->generarCuotasDesde(
                $prestamo,
                $saldoNuevo,
                $tasaPeriodica,
                $nuevaCuotaPeriodica,
                $numCuotasRestantes,
                $numeroInicial,
                $primeraPendiente->fecha_vencimiento->toDateString(),
            );

            $prestamo->update([
                'cuota_periodica' => $nuevaCuotaPeriodica,
                'num_cuotas' => $numeroInicial - 1 + $numCuotasRestantes,
            ]);

            return $prestamo->reliquidaciones()->create([
                'motivo' => $motivo,
                'saldo_anterior' => $saldoAnterior,
                'saldo_nuevo' => $saldoNuevo,
                'tasa_aplicada' => round($tasaPeriodica * PrestamoService::QUINCENAS_POR_ANIO * 100, 4),
                'fecha' => $fecha,
                'cedula_usu' => $actor->cedula,
            ]);
        });
    }

    private function numCuotasParaCuotaFija(float $saldo, float $tasaPeriodica, float $cuotaFija): int
    {
        if ($tasaPeriodica <= 0) {
            return max(1, (int) ceil($saldo / $cuotaFija));
        }

        $interesPrimeraCuota = $saldo * $tasaPeriodica;

        if ($cuotaFija <= $interesPrimeraCuota) {
            throw new RuntimeException('La cuota actual no alcanza a cubrir el interés del nuevo saldo; reliquide disminuyendo la cuota en vez del plazo.');
        }

        $numCuotas = -log(1 - $interesPrimeraCuota / $cuotaFija) / log(1 + $tasaPeriodica);

        return max(1, (int) ceil($numCuotas));
    }
}
