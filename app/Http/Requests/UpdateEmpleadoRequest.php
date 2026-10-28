<?php

namespace App\Http\Requests;

use App\Enums\TipoVinculacion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmpleadoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['de_carrera' => $this->boolean('de_carrera')]);
    }

    /**
     * "Retirado" solo se puede conservar aquí, no asignar: el retiro se
     * registra únicamente por empleados.retiro, que genera las
     * reliquidaciones correspondientes (Art. 40).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $estadosPermitidos = $this->route('empleado')->estado === 'Retirado' ? ['Activo', 'Retirado'] : ['Activo'];

        return [
            'nombre_completo' => ['required', 'string', 'max:150', "regex:/^[\p{L}\s'’.\-]+$/u"],
            'tipo_vinculacion' => ['required', Rule::enum(TipoVinculacion::class)],
            'de_carrera' => ['boolean'],
            'cargo' => ['nullable', 'string', 'max:100'],
            'dependencia' => ['nullable', 'string', 'max:100'],
            'salario' => ['required', 'numeric', 'min:0'],
            'fecha_vinculacion' => ['nullable', 'date'],
            'estado' => ['required', Rule::in($estadosPermitidos)],
            'capacidad_endeudamiento' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nombre_completo.regex' => 'El nombre solo puede tener letras (con tildes y ñ), espacios, apóstrofos, guiones y puntos; no admite números.',
            'estado.in' => 'El retiro de un empleado solo se puede registrar mediante el flujo de retiro, no editando el estado.',
        ];
    }
}
