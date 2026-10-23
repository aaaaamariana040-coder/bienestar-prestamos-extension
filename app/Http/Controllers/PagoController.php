<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegistrarAbonoExtraordinarioRequest;
use App\Http\Requests\RegistrarCancelacionRequest;
use App\Http\Requests\RegistrarPagoCuotaRequest;
use App\Http\Requests\VistaPreviaAbonoRequest;
use App\Models\Cuota;
use App\Models\Prestamo;
use App\Services\PagoService;
use App\Services\ReliquidacionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

class PagoController extends Controller
{
    public function storeCuota(RegistrarPagoCuotaRequest $request, Prestamo $prestamo, Cuota $cuota, PagoService $pagoService): RedirectResponse
    {
        $this->authorize('registrarPagoCuota', $prestamo);

        try {
            $pagoService->registrarPagoCuota($prestamo, $cuota, $request->validated('fecha_pago'), $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('prestamos.show', $prestamo)->with('status', 'Pago de cuota registrado correctamente.');
    }

    public function storeAbono(RegistrarAbonoExtraordinarioRequest $request, Prestamo $prestamo, PagoService $pagoService): RedirectResponse
    {
        $this->authorize('registrarAbono', $prestamo);

        try {
            $pagoService->registrarAbonoExtraordinario(
                $prestamo,
                (float) $request->validated('monto'),
                $request->validated('fecha_pago'),
                $request->user(),
                $request->validated('modo_reliquidacion'),
                $request->validated('num_cuotas') !== null ? (int) $request->validated('num_cuotas') : null,
            );
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('prestamos.show', $prestamo)->with('status', 'Abono extraordinario registrado correctamente.');
    }

    /**
     * Vista previa del abono: no guarda nada. Indica si el monto supera el
     * umbral. Si no lo supera no hay elección y el plan es el automático (baja
     * el plazo con la misma cuota); si lo supera, el plan sale cuando el
     * operador ya eligió una opción.
     */
    public function vistaPreviaAbono(VistaPreviaAbonoRequest $request, Prestamo $prestamo, ReliquidacionService $reliquidacionService): JsonResponse
    {
        $this->authorize('registrarAbono', $prestamo);

        $monto = (float) $request->validated('monto');
        $saldoCapital = $reliquidacionService->saldoCapitalPendiente($prestamo);

        if (round($monto, 2) >= $saldoCapital) {
            return response()->json([
                'supera_umbral' => false,
                'umbral_cuotas' => $reliquidacionService->umbralCuotas(),
                'umbral_monto' => round($reliquidacionService->umbralCuotas() * (float) $prestamo->cuota_periodica, 2),
                'cancelacion_total' => true,
                'error' => $reliquidacionService->mensajeAbonoIgualOMayorAlSaldo($saldoCapital),
            ]);
        }

        $respuesta = [
            'supera_umbral' => $reliquidacionService->superaUmbral($prestamo, $monto),
            'umbral_cuotas' => $reliquidacionService->umbralCuotas(),
            'umbral_monto' => round($reliquidacionService->umbralCuotas() * (float) $prestamo->cuota_periodica, 2),
        ];

        $modo = $request->validated('modo_reliquidacion');

        if (! $respuesta['supera_umbral'] || $modo !== null) {
            try {
                $numCuotas = $request->validated('num_cuotas');
                $respuesta['plan'] = collect($reliquidacionService->calcular($prestamo, $monto, (string) $modo, $numCuotas !== null ? (int) $numCuotas : null, ! $respuesta['supera_umbral']))
                    ->except('tasa_periodica')
                    ->all();
            } catch (RuntimeException $e) {
                $respuesta['error'] = $e->getMessage();
            }
        }

        return response()->json($respuesta);
    }

    public function storeCancelacion(RegistrarCancelacionRequest $request, Prestamo $prestamo, PagoService $pagoService): RedirectResponse
    {
        $this->authorize('cancelar', $prestamo);

        try {
            $pagoService->registrarCancelacion($prestamo, $request->validated('fecha_pago'), $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('prestamos.show', $prestamo)->with('status', 'Préstamo cancelado correctamente.');
    }
}
