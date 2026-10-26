<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizaTasas;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTasaIntRequest extends FormRequest
{
    use NormalizaTasas;

    /**
     * Los errores de la edición van en su propio paquete para no mezclarse con los del
     * formulario de registro, que usa los mismos nombres de campo.
     */
    protected $errorBag = 'edicionTasa';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizarTasas(['tasa_anual']);
    }

    /**
     * Solo se modifica la tasa: el año y la modalidad no cambian.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tasa_anual' => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
