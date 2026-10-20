<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConfirmarQuincenaRequest;
use App\Models\ProcesoQuincena;
use App\Services\CalendarioQuincenalService;
use App\Services\QuincenaCambioException;
use App\Services\QuincenaService;
use App\Services\QuincenaYaConfirmadaException;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use InvalidArgumentException;
use RuntimeException;

class QuincenaController extends Controller
{
    public function index(QuincenaService $quincenaService): View
    {
        $this->authorize('viewAny', ProcesoQuincena::class);

        return view('quincenas.index', [
            'pendientes' => $quincenaService->quincenasPendientes(),
            'procesos' => ProcesoQuincena::query()->confirmados()->with('usuario')->paginate(15),
        ]);
    }

    /**
     * Vista previa de una quincena: calcula sin escribir nada. Si la quincena ya
     * está confirmada lleva al detalle de su proceso.
     */
    public function vistaPrevia(int $anio, int $quincena, QuincenaService $quincenaService, CalendarioQuincenalService $calendario): View|RedirectResponse
    {
        $this->authorize('confirmar', ProcesoQuincena::class);

        try {
            $periodo = $calendario->quincena($anio, $quincena);
        } catch (InvalidArgumentException $e) {
            abort(404, $e->getMessage());
        }

        if ($periodo['inicio']->greaterThan($calendario->hoy())) {
            return redirect()->route('quincenas.index')->with('error', "La quincena {$quincena} de {$anio} todavía no empieza: solo se confirman quincenas ya iniciadas.");
        }

        if ($proceso = $quincenaService->procesoConfirmado($anio, $quincena)) {
            return redirect()->route('quincenas.proceso', $proceso)->with('error', (new QuincenaYaConfirmadaException($proceso))->getMessage());
        }

        return view('quincenas.vista-previa', [
            'vista' => $quincenaService->procesar($anio, $quincena),
            'fechaPago' => $periodo['corte']->min($calendario->hoy()),
        ]);
    }

    public function confirmar(ConfirmarQuincenaRequest $request, int $anio, int $quincena, QuincenaService $quincenaService): RedirectResponse
    {
        $this->authorize('confirmar', ProcesoQuincena::class);

        try {
            $proceso = $quincenaService->confirmar($anio, $quincena, $request->user(), $request->validated('vistas'), $request->exclusiones());
        } catch (QuincenaYaConfirmadaException $e) {
            return redirect()->route('quincenas.proceso', $e->proceso)->with('error', $e->getMessage());
        } catch (QuincenaCambioException $e) {
            return redirect()->route('quincenas.vista-previa', [$anio, $quincena])
                ->with('error', $e->getMessage())
                ->with('diferencias', $e->diferencias);
        } catch (InvalidArgumentException|RuntimeException $e) {
            return redirect()->route('quincenas.vista-previa', [$anio, $quincena])->with('error', $e->getMessage());
        }

        return redirect()->route('quincenas.proceso', $proceso)->with('status', "Quincena {$quincena} de {$anio} confirmada: ".$proceso->resumen['pagos_creados'].' cuotas pagadas.');
    }

    public function proceso(ProcesoQuincena $proceso): View
    {
        $this->authorize('view', $proceso);

        return view('quincenas.proceso', ['proceso' => $proceso->load('usuario')]);
    }
}
