<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizaMontos;
use Illuminate\Foundation\Http\FormRequest;

class VistaPreviaAbonoRequest extends FormRequest
{
    use NormalizaMontos;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizarMontos(['monto']);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'monto' => ['required', 'numeric', 'min:0.01'],
            'modo_reliquidacion' => ['nullable', 'in:cuota,plazo'],
            'num_cuotas' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
