<?php

namespace App\Http\Controllers;

use App\Enums\EstadoSolicitud;
use App\Http\Requests\EnviarSolicitudComiteRequest;
use App\Models\SolicitudCredito;
use App\Services\EnvioComiteException;
use App\Services\EnvioComiteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class EnvioComiteController extends Controller
{
    public function __construct(private readonly EnvioComiteService $envioComiteService)
    {
        //
    }

    /**
     * Vista previa del correo: no envía ni guarda nada. Sin `actualizar` se
     * incluyen todos los documentos; con él, solo los marcados.
     */
    public function previa(Request $request, SolicitudCredito $solicitud): View|RedirectResponse
    {
        Gate::authorize('solicitudes.enviar-comite');

        if ($solicitud->estado !== EstadoSolicitud::Radicada) {
            return redirect()->route('solicitudes.show', $solicitud)
                ->with('error', "Solo se puede enviar al comité una solicitud Radicada; esta está {$solicitud->estado->value}.");
        }

        $ids = $request->boolean('actualizar') ? array_map('intval', (array) $request->query('documentos', [])) : null;
        $previa = $this->envioComiteService->vistaPrevia($solicitud, $ids);

        return view('solicitudes.comite.previa', ['solicitud' => $solicitud->loadMissing(['empleado', 'tipoPrestamo']), 'previa' => $previa]);
    }

    public function enviar(EnviarSolicitudComiteRequest $request, SolicitudCredito $solicitud): RedirectResponse
    {
        try {
            $envio = $this->envioComiteService->enviar(
                $solicitud,
                $request->user(),
                array_map('intval', $request->validated('documentos') ?? []),
                $request->boolean('confirmar_reenvio'),
            );
        } catch (EnvioComiteException $e) {
            return redirect()->route('solicitudes.comite.previa', $solicitud)->with('error', $e->getMessage());
        }

        return redirect()->route('solicitudes.show', $solicitud)->with('status', $envio->simulado
            ? 'Envío simulado (no se envió): el correo quedó registrado en el log y en el historial de envíos.'
            : 'Solicitud enviada al comité correctamente.');
    }
}
