<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegistrarAbonoExtraordinarioRequest;
use App\Http\Requests\RegistrarCancelacionRequest;
use App\Http\Requests\RegistrarPagoCuotaRequest;
use App\Models\Cuota;
use App\Models\Prestamo;
use App\Services\PagoService;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

class PagoController extends Controller
{
    public function storeCuota(RegistrarPagoCuotaRequest $request, Prestamo $prestamo, Cuota $cuota, PagoService $pagoService): RedirectResponse
    {
        $this->authorize('registrarPago', $prestamo);

        try {
            $pagoService->registrarPagoCuota($prestamo, $cuota, $request->validated('fecha_pago'), $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('prestamos.show', $prestamo)->with('status', 'Pago de cuota registrado correctamente.');
    }

    public function storeAbono(RegistrarAbonoExtraordinarioRequest $request, Prestamo $prestamo, PagoService $pagoService): RedirectResponse
    {
        $this->authorize('registrarPago', $prestamo);

        try {
            $pagoService->registrarAbonoExtraordinario(
                $prestamo,
                (float) $request->validated('monto'),
                $request->validated('fecha_pago'),
                $request->user(),
            );
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('prestamos.show', $prestamo)->with('status', 'Abono extraordinario registrado correctamente.');
    }

    public function storeCancelacion(RegistrarCancelacionRequest $request, Prestamo $prestamo, PagoService $pagoService): RedirectResponse
    {
        $this->authorize('registrarPago', $prestamo);

        try {
            $pagoService->registrarCancelacion($prestamo, $request->validated('fecha_pago'), $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('prestamos.show', $prestamo)->with('status', 'Préstamo cancelado correctamente.');
    }
}
