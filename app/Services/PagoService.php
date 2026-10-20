<?php

namespace App\Services;

use App\Enums\EstadoCuota;
use App\Enums\EstadoPrestamo;
use App\Enums\MotivoReliquidacion;
use App\Enums\OrigenPago;
use App\Enums\TipoPago;
use App\Models\Cuota;
use App\Models\Pago;
use App\Models\Prestamo;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PagoService
{
    public function __construct(
        private readonly ReliquidacionService $reliquidacionService,
        private readonly AuditoriaService $auditoriaService,
    ) {
        //
    }

    /**
     * Pago regular de una cuota. El Art. 12 parágrafo 2 fija cuotas constantes,
     * por lo que un pago regular cubre exactamente el valor de la cuota; el
     * monto se aplica primero a intereses y el resto a capital. El origen es
     * Manual salvo que lo registre el proceso de la quincena, que además deja
     * el id del proceso para trazar el pago.
     */
    public function registrarPagoCuota(Prestamo $prestamo, Cuota $cuota, string $fechaPago, Usuario $actor, OrigenPago $origen = OrigenPago::Manual, ?int $idProcesoQuincena = null): Pago
    {
        if ($cuota->id_prestamo !== $prestamo->id_prestamo) {
            throw new RuntimeException('La cuota no pertenece a este préstamo.');
        }

        if ($cuota->estado !== EstadoCuota::Pendiente) {
            throw new RuntimeException('La cuota ya fue pagada.');
        }

        return DB::transaction(function () use ($prestamo, $cuota, $fechaPago, $actor, $origen, $idProcesoQuincena) {
            $pago = $prestamo->pagos()->create([
                'id_cuota' => $cuota->id_cuota,
                'fecha_pago' => $fechaPago,
                'monto' => $cuota->valor_total,
                'tipo' => TipoPago::Regular,
                'origen' => $origen,
                'id_proceso_quincena' => $idProcesoQuincena,
                'monto_aplicado_interes' => $cuota->valor_interes,
                'monto_aplicado_capital' => $cuota->valor_capital,
                'cedula_usu' => $actor->cedula,
            ]);

            $cuota->update(['estado' => EstadoCuota::Pagada]);

            $this->cerrarSiNoQuedanCuotasPendientes($prestamo);

            $this->auditoriaService->registrar(
                $actor,
                'Pago de cuota',
                "Préstamo #{$prestamo->id_prestamo} — cuota #{$cuota->numero_cuota} por {$pago->monto}",
            );

            return $pago;
        });
    }

    /**
     * Abono extraordinario a capital (Art. 12 parágrafos 3 y 4): no está ligado
     * a una cuota puntual. Si alcanza el umbral configurado (por defecto 10
     * cuotas) en una modalidad a la que aplica, el operador debe elegir en
     * $modoReliquidacion ('cuota' o 'plazo') y se reliquida (parágrafos 4 y 5);
     * $numCuotas fija a mano el plazo nuevo. Por debajo del umbral solo se
     * registra el pago, sin reliquidación.
     */
    public function registrarAbonoExtraordinario(Prestamo $prestamo, float $monto, string $fechaPago, Usuario $actor, ?string $modoReliquidacion = null, ?int $numCuotas = null): Pago
    {
        if ($monto <= 0) {
            throw new RuntimeException('El monto del abono debe ser mayor que cero.');
        }

        $saldoCapital = round((float) $prestamo->cuotas()->where('estado', EstadoCuota::Pendiente)->sum('valor_capital'), 2);

        if (round($monto, 2) >= $saldoCapital) {
            throw new RuntimeException('El abono debe ser menor que el saldo de capital pendiente; para pagar el saldo completo use «Cancelación total».');
        }

        return DB::transaction(function () use ($prestamo, $monto, $fechaPago, $actor, $modoReliquidacion, $numCuotas) {
            $pago = $prestamo->pagos()->create([
                'id_cuota' => null,
                'fecha_pago' => $fechaPago,
                'monto' => $monto,
                'tipo' => TipoPago::AbonoExtraordinario,
                'monto_aplicado_interes' => 0,
                'monto_aplicado_capital' => $monto,
                'cedula_usu' => $actor->cedula,
            ]);

            if ($this->reliquidacionService->superaUmbral($prestamo, $monto)) {
                $this->reliquidacionService->reliquidar(
                    $prestamo,
                    $monto,
                    MotivoReliquidacion::AbonoExtraordinario,
                    (string) $modoReliquidacion,
                    $fechaPago,
                    $actor,
                    $numCuotas,
                );
            }

            $this->auditoriaService->registrar(
                $actor,
                'Abono extraordinario',
                "Préstamo #{$prestamo->id_prestamo} — abono de {$monto}",
            );

            return $pago;
        });
    }

    /**
     * Cancelación total: paga de una vez el saldo de todas las cuotas pendientes
     * y cierra el préstamo.
     */
    public function registrarCancelacion(Prestamo $prestamo, string $fechaPago, Usuario $actor): Pago
    {
        $cuotasPendientes = $prestamo->cuotas()->where('estado', EstadoCuota::Pendiente)->get();

        if ($cuotasPendientes->isEmpty()) {
            throw new RuntimeException('Este préstamo no tiene cuotas pendientes.');
        }

        return DB::transaction(function () use ($prestamo, $cuotasPendientes, $fechaPago, $actor) {
            $pago = $prestamo->pagos()->create([
                'id_cuota' => null,
                'fecha_pago' => $fechaPago,
                'monto' => $cuotasPendientes->sum('valor_total'),
                'tipo' => TipoPago::Cancelacion,
                'monto_aplicado_interes' => $cuotasPendientes->sum('valor_interes'),
                'monto_aplicado_capital' => $cuotasPendientes->sum('valor_capital'),
                'cedula_usu' => $actor->cedula,
            ]);

            $prestamo->cuotas()->where('estado', EstadoCuota::Pendiente)->update(['estado' => EstadoCuota::Pagada]);
            $prestamo->update(['estado' => EstadoPrestamo::Cancelado]);

            $this->auditoriaService->registrar(
                $actor,
                'Cancelación de préstamo',
                "Préstamo #{$prestamo->id_prestamo} cancelado con pago de {$pago->monto}",
            );

            return $pago;
        });
    }

    private function cerrarSiNoQuedanCuotasPendientes(Prestamo $prestamo): void
    {
        if (! $prestamo->cuotas()->where('estado', EstadoCuota::Pendiente)->exists()) {
            $prestamo->update(['estado' => EstadoPrestamo::Cancelado]);
        }
    }
}
