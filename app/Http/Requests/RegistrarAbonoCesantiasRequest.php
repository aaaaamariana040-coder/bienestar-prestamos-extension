<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizaMontos;
use App\Rules\MontoColombiano;
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
            'monto' => ['bail', 'required', new MontoColombiano, 'numeric', 'min:1000'],
            'fecha_abono' => ['required', 'date'],
            'modo_reliquidacion' => ['nullable', 'in:cuota,plazo'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'anio' => 'año',
            'monto' => 'monto',
            'fecha_abono' => 'fecha del abono',
            'modo_reliquidacion' => 'opción de reliquidación',
        ];
    }
}
