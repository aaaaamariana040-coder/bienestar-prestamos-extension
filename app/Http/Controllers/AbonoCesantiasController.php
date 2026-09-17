<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegistrarAbonoCesantiasRequest;
use App\Models\Prestamo;
use App\Services\CesantiasService;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

class AbonoCesantiasController extends Controller
{
    public function store(RegistrarAbonoCesantiasRequest $request, Prestamo $prestamo, CesantiasService $cesantiasService): RedirectResponse
    {
        $this->authorize('registrarPago', $prestamo);

        try {
            $cesantiasService->registrarAbono(
                $prestamo,
                (int) $request->validated('anio'),
                (float) $request->validated('monto'),
                $request->validated('fecha_abono'),
                $request->user(),
                $request->validated('modo_reliquidacion') ?? 'plazo',
            );
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('prestamos.show', $prestamo)->with('status', 'Abono de cesantías registrado correctamente.');
    }
}
