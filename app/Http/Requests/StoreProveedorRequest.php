<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProveedorRequest extends FormRequest
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
            'id_proveedor' => ['required', 'string', 'max:20', 'unique:proveedores,id_proveedor'],
            'nit' => ['required', 'string', 'max:20'],
            'nombre_empresa' => ['required', 'string', 'max:150'],
            'direccion' => ['nullable', 'string', 'max:200'],
            'num_cuenta_bancaria' => ['nullable', 'string', 'max:30'],
        ];
    }
}
