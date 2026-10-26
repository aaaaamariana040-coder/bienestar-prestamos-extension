<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizaTasas;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePrimaSeguroRequest extends FormRequest
{
    use NormalizaTasas;

    /**
     * Los errores de la edición van en su propio paquete para no mezclarse con los del
     * formulario de registro, que usa el mismo nombre de campo.
     */
    protected $errorBag = 'edicionPrima';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizarTasas(['tasa']);
    }

    /**
     * Solo se modifica la tasa: el concepto y el año no cambian.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tasa' => ['required', 'numeric', 'min:0', 'max:1', 'decimal:0,8'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tasa.decimal' => 'La tasa puede tener hasta 8 decimales.',
            'tasa.max' => 'La tasa es una fracción del monto: no puede ser mayor que 1.',
        ];
    }
}
