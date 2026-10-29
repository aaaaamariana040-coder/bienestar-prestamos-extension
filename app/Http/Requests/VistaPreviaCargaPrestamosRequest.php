<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VistaPreviaCargaPrestamosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'archivo' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'archivo.required' => 'Elija el archivo CSV con los préstamos vigentes.',
            'archivo.file' => 'El archivo no se pudo subir. Intente de nuevo.',
            'archivo.mimes' => 'El archivo debe ser un CSV (use la plantilla descargable).',
            'archivo.max' => 'El archivo no puede pesar más de 2 MB.',
        ];
    }
}
