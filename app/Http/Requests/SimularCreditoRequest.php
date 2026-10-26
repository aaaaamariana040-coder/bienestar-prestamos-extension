<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizaMontos;
use App\Rules\MontoColombiano;
use App\Services\SimuladorCreditoService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class SimularCreditoRequest extends FormRequest
{
    use NormalizaMontos;

    public function authorize(): bool
    {
        return Gate::allows('simulador.usar');
    }

    protected function prepareForValidation(): void
    {
        $this->normalizarMontos(['monto', 'retencion']);

        $tasas = [];

        foreach (['tasa_efectiva', 'puntos'] as $campo) {
            $valor = $this->input($campo);

            if (is_string($valor)) {
                $tasas[$campo] = str_replace(',', '.', trim($valor));
            }
        }

        $this->merge($tasas);
    }

    /**
     * El tope del plazo depende de la modalidad y lo valida el servicio.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cedula' => ['required', 'string', 'regex:/^[0-9]{1,10}$/'],
            'id_tipo' => ['required', 'integer', 'exists:tipos_prestamo,id_tipo'],
            'monto' => ['bail', 'required', new MontoColombiano, 'numeric', 'min:'.SimuladorCreditoService::MONTO_MINIMO_SOLICITUD],
            'plazo' => ['required', 'integer', 'min:1'],
            'tasa_efectiva' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'puntos' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'retencion' => ['bail', 'nullable', new MontoColombiano, 'numeric', 'min:0'],
            'fecha_desembolso' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'fecha_desembolso.date_format' => 'La fecha estimada de desembolso no es una fecha válida: usa el formato AAAA-MM-DD (por ejemplo 2026-03-03).',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'cedula' => 'cédula',
            'id_tipo' => 'modalidad',
            'tasa_efectiva' => 'tasa efectiva anual',
            'puntos' => 'puntos adicionales',
            'retencion' => 'valor de la retención en la fuente',
            'fecha_desembolso' => 'fecha estimada de desembolso',
        ];
    }
}
