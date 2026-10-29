<?php

namespace App\Http\Requests;

use App\Enums\TipoVinculacion;
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
            $this->merge(['cedula' => self::normalizarCedula($this->cedula)]);
        }

        $this->merge(['de_carrera' => $this->boolean('de_carrera')]);
    }

    /**
     * La cédula sin espacios; también la usa la carga masiva de empleados.
     */
    public static function normalizarCedula(string $cedula): string
    {
        return preg_replace('/\s+/', '', $cedula) ?? $cedula;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cedula' => ['required', 'regex:/^\d{1,10}$/', 'unique:empleados,cedula'],
            'nombre_completo' => ['required', 'string', 'max:150', "regex:/^[\p{L}\s'’.\-]+$/u"],
            'tipo_vinculacion' => ['required', Rule::enum(TipoVinculacion::class)],
            'de_carrera' => ['boolean'],
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
            'nombre_completo.regex' => 'El nombre solo puede tener letras (con tildes y ñ), espacios, apóstrofos, guiones y puntos; no admite números.',
            'cedula.unique' => 'Ya existe un empleado con esa cédula.',
            'estado.in' => 'Un empleado nuevo solo puede crearse con estado Activo.',
        ];
    }
}
