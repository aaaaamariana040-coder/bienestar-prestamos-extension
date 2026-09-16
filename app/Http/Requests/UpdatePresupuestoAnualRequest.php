<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePresupuestoAnualRequest extends FormRequest
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
            'monto_asignado' => ['required', 'numeric', 'min:0'],
            'monto_comprometido' => ['required', 'numeric', 'min:0', 'lte:monto_asignado'],
            'motivo' => ['required', 'string', 'max:255'],
        ];
    }
}
