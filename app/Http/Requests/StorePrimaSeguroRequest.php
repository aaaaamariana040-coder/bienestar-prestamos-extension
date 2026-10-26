<?php

namespace App\Http\Requests;

use App\Enums\ConceptoSeguro;
use App\Http\Requests\Concerns\NormalizaTasas;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePrimaSeguroRequest extends FormRequest
{
    use NormalizaTasas;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizarTasas(['tasa']);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'concepto' => ['required', Rule::enum(ConceptoSeguro::class)],
            'anio' => [
                'required', 'integer', 'digits:4',
                Rule::unique('primas_seguro', 'anio')->where('concepto', $this->input('concepto')),
            ],
            'tasa' => ['required', 'numeric', 'min:0', 'max:1', 'decimal:0,8'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'concepto.required' => 'Elige el concepto del seguro.',
            'concepto.enum' => 'El concepto del seguro no es válido.',
            'anio.unique' => 'Ya hay una prima registrada para ese concepto y año. Para cambiarla, usa «Guardar» en su fila de la tabla.',
            'tasa.decimal' => 'La tasa puede tener hasta 8 decimales.',
            'tasa.max' => 'La tasa es una fracción del monto: no puede ser mayor que 1.',
        ];
    }
}
