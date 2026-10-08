<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTipoPrestamoRequest extends FormRequest
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
            'nombre' => ['required', 'string', 'max:100', 'unique:tipos_prestamo,nombre'],
            'descripcion' => ['nullable', 'string'],
            'plazo_maximo' => ['required', 'integer', 'min:1'],
            'fuente_normativa' => ['nullable', 'string', 'max:100'],
        ];
    }
}
