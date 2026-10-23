<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizaMontos;
use App\Rules\MontoColombiano;
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
            'monto' => ['bail', 'required', new MontoColombiano, 'numeric', 'min:1000'],
            'fecha_pago' => ['required', 'date'],
            'modo_reliquidacion' => ['nullable', 'in:cuota,plazo'],
            'num_cuotas' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'monto' => 'monto',
            'fecha_pago' => 'fecha de pago',
            'modo_reliquidacion' => 'opción de reliquidación',
            'num_cuotas' => 'número de cuotas',
        ];
    }
}
