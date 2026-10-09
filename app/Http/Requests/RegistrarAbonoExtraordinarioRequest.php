<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizaMontos;
use Illuminate\Foundation\Http\FormRequest;

class RegistrarAbonoExtraordinarioRequest extends FormRequest
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
            'monto' => ['required', 'numeric', 'min:1000'],
            'fecha_pago' => ['required', 'date'],
            'modo_reliquidacion' => ['nullable', 'in:cuota,plazo'],
        ];
    }
}
