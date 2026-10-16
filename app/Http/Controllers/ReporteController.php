<?php

namespace App\Http\Controllers;

use App\Enums\EstadoPrestamo;
use App\Models\Empleado;
use App\Models\Pago;
use App\Models\TipoPrestamo;
use App\Services\HistorialPagosService;
use App\Services\ReporteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ReporteController extends Controller
{
    public function prestamos(Request $request, ReporteService $reporteService): View
    {
        Gate::authorize('reportes.ver');

        $idTipo = $request->query('id_tipo') !== null ? (int) $request->query('id_tipo') : null;
        $estado = $request->query('estado');
        $desde = $request->query('desde');
        $hasta = $request->query('hasta');

        $filas = $reporteService->prestamosPorModalidad($idTipo, $estado, $desde, $hasta);
        $tiposPrestamo = TipoPrestamo::orderBy('nombre')->get();
        $estados = EstadoPrestamo::cases();

        return view('reportes.prestamos', compact('filas', 'tiposPrestamo', 'estados', 'idTipo', 'estado', 'desde', 'hasta'));
    }

    public function pagos(Request $request, ReporteService $reporteService): View
    {
        Gate::authorize('reportes.ver');

        $desde = $request->query('desde');
        $hasta = $request->query('hasta');

        $filas = $reporteService->pagosPorPeriodo($desde, $hasta);

        return view('reportes.pagos', compact('filas', 'desde', 'hasta'));
    }

    public function historialPagos(Request $request, HistorialPagosService $historial): View
    {
        Gate::authorize('reportes.ver');

        $q = $this->parametrosQ($request, $historial);
        $empleados = $historial->paginaQ($q['cedula']);
        $individual = $q['cedula'] !== '' && $empleados->total() === 1
            ? $this->consultaIndividual($request, $historial, $empleados->first(), $q['cierre'])
            : null;

        $columnasQ = $historial->columnasConDatos();

        return view('reportes.historial-pagos', [
            ...$q,
            'pantalla' => true,
            'columnasQ' => $columnasQ['visibles'],
            'columnasOcultas' => $columnasQ['ocultas'],
            'empleados' => $empleados,
            'lineas' => $historial->lineasDeCredito($empleados->getCollection(), $q['cierre']),
            'individual' => $individual,
            'columnas' => HistorialPagosService::COLUMNAS,
            'historial' => $historial,
            'quincenas' => range(1, HistorialPagosService::QUINCENAS_POR_ANIO),
        ]);
    }

    public function imprimirHistorialPagos(Request $request, HistorialPagosService $historial): View|RedirectResponse
    {
        Gate::authorize('reportes.ver');

        $q = $this->parametrosQ($request, $historial);

        if ($request->query('tipo') === 'individual') {
            $empleados = $historial->todosQ($q['cedula']);
            $individual = $q['cedula'] !== '' && $empleados->count() === 1
                ? $this->consultaIndividual($request, $historial, $empleados->first(), $q['cierre'])
                : null;

            if ($individual === null || $individual['pagos'] === null) {
                return redirect()->route('reportes.historial-pagos', $request->query());
            }

            return view('reportes.historial-pagos-imprimir', [
                ...$q,
                'tipo' => 'individual',
                'empleado' => $empleados->first(),
                'individual' => $individual,
                'historial' => $historial,
            ]);
        }

        $empleados = $historial->todosQ($q['cedula']);

        return view('reportes.historial-pagos-imprimir', [
            ...$q,
            'tipo' => 'q',
            'empleados' => $empleados,
            'lineas' => $historial->lineasDeCredito($empleados, $q['cierre']),
            'columnas' => HistorialPagosService::COLUMNAS,
            'historial' => $historial,
        ]);
    }

    public function cartera(ReporteService $reporteService): View
    {
        Gate::authorize('reportes.ver');

        $filas = $reporteService->carteraVencida();

        return view('reportes.cartera', compact('filas'));
    }

    public function presupuesto(Request $request, ReporteService $reporteService): View
    {
        Gate::authorize('reportes.ver');

        $anio = (int) $request->query('anio', now()->year);
        $presupuestos = $reporteService->ejecucionPresupuesto($anio);

        return view('reportes.presupuesto', compact('anio', 'presupuestos'));
    }

    /**
     * @return array{anio: int, quincena: int, cedula: string, cierre: Carbon}
     */
    private function parametrosQ(Request $request, HistorialPagosService $historial): array
    {
        $actual = $historial->quincenaActual();

        $anio = (int) $request->query('anio', $actual['anio']);
        $quincena = (int) $request->query('quincena', $actual['quincena']);

        if ($anio < 2000 || $anio > 2100) {
            $anio = $actual['anio'];
        }

        if ($quincena < 1 || $quincena > HistorialPagosService::QUINCENAS_POR_ANIO) {
            $quincena = $actual['quincena'];
        }

        return [
            'anio' => $anio,
            'quincena' => $quincena,
            'cedula' => $historial->cedulaDigitos($request->query('cedula')),
            'cierre' => $historial->cierreDeQuincena($anio, $quincena),
        ];
    }

    /**
     * Situación de crédito del empleado y, si se eligió modalidad, sus pagos en el rango.
     *
     * @return array{empleado: Empleado, creditos: list<object>, modalidades: Collection<int, TipoPrestamo>, modalidad: int|null, desde: string, hasta: string, errores: list<string>, pagos: Collection<int, Pago>|null, total: float}
     */
    private function consultaIndividual(Request $request, HistorialPagosService $historial, Empleado $empleado, Carbon $cierre): array
    {
        $modalidades = $historial->modalidadesDe($empleado);
        $desde = (string) $request->query('desde', '');
        $hasta = (string) $request->query('hasta', '');
        $modalidad = $request->query('modalidad');
        $errores = [];
        $pagos = null;

        if ($modalidad !== null && $modalidad !== '') {
            $validador = Validator::make(
                ['modalidad' => $modalidad, 'desde' => $desde, 'hasta' => $hasta],
                [
                    'modalidad' => ['required', Rule::in($modalidades->pluck('id_tipo')->map(fn ($id): string => (string) $id)->all())],
                    'desde' => ['required', 'date_format:Y-m-d'],
                    'hasta' => ['required', 'date_format:Y-m-d', 'after_or_equal:desde'],
                ],
                [
                    'modalidad.in' => 'La modalidad elegida no corresponde a este empleado.',
                    'desde.required' => 'Indica la fecha «Desde».',
                    'desde.date_format' => 'La fecha «Desde» no es válida.',
                    'hasta.required' => 'Indica la fecha «Hasta».',
                    'hasta.date_format' => 'La fecha «Hasta» no es válida.',
                    'hasta.after_or_equal' => 'La fecha «Desde» no puede ser posterior a «Hasta».',
                ],
            );

            if ($validador->fails()) {
                $errores = $validador->errors()->all();
            } else {
                $pagos = $historial->pagosEnRango($empleado, (int) $modalidad, $desde, $hasta);
            }
        }

        return [
            'empleado' => $empleado,
            'creditos' => $historial->creditosDe($empleado, $cierre),
            'modalidades' => $modalidades,
            'modalidad' => $modalidad !== null && $modalidad !== '' ? (int) $modalidad : null,
            'desde' => $desde,
            'hasta' => $hasta,
            'errores' => $errores,
            'pagos' => $pagos,
            'total' => $pagos?->sum(fn (Pago $pago): float => (float) $pago->monto) ?? 0.0,
        ];
    }
}
