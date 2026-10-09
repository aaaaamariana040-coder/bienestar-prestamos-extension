<?php

namespace App\Services;

use App\Models\DocumentoSolicitud;
use App\Models\SolicitudCredito;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentoSolicitudService
{
    private const DISCO = 'local';

    public function __construct(private readonly AuditoriaService $auditoriaService)
    {
        //
    }

    /**
     * Guarda los archivos en el disco privado con un nombre interno aleatorio
     * y deja un registro de auditoría por cada uno. Las validaciones de tipo,
     * tamaño y cantidad ya las hizo la solicitud HTTP.
     *
     * @param  array<int, UploadedFile>  $archivos
     * @return list<DocumentoSolicitud>
     */
    public function subir(SolicitudCredito $solicitud, TipoDocumento $tipoDocumento, array $archivos, Usuario $actor): array
    {
        return DB::transaction(function () use ($solicitud, $tipoDocumento, $archivos, $actor): array {
            $documentos = [];

            foreach ($archivos as $archivo) {
                $mime = (string) $archivo->getMimeType();
                $extension = DocumentoSolicitud::MIMES_PERMITIDOS[$mime];
                $ruta = $archivo->storeAs("documentos-solicitud/{$solicitud->id_solicitud}", Str::random(40).'.'.$extension, self::DISCO);

                $documento = $solicitud->documentos()->create([
                    'id_tipo_documento' => $tipoDocumento->id_tipo_documento,
                    'nombre_original' => $archivo->getClientOriginalName(),
                    'ruta' => $ruta,
                    'tamano' => $archivo->getSize(),
                    'tipo_mime' => $mime,
                    'cedula_usu' => $actor->cedula,
                ]);

                $this->auditoriaService->registrar(
                    $actor,
                    'Subida de documento de solicitud',
                    "Solicitud #{$solicitud->id_solicitud} — {$tipoDocumento->nombre}: {$documento->nombre_original} ({$documento->tamano_legible})",
                );

                $documentos[] = $documento;
            }

            return $documentos;
        });
    }

    public function eliminar(DocumentoSolicitud $documento, Usuario $actor): void
    {
        DB::transaction(function () use ($documento, $actor): void {
            $detalle = "Solicitud #{$documento->id_solicitud} — {$documento->tipoDocumento->nombre}: {$documento->nombre_original}";

            $documento->delete();

            $this->auditoriaService->registrar($actor, 'Eliminación de documento de solicitud', $detalle);
        });

        Storage::disk(self::DISCO)->delete($documento->ruta);
    }

    public function existeElArchivo(DocumentoSolicitud $documento): bool
    {
        return Storage::disk(self::DISCO)->exists($documento->ruta);
    }

    public function descargar(DocumentoSolicitud $documento): StreamedResponse
    {
        return Storage::disk(self::DISCO)->download($documento->ruta, $documento->nombre_original);
    }
}
