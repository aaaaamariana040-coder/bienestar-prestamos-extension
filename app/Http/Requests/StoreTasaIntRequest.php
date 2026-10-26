<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizaTasas;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTasaIntRequest extends FormRequest
{
    use NormalizaTasas;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizarTasas(['tasa_anual']);
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

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'anio.unique' => 'Ya hay una tasa registrada para ese año en esta modalidad. Para cambiarla, usa «Guardar» en su fila de la tabla.',
        ];
    }
}
