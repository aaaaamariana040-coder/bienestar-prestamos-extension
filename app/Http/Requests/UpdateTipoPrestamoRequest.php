<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTipoPrestamoRequest extends FormRequest
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
            'nombre' => [
                'required', 'string', 'max:100',
                Rule::unique('tipos_prestamo', 'nombre')->ignore($this->route('tipo_prestamo')),
            ],
            'descripcion' => ['nullable', 'string'],
            'plazo_maximo' => ['required', 'integer', 'min:1'],
            'fuente_normativa' => ['nullable', 'string', 'max:100'],
        ];
    }
}
