<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegistrarAbonoExtraordinarioRequest extends FormRequest
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
            'monto' => ['required', 'numeric', 'min:0.01'],
            'fecha_pago' => ['required', 'date'],
        ];
    }
}
