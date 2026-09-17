<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegistrarAbonoCesantiasRequest extends FormRequest
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
            'anio' => ['required', 'integer', 'digits:4'],
            'monto' => ['required', 'numeric', 'min:0.01'],
            'fecha_abono' => ['required', 'date'],
            'modo_reliquidacion' => ['nullable', 'in:cuota,plazo'],
        ];
    }
}
