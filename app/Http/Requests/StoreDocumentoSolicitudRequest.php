<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidaDocumentosSolicitud;
use App\Models\DocumentoSolicitud;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreDocumentoSolicitudRequest extends FormRequest
{
    use ValidaDocumentosSolicitud;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->reglasDocumentos(obligatorios: true);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->mensajesDocumentos();
    }

    /**
     * El límite de 15 cuenta también los documentos que la solicitud ya tiene.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $nuevos = count($this->file('archivos', []));
                $existentes = $this->route('solicitud')->documentos()->count();

                if ($existentes + $nuevos > DocumentoSolicitud::MAX_POR_SOLICITUD) {
                    $validator->errors()->add('archivos', 'Una solicitud admite como máximo '.DocumentoSolicitud::MAX_POR_SOLICITUD." documentos; ya tiene {$existentes} y intentas subir {$nuevos}.");
                }
            },
        ];
    }
}
