<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmarCargaPrestamosRequest extends FormRequest
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
            'token' => ['required', 'regex:/^[A-Za-z0-9]{40}$/'],
            'nombre_archivo' => ['nullable', 'string', 'max:150'],
        ];
    }
}
