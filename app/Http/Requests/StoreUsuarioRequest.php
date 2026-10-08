<?php

namespace App\Http\Requests;

use App\Enums\RolUsuario;
use App\Http\Requests\Concerns\ValidaContrasenaSegura;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUsuarioRequest extends FormRequest
{
    use ValidaContrasenaSegura;

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
            'cedula' => ['required', 'string', 'max:20', 'exists:empleados,cedula', 'unique:usuarios,cedula'],
            'password' => $this->reglasContrasena(),
            'rol' => ['required', Rule::enum(RolUsuario::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->mensajesContrasena();
    }
}
