<?php

namespace App\Http\Controllers;

use App\Http\Requests\SimularCreditoRequest;
use App\Models\Empleado;
use App\Models\TipoPrestamo;
use App\Services\CalendarioQuincenalService;
use App\Services\SimuladorCreditoService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class SimuladorController extends Controller
{
    public function __construct(private readonly SimuladorCreditoService $simuladorCreditoService)
    {
        //
    }

    public function index(): View
    {
        Gate::authorize('simulador.usar');

        return view('simulador.index');
    }

    public function empleado(string $cedula): JsonResponse
    {
        Gate::authorize('simulador.usar');

        $datos = $this->simuladorCreditoService->consultarEmpleado($cedula);

        return response()->json($datos, $datos['encontrado'] ? 200 : 404);
    }

    public function modalidad(string $cedula, TipoPrestamo $tipo_prestamo): JsonResponse
    {
        Gate::authorize('simulador.usar');

        $empleado = Empleado::find($cedula);

        if ($empleado === null) {
            return response()->json(['mensaje' => "No existe un empleado con la cédula {$cedula}."], 404);
        }

        if ($empleado->estado !== 'Activo') {
            return response()->json(['mensaje' => 'El empleado está retirado y no puede solicitar un crédito.'], 422);
        }

        return response()->json($this->simuladorCreditoService->evaluarModalidad($empleado, $tipo_prestamo));
    }

    /**
     * Tasa oficial (base y puntos) con la que se prellena el formulario para
     * una modalidad y un plazo.
     */
    public function tasa(Request $request): JsonResponse
    {
        Gate::authorize('simulador.usar');

        $datos = $request->validate([
            'cedula' => ['required', 'string', 'regex:/^[0-9]{1,10}$/'],
            'id_tipo' => ['required', 'integer', 'exists:tipos_prestamo,id_tipo'],
            'plazo' => ['nullable', 'integer', 'min:1'],
        ]);

        $empleado = Empleado::find($datos['cedula']);

        if ($empleado === null) {
            return response()->json(['mensaje' => "No existe un empleado con la cédula {$datos['cedula']}."], 404);
        }

        $modalidad = TipoPrestamo::findOrFail($datos['id_tipo']);
        $oficial = $this->simuladorCreditoService->tasaOficial($empleado, $modalidad, now()->year, (int) ($datos['plazo'] ?? 0));

        return response()->json($oficial + [
            'total' => $oficial['base'] === null ? null : round($oficial['base'] + $oficial['puntos'], 4),
            'plazo_maximo' => $this->simuladorCreditoService->plazoMaximoSimulable($modalidad),
        ]);
    }

    /**
     * Calcula la simulación. No guarda nada: ni solicitud, ni préstamo, ni
     * auditoría. La respuesta trae los datos y, ya armado, el HTML del resultado.
     */
    public function calcular(SimularCreditoRequest $request): JsonResponse
    {
        $datos = $request->validated();
        $empleado = Empleado::find($datos['cedula']);

        if ($empleado === null) {
            return response()->json(['mensaje' => "No existe un empleado con la cédula {$datos['cedula']}."], 404);
        }

        if ($empleado->estado !== 'Activo') {
            return response()->json(['mensaje' => 'El empleado está retirado y no puede solicitar un crédito.', 'errores' => ['El empleado está retirado y no puede solicitar un crédito.']], 422);
        }

        $resultado = $this->simuladorCreditoService->simular(
            $empleado,
            TipoPrestamo::findOrFail($datos['id_tipo']),
            (float) $datos['monto'],
            (int) $datos['plazo'],
            isset($datos['tasa_efectiva']) ? (float) $datos['tasa_efectiva'] : null,
            isset($datos['puntos']) ? (float) $datos['puntos'] : null,
            (float) ($datos['retencion'] ?? 0),
            fechaDesembolso: $datos['fecha_desembolso'] ?? null,
        );

        if ($resultado['errores'] !== []) {
            return response()->json(['mensaje' => $resultado['errores'][0], 'errores' => $resultado['errores']], 422);
        }

        $enlaceSolicitud = route('solicitudes.create', [
            'cedula_empleado' => $empleado->cedula,
            'id_tipo' => $datos['id_tipo'],
            'monto_solicitado' => $datos['monto'],
            'plazo_solicitado' => $datos['plazo'],
        ]);

        $datosHoja = [
            'empleado' => $empleado,
            'usuario' => $request->user(),
            'generadaEn' => CarbonImmutable::now(CalendarioQuincenalService::ZONA),
        ];

        return response()->json([
            ...Arr::except($resultado, 'tabla_amortizacion'),
            'html' => view('simulador.partials.resultado', $resultado + ['enlaceSolicitud' => $enlaceSolicitud])->render(),
            'html_impresion' => view('simulador.partials.hoja-impresion', $resultado + $datosHoja)->render(),
        ]);
    }
}
