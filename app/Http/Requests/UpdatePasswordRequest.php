<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidaContrasenaSegura;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePasswordRequest extends FormRequest
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
            'current_password' => ['required', 'current_password'],
            'password' => $this->reglasContrasena(),
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
