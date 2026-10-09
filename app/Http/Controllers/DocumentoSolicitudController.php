<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDocumentoSolicitudRequest;
use App\Models\DocumentoSolicitud;
use App\Models\SolicitudCredito;
use App\Models\TipoDocumento;
use App\Services\DocumentoSolicitudService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentoSolicitudController extends Controller
{
    public function __construct(private readonly DocumentoSolicitudService $documentoSolicitudService)
    {
        //
    }

    public function store(StoreDocumentoSolicitudRequest $request, SolicitudCredito $solicitud): RedirectResponse
    {
        $this->authorize('view', $solicitud);

        $documentos = $this->documentoSolicitudService->subir(
            $solicitud,
            TipoDocumento::findOrFail($request->validated('id_tipo_documento')),
            $request->file('archivos'),
            $request->user(),
        );

        $mensaje = count($documentos) === 1 ? 'Documento subido correctamente.' : count($documentos).' documentos subidos correctamente.';

        return redirect()->route('solicitudes.show', $solicitud)->with('status', $mensaje);
    }

    public function download(DocumentoSolicitud $documento): StreamedResponse
    {
        $this->authorize('view', $documento->solicitud);

        abort_unless($this->documentoSolicitudService->existeElArchivo($documento), 404, 'El archivo ya no está disponible.');

        return $this->documentoSolicitudService->descargar($documento);
    }

    public function destroy(Request $request, DocumentoSolicitud $documento): RedirectResponse
    {
        $this->authorize('view', $documento->solicitud);

        $solicitud = $documento->solicitud;

        $this->documentoSolicitudService->eliminar($documento, $request->user());

        return redirect()->route('solicitudes.show', $solicitud)->with('status', 'Documento eliminado.');
    }
}
