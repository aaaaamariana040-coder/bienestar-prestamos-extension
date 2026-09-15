<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmpleadoRequest extends FormRequest
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
            'cedula' => ['required', 'string', 'max:20', 'unique:empleados,cedula'],
            'nombre_completo' => ['required', 'string', 'max:150'],
            'tipo_vinculacion' => ['required', Rule::in(['Empleado', 'Docente'])],
            'cargo' => ['nullable', 'string', 'max:100'],
            'dependencia' => ['nullable', 'string', 'max:100'],
            'salario' => ['required', 'numeric', 'min:0'],
            'fecha_vinculacion' => ['nullable', 'date'],
            'estado' => ['required', Rule::in(['Activo', 'Retirado'])],
            'capacidad_endeudamiento' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
