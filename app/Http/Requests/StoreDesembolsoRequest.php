<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDesembolsoRequest extends FormRequest
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
            'numero_tramo' => ['required', 'integer', 'min:1'],
            'id_proveedor' => ['nullable', 'string', 'exists:proveedores,id_proveedor'],
            'fecha' => ['required', 'date'],
            'inspeccion_aprobada' => ['sometimes', 'boolean'],
        ];
    }
}
