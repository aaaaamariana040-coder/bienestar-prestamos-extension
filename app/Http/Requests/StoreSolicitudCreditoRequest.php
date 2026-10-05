<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizaMontos;
use Illuminate\Foundation\Http\FormRequest;

class StoreSolicitudCreditoRequest extends FormRequest
{
    use NormalizaMontos;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizarMontos(['monto_solicitado']);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cedula_empleado' => ['required', 'string', 'exists:empleados,cedula'],
            'id_tipo' => ['required', 'integer', 'exists:tipos_prestamo,id_tipo'],
            'monto_solicitado' => ['required', 'numeric', 'min:1000'],
            'plazo_solicitado' => ['required', 'integer', 'min:1'],
            'fecha_solicitud' => ['required', 'date'],
        ];
    }
}
