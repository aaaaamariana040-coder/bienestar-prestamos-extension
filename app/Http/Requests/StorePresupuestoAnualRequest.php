<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePresupuestoAnualRequest extends FormRequest
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
            'id_tipo' => [
                'required', 'integer', 'exists:tipos_prestamo,id_tipo',
                Rule::unique('presupuestos_anuales', 'id_tipo')->where(fn ($query) => $query->where('anio', $this->input('anio'))),
            ],
            'monto_asignado' => ['required', 'numeric', 'min:0'],
        ];
    }
}
