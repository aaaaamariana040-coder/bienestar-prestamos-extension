<?php

namespace App\Services;

use App\Enums\EstadoCuota;
use App\Enums\EstadoPrestamo;
use App\Enums\TipoPago;
use App\Models\Cuota;
use App\Models\Pago;
use App\Models\Prestamo;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PagoService
{
    /**
     * Pago regular de una cuota. El Art. 12 parágrafo 2 fija cuotas constantes,
     * por lo que un pago regular cubre exactamente el valor de la cuota; el
     * monto se aplica primero a intereses y el resto a capital.
     */
    public function registrarPagoCuota(Prestamo $prestamo, Cuota $cuota, string $fechaPago, Usuario $actor): Pago
    {
        if ($cuota->id_prestamo !== $prestamo->id_prestamo) {
            throw new RuntimeException('La cuota no pertenece a este préstamo.');
        }

        if ($cuota->estado !== EstadoCuota::Pendiente) {
            throw new RuntimeException('La cuota ya fue pagada.');
        }

        return DB::transaction(function () use ($prestamo, $cuota, $fechaPago, $actor) {
            $pago = $prestamo->pagos()->create([
                'id_cuota' => $cuota->id_cuota,
                'fecha_pago' => $fechaPago,
                'monto' => $cuota->valor_total,
                'tipo' => TipoPago::Regular,
                'monto_aplicado_interes' => $cuota->valor_interes,
                'monto_aplicado_capital' => $cuota->valor_capital,
                'cedula_usu' => $actor->cedula,
            ]);

            $cuota->update(['estado' => EstadoCuota::Pagada]);

            $this->cerrarSiNoQuedanCuotasPendientes($prestamo);

            return $pago;
        });
    }

    /**
     * Abono extraordinario a capital (Art. 12 parágrafos 3 y 4): no está ligado
     * a una cuota puntual. La reliquidación de cuota o plazo que puede generar
     * cuando el abono supera 10 cuotas (parágrafo 5) queda fuera de este alcance.
     */
    public function registrarAbonoExtraordinario(Prestamo $prestamo, float $monto, string $fechaPago, Usuario $actor): Pago
    {
        if ($monto <= 0) {
            throw new RuntimeException('El monto del abono debe ser mayor que cero.');
        }

        return $prestamo->pagos()->create([
            'id_cuota' => null,
            'fecha_pago' => $fechaPago,
            'monto' => $monto,
            'tipo' => TipoPago::AbonoExtraordinario,
            'monto_aplicado_interes' => 0,
            'monto_aplicado_capital' => $monto,
            'cedula_usu' => $actor->cedula,
        ]);
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
