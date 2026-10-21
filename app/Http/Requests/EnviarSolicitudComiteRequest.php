<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class EnviarSolicitudComiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('solicitudes.enviar-comite');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'documentos' => ['nullable', 'array'],
            'documentos.*' => ['integer'],
            'confirmar_reenvio' => ['nullable', 'boolean'],
        ];
    }
}
