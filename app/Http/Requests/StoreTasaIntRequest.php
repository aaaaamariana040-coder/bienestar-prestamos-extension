<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTasaIntRequest extends FormRequest
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
            'anio' => [
                'required', 'integer', 'digits:4',
                Rule::unique('tasas_int', 'anio')->where('id_tipo', $this->route('tipo_prestamo')->id_tipo),
            ],
            'tasa_anual' => ['required', 'numeric', 'min:0', 'max:100'],
            'fuente_normativa' => ['nullable', 'string', 'max:100'],
        ];
    }
}
