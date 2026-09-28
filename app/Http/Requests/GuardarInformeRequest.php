<?php

namespace App\Http\Requests;

use App\Enums\EstadoPrestamo;
use App\Enums\TipoInforme;
use App\Models\InformeGuardado;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardarInformeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', InformeGuardado::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tipo' => ['required', Rule::enum(TipoInforme::class)],
            'id_tipo' => ['nullable', 'integer', 'exists:tipos_prestamo,id_tipo'],
            'estado' => ['nullable', Rule::enum(EstadoPrestamo::class)],
            'desde' => ['nullable', 'date'],
            'hasta' => array_merge(['nullable', 'date'], $this->filled('desde') ? ['after_or_equal:desde'] : []),
            'anio' => ['nullable', 'integer', 'between:2000,2100'],
        ];
    }
}
