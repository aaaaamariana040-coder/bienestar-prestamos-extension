<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizaMontos;
use Illuminate\Foundation\Http\FormRequest;

class StoreSmmlvAnualRequest extends FormRequest
{
    use NormalizaMontos;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizarMontos(['valor']);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'anio' => ['required', 'integer', 'digits:4', 'unique:smmlv_anual,anio'],
            'valor' => ['required', 'numeric', 'min:1000'],
            'fuente_normativa' => ['nullable', 'string', 'max:100'],
        ];
    }
}
