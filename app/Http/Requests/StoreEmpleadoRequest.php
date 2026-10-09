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

    protected function prepareForValidation(): void
    {
        if (is_string($this->cedula)) {
            $this->merge(['cedula' => preg_replace('/\s+/', '', $this->cedula)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cedula' => ['required', 'regex:/^\d{1,10}$/', 'unique:empleados,cedula'],
            'nombre_completo' => ['required', 'string', 'max:150'],
            'tipo_vinculacion' => ['required', Rule::in(['Empleado', 'Docente'])],
            'cargo' => ['nullable', 'string', 'max:100'],
            'dependencia' => ['nullable', 'string', 'max:100'],
            'salario' => ['required', 'numeric', 'min:0'],
            'fecha_vinculacion' => ['nullable', 'date'],
            'estado' => ['required', Rule::in(['Activo'])],
            'capacidad_endeudamiento' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cedula.required' => 'La cédula es obligatoria.',
            'cedula.regex' => 'La cédula debe contener solo números, máximo 10 dígitos, sin puntos ni caracteres especiales.',
            'cedula.unique' => 'Ya existe un empleado con esa cédula.',
            'estado.in' => 'Un empleado nuevo solo puede crearse con estado Activo.',
        ];
    }
}
