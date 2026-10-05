<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizaMontos;
use Illuminate\Foundation\Http\FormRequest;

class RegistrarAbonoCesantiasRequest extends FormRequest
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
            'anio' => ['required', 'integer', 'digits:4'],
            'monto' => ['required', 'numeric', 'min:1000'],
            'fecha_abono' => ['required', 'date'],
            'modo_reliquidacion' => ['nullable', 'in:cuota,plazo'],
        ];
    }
}
