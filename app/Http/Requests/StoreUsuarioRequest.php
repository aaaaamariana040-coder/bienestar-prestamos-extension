<?php

namespace App\Http\Requests;

use App\Enums\RolUsuario;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUsuarioRequest extends FormRequest
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
            'cedula' => ['required', 'string', 'max:20', 'exists:empleados,cedula', 'unique:usuarios,cedula'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'rol' => ['required', Rule::enum(RolUsuario::class)],
        ];
    }
}
