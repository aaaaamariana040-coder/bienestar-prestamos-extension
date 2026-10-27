<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizaTasas;
use Illuminate\Foundation\Http\FormRequest;

class StoreDtfRequest extends FormRequest
{
    use NormalizaTasas;

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
            'fecha_vigencia' => ['required', 'date_format:Y-m-d', 'unique:tasas_dtf,fecha_vigencia'],
            'dtf_nominal' => ['required', 'numeric', 'min:0', 'lt:100', 'decimal:0,4'],
            'fuente_normativa' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'fecha_vigencia.required' => 'Escribe la fecha desde la que aplica el DTF.',
            'fecha_vigencia.date_format' => 'La fecha de vigencia no es una fecha válida.',
            'fecha_vigencia.unique' => 'Ya hay un DTF registrado con esa fecha de vigencia. Para cambiarlo, edita su fila de la tabla.',
            'dtf_nominal.required' => 'Escribe el DTF nominal trimestre anticipado.',
            'dtf_nominal.numeric' => 'El DTF nominal debe ser un número (por ejemplo 9,81).',
            'dtf_nominal.min' => 'El DTF nominal no puede ser negativo.',
            'dtf_nominal.lt' => 'El DTF nominal debe ser menor que 100 %.',
            'dtf_nominal.decimal' => 'El DTF nominal puede tener hasta 4 decimales.',
            'fuente_normativa.max' => 'La fuente u observación no puede pasar de 100 caracteres.',
        ];
    }
}
