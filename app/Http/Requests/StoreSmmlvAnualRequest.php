<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSmmlvAnualRequest extends FormRequest
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
            'anio' => ['required', 'integer', 'digits:4', 'unique:smmlv_anual,anio'],
            'valor' => ['required', 'numeric', 'min:0'],
            'fuente_normativa' => ['nullable', 'string', 'max:100'],
        ];
    }
}
