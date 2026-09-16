<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCuantiaSmmlvRequest extends FormRequest
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
            'tramo_desde_smmlv' => ['required', 'numeric', 'min:0'],
            'tramo_hasta_smmlv' => ['nullable', 'numeric', 'gt:tramo_desde_smmlv'],
            'cuantia_maxima_smmlv' => ['required', 'numeric', 'min:0'],
        ];
    }
}
