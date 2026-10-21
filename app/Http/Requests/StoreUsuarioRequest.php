<?php

namespace App\Http\Requests;

use App\Enums\RolUsuario;
use App\Http\Requests\Concerns\ValidaContrasenaSegura;
use App\Http\Requests\Concerns\ValidaPermisosDeUsuario;
use App\Models\Usuario;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUsuarioRequest extends FormRequest
{
    use ValidaContrasenaSegura;
    use ValidaPermisosDeUsuario;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Usuario::class) ?? false;
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
            'cedula' => ['required', 'regex:/^\d{1,10}$/', Rule::notIn([Usuario::CEDULA_SISTEMA]), 'unique:usuarios,cedula'],
            'nombre' => ['required', 'string', 'max:80'],
            'apellido' => ['required', 'string', 'max:80'],
            'password' => $this->reglasContrasena(),
            'rol' => ['required', Rule::enum(RolUsuario::class)],
            ...$this->reglasDePermisos(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...$this->mensajesContrasena(),
            'cedula.required' => 'La cédula es obligatoria.',
            'cedula.regex' => 'La cédula debe contener solo números, máximo 10 dígitos, sin puntos ni caracteres especiales.',
            'cedula.unique' => 'Ya existe un usuario con esa cédula.',
            'cedula.not_in' => 'Esa cédula está reservada para el sistema.',
            'nombre.required' => 'El nombre es obligatorio.',
            'nombre.max' => 'El nombre no puede superar los 80 caracteres.',
            'apellido.required' => 'El apellido es obligatorio.',
            'apellido.max' => 'El apellido no puede superar los 80 caracteres.',
            'rol.required' => 'El rol es obligatorio.',
            ...$this->mensajesDePermisos(),
        ];
    }
}
