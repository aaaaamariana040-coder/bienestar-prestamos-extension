<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RechazarSolicitudCreditoRequest extends FormRequest
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
            'motivo_rechazo' => ['required', 'string', 'max:1000'],
        ];
    }
}
