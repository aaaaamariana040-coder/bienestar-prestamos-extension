<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizaTasas;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDtfRequest extends FormRequest
{
    use NormalizaTasas;

    /**
     * Los errores de la edición van en su propio paquete para no mezclarse con los del
     * formulario de registro, que usa los mismos nombres de campo.
     */
    protected $errorBag = 'edicionDtf';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizarTasas(['dtf_nominal']);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'fecha_vigencia' => ['required', 'date_format:Y-m-d', Rule::unique('tasas_dtf', 'fecha_vigencia')->ignore($this->route('dtf')->id_tasa_dtf, 'id_tasa_dtf')],
            'dtf_nominal' => ['required', 'numeric', 'min:0', 'lt:100', 'decimal:0,4'],
            'fuente_normativa' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return (new StoreDtfRequest)->messages();
    }
}
