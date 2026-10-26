<?php

namespace App\Http\Controllers;

use App\Enums\EstadoSolicitud;
use App\Http\Requests\RechazarSolicitudCreditoRequest;
use App\Http\Requests\StoreSolicitudCreditoRequest;
use App\Models\Empleado;
use App\Models\SolicitudCredito;
use App\Models\TipoDocumento;
use App\Models\TipoPrestamo;
use App\Services\CapacidadEndeudamientoService;
use App\Services\DocumentoSolicitudService;
use App\Services\PrestamoService;
use App\Services\SolicitudCreditoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class SolicitudCreditoController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', SolicitudCredito::class);

        $cedula = substr(preg_replace('/\D+/', '', (string) $request->query('cedula', '')), 0, 10);

        $solicitudes = SolicitudCredito::with(['empleado', 'tipoPrestamo', 'ultimoEnvio'])
            ->when($cedula !== '', fn ($consulta) => $consulta->where('cedula_empleado', 'like', $cedula.'%'))
            ->orderByDesc('fecha_solicitud')
            ->get();

        return view('solicitudes.index', compact('solicitudes', 'cedula'));
    }

    /**
     * Acepta valores previos por GET (cédula, modalidad, monto y plazo), como
     * los que manda el simulador; solo prellenan el formulario, no registran nada.
     */
    public function create(Request $request): View
    {
        $this->authorize('create', SolicitudCredito::class);

        $tiposPrestamo = TipoPrestamo::where('habilitada', true)->orderBy('nombre')->get();
        $tiposDocumento = TipoDocumento::where('activo', true)->orderBy('nombre')->get();
        $previos = [
            'cedula_empleado' => substr(preg_replace('/\D+/', '', (string) $request->query('cedula_empleado', '')), 0, 10),
            'id_tipo' => (string) $request->integer('id_tipo'),
            'monto_solicitado' => is_numeric($request->query('monto_solicitado')) ? (string) $request->query('monto_solicitado') : '',
            'plazo_solicitado' => (string) $request->integer('plazo_solicitado'),
        ];

        return view('solicitudes.create', compact('tiposPrestamo', 'tiposDocumento', 'previos'));
    }

    /**
     * Búsqueda por cédula para el formulario de solicitud. Solo devuelve
     * empleados Activos; un empleado retirado no puede solicitar crédito.
     */
    public function buscarEmpleado(string $cedula, CapacidadEndeudamientoService $capacidadService): JsonResponse
    {
        $this->authorize('create', SolicitudCredito::class);

        $empleado = Empleado::find($cedula);

        if ($empleado === null) {
            return response()->json([
                'encontrado' => false,
                'mensaje' => "No existe un empleado con la cédula {$cedula}.",
            ], 404);
        }

        if ($empleado->estado !== 'Activo') {
            return response()->json([
                'encontrado' => false,
                'inactivo' => true,
                'mensaje' => "El empleado con la cédula {$cedula} no está activo y no puede solicitar un crédito.",
            ], 404);
        }

        $capacidad = $capacidadService->paraEmpleado($empleado);

        return response()->json([
            'encontrado' => true,
            'cedula' => $empleado->cedula,
            'nombre_completo' => $empleado->nombre_completo,
            'dependencia' => $empleado->dependencia,
            'salario' => number_format((float) $empleado->salario, 2),
            'capacidad_endeudamiento' => number_format((float) $empleado->capacidad_endeudamiento, 2),
            'capacidad_fuente' => $capacidad['fuente'],
            'capacidad_aplicada' => $capacidad['calculable'] ? number_format($capacidad['total'], 2) : null,
        ]);
    }

    public function store(StoreSolicitudCreditoRequest $request, SolicitudCreditoService $solicitudCreditoService, DocumentoSolicitudService $documentoSolicitudService): RedirectResponse
    {
        $this->authorize('create', SolicitudCredito::class);

        $solicitud = $solicitudCreditoService->crear($request->safe()->except(['archivos', 'id_tipo_documento']), $request->user());

        if ($request->hasFile('archivos')) {
            $documentoSolicitudService->subir(
                $solicitud,
                TipoDocumento::findOrFail($request->validated('id_tipo_documento')),
                $request->file('archivos'),
                $request->user(),
            );
        }

        return redirect()->route('solicitudes.show', $solicitud)->with('status', 'Solicitud registrada correctamente.');
    }

    public function show(SolicitudCredito $solicitud, SolicitudCreditoService $solicitudCreditoService, PrestamoService $prestamoService): View
    {
        $this->authorize('view', $solicitud);

        $solicitud->loadMissing(['empleado', 'tipoPrestamo', 'prestamo.tasaInt', 'prestamo.tipoPrestamo', 'envios.usuario']);
        $documentos = $solicitud->documentos()->with(['tipoDocumento', 'subidoPor'])->orderBy('created_at')->get();
        $tiposDocumento = TipoDocumento::where('activo', true)->orderBy('nombre')->get();

        $problemas = $solicitud->estado === EstadoSolicitud::Radicada
            ? $solicitudCreditoService->validar($solicitud)
            : [];

        $tasa = $prestamoService->tasaDeSolicitud($solicitud);

        return view('solicitudes.show', compact('solicitud', 'problemas', 'documentos', 'tiposDocumento', 'tasa'));
    }

    public function aprobar(Request $request, SolicitudCredito $solicitud, SolicitudCreditoService $solicitudCreditoService): RedirectResponse
    {
        $this->authorize('aprobar', $solicitud);

        try {
            $solicitudCreditoService->aprobar($solicitud, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('solicitudes.show', $solicitud)->with('status', 'Solicitud aprobada: se generó el préstamo y su tabla de amortización.');
    }

    public function rechazar(RechazarSolicitudCreditoRequest $request, SolicitudCredito $solicitud, SolicitudCreditoService $solicitudCreditoService): RedirectResponse
    {
        $this->authorize('rechazar', $solicitud);

        $solicitudCreditoService->rechazar($solicitud, $request->validated('motivo_rechazo'), $request->user());

        return redirect()->route('solicitudes.show', $solicitud)->with('status', 'Solicitud rechazada.');
    }
}
