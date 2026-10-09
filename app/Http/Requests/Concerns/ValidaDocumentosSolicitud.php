<?php

namespace App\Http\Requests\Concerns;

use App\Models\DocumentoSolicitud;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * Reglas de los documentos que se adjuntan a una solicitud de crédito: solo
 * PDF, JPG o PNG, hasta 5 MB cada uno y 15 por solicitud. Las comparten el
 * formulario de crear solicitud y el de subir documentos.
 */
trait ValidaDocumentosSolicitud
{
    /**
     * @return array<string, mixed>
     */
    protected function reglasDocumentos(bool $obligatorios): array
    {
        return [
            'id_tipo_documento' => [
                $obligatorios ? 'required' : 'required_with:archivos',
                'nullable',
                'integer',
                Rule::exists('tipos_documento', 'id_tipo_documento')->where('activo', true),
            ],
            'archivos' => [$obligatorios ? 'required' : 'nullable', 'array', 'max:'.DocumentoSolicitud::MAX_POR_SOLICITUD],
            'archivos.*' => [$this->validarArchivo(...)],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function mensajesDocumentos(): array
    {
        return [
            'id_tipo_documento.required' => 'Elige el tipo de documento.',
            'id_tipo_documento.required_with' => 'Elige el tipo de documento de los archivos que adjuntas.',
            'id_tipo_documento.exists' => 'El tipo de documento elegido no existe o está inactivo.',
            'archivos.required' => 'Elige al menos un archivo.',
            'archivos.array' => 'Los documentos no llegaron en el formato esperado.',
            'archivos.max' => 'Puedes subir como máximo '.DocumentoSolicitud::MAX_POR_SOLICITUD.' documentos a la vez.',
        ];
    }

    protected function validarArchivo(string $atributo, mixed $archivo, Closure $fallar): void
    {
        if (! $archivo instanceof UploadedFile) {
            $fallar('Cada documento debe ser un archivo.');

            return;
        }

        $nombre = $archivo->getClientOriginalName();

        if (! $archivo->isValid()) {
            $fallar("«{$nombre}» no se pudo subir: supera el tamaño que acepta el servidor.");

            return;
        }

        if ($archivo->getSize() > DocumentoSolicitud::TAMANO_MAXIMO_BYTES) {
            $fallar("«{$nombre}» supera el tamaño máximo de 5 MB.");

            return;
        }

        $extension = strtolower($archivo->getClientOriginalExtension());

        if (! array_key_exists((string) $archivo->getMimeType(), DocumentoSolicitud::MIMES_PERMITIDOS) || ! in_array($extension, ['pdf', 'jpg', 'jpeg', 'png'], true)) {
            $fallar("«{$nombre}» no es un tipo permitido: solo se aceptan archivos PDF, JPG o PNG.");
        }
    }
}
