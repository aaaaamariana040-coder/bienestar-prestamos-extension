<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConfirmarQuincenaRequest;
use App\Http\Requests\DescargarInformeQuincenaRequest;
use App\Models\ProcesoQuincena;
use App\Models\Usuario;
use App\Services\CalendarioQuincenalService;
use App\Services\InformeQuincenaService;
use App\Services\QuincenaCambioException;
use App\Services\QuincenaService;
use App\Services\QuincenaYaConfirmadaException;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class QuincenaController extends Controller
{
    public function index(QuincenaService $quincenaService, CalendarioQuincenalService $calendario): View
    {
        $this->authorize('viewAny', ProcesoQuincena::class);

        $pendientes = $quincenaService->quincenasPendientes();
        $enCurso = $quincenaService->quincenaEnCurso();

        return view('quincenas.index', [
            'pendientes' => $pendientes,
            'procesos' => ProcesoQuincena::query()->confirmados()->with('usuario')->paginate(15),
            'enCurso' => $enCurso,
            'opciones' => $this->opcionesDeQuincena($quincenaService, $calendario, $pendientes, $enCurso),
        ]);
    }

    /**
     * Las quincenas que se pueden abrir desde el selector, cada una con su etiqueta: la que
     * está en curso (la seleccionada por defecto), las pendientes anteriores y las últimas
     * confirmadas. Una quincena confirmada se etiqueta «Confirmada» aunque sea la del momento.
     *
     * @param  list<array{anio: int, numero: int, inicio: string, corte: string, cuotas: int, total: float}>  $pendientes
     * @param  array{anio: int, numero: int, inicio: CarbonImmutable, corte: CarbonImmutable}  $enCurso
     * @return list<array{clave: string, texto: string, etiqueta: string, url: string, abrible: bool, por_defecto: bool}>
     */
    private function opcionesDeQuincena(QuincenaService $quincenaService, CalendarioQuincenalService $calendario, array $pendientes, array $enCurso): array
    {
        $opciones = [];
        $vistas = [];
        $agregar = function (int $anio, int $numero, string $etiqueta) use (&$opciones, &$vistas, $quincenaService, $calendario, $enCurso): void {
            $clave = "{$anio}-{$numero}";

            if (isset($vistas[$clave])) {
                return;
            }

            $vistas[$clave] = true;
            $periodo = $calendario->quincena($anio, $numero);
            $proceso = $quincenaService->procesoConfirmado($anio, $numero);
            $etiqueta = $proceso !== null ? 'Confirmada' : $etiqueta;

            $opciones[] = [
                'clave' => $clave,
                'texto' => "Quincena {$numero} de {$anio} ({$periodo['inicio']->format('d/m')} al {$periodo['corte']->format('d/m')}) — {$etiqueta}",
                'etiqueta' => $etiqueta,
                'url' => $proceso !== null ? route('quincenas.proceso', $proceso) : route('quincenas.vista-previa', [$anio, $numero]),
                'abrible' => $proceso !== null || ($periodo['inicio']->lessThanOrEqualTo($calendario->hoy()) && (bool) request()->user()?->can('quincenas.confirmar')),
                'por_defecto' => $anio === $enCurso['anio'] && $numero === $enCurso['numero'],
            ];
        };

        $agregar($enCurso['anio'], $enCurso['numero'], 'En curso');

        foreach ($pendientes as $pendiente) {
            $agregar($pendiente['anio'], $pendiente['numero'], 'Pendiente anterior');
        }

        foreach (ProcesoQuincena::query()->confirmados()->limit(6)->get() as $proceso) {
            $agregar($proceso->anio, $proceso->numero_quincena, 'Confirmada');
        }

        return $opciones;
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
            'cierreNovedades' => $calendario->cierreDeNovedades($periodo['corte']),
            'avisoCuotaExcluida' => $quincenaService->avisoCuotaExcluida(),
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

    /**
     * Informe por cuota de la vista previa, con las exclusiones marcadas en ese momento.
     */
    public function excelVistaPrevia(DescargarInformeQuincenaRequest $request, int $anio, int $quincena, QuincenaService $quincenaService, CalendarioQuincenalService $calendario, InformeQuincenaService $informes): StreamedResponse|RedirectResponse
    {
        $this->authorize('confirmar', ProcesoQuincena::class);

        try {
            $periodo = $calendario->quincena($anio, $quincena);
        } catch (InvalidArgumentException $e) {
            abort(404, $e->getMessage());
        }

        if ($periodo['inicio']->greaterThan($calendario->hoy())) {
            return redirect()->route('quincenas.index')->with('error', "La quincena {$quincena} de {$anio} todavía no empieza: no hay informe que descargar.");
        }

        if ($proceso = $quincenaService->procesoConfirmado($anio, $quincena)) {
            return redirect()->route('quincenas.proceso', $proceso)->with('error', (new QuincenaYaConfirmadaException($proceso))->getMessage());
        }

        return $this->descargar($informes, $informes->dePrevia($anio, $quincena, $request->exclusiones(), $request->user()), $request->user());
    }

    /**
     * Informe por cuota de una quincena confirmada, con lo que quedó guardado.
     */
    public function excelProceso(ProcesoQuincena $proceso, Request $request, InformeQuincenaService $informes): StreamedResponse
    {
        $this->authorize('view', $proceso);

        return $this->descargar($informes, $informes->deProceso($proceso, $request->user()), $request->user());
    }

    /**
     * @param  array<string, mixed>  $informe
     */
    private function descargar(InformeQuincenaService $informes, array $informe, Usuario $actor): StreamedResponse
    {
        $informes->auditarDescarga($actor, $informe);

        return response()->streamDownload(
            fn () => print ($informes->csv($informe)),
            $informes->nombreArchivo($informe),
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    public function proceso(ProcesoQuincena $proceso, QuincenaService $quincenaService): View
    {
        $this->authorize('view', $proceso);

        return view('quincenas.proceso', [
            'proceso' => $proceso->load('usuario'),
            'avisoCuotaExcluida' => $quincenaService->avisoCuotaExcluida(),
        ]);
    }
}
