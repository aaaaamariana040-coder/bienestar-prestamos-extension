<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDesembolsoRequest;
use App\Models\Prestamo;
use App\Services\DesembolsoService;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

class DesembolsoController extends Controller
{
    public function store(StoreDesembolsoRequest $request, Prestamo $prestamo, DesembolsoService $desembolsoService): RedirectResponse
    {
        $this->authorize('registrarDesembolso', $prestamo);

        try {
            $desembolsoService->registrar($prestamo, $request->validated());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('prestamos.show', $prestamo)->with('status', 'Desembolso registrado correctamente.');
    }
}
