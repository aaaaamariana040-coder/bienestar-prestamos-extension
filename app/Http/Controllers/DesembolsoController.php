<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDesembolsoRequest;
use App\Models\Prestamo;
use App\Services\CalendarioQuincenalService;
use App\Services\DesembolsoService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class DesembolsoController extends Controller
{
    public function store(StoreDesembolsoRequest $request, Prestamo $prestamo, DesembolsoService $desembolsoService): RedirectResponse
    {
        $this->authorize('registrarDesembolso', $prestamo);

        try {
            $desembolsoService->registrar($prestamo, $request->validated(), $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('prestamos.show', $prestamo)->with('status', 'Desembolso registrado correctamente.');
    }

    /**
     * Primera cuota propuesta (y las quincenas elegibles) para una fecha de desembolso; no guarda nada.
     */
    public function primeraCuota(Request $request, Prestamo $prestamo, CalendarioQuincenalService $calendario): JsonResponse
    {
        $this->authorize('registrarDesembolso', $prestamo);

        $fecha = $request->validate(['fecha' => ['required', 'date_format:Y-m-d']])['fecha'];
        $opciones = $calendario->opcionesPrimeraCuota($fecha);

        return response()->json(array_map(
            fn (?array $opcion): ?array => $opcion === null ? null : [
                'vencimiento' => $opcion['vencimiento'],
                'cierre' => $opcion['cierre'],
                'vencimiento_legible' => Carbon::parse($opcion['vencimiento'])->format('d/m/Y'),
                'cierre_legible' => Carbon::parse($opcion['cierre'])->format('d/m/Y'),
            ],
            $opciones,
        ));
    }
}
