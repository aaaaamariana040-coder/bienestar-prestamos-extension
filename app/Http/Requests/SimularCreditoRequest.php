<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizaMontos;
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
            'monto' => ['required', 'numeric', 'min:'.SimuladorCreditoService::MONTO_MINIMO_SOLICITUD],
            'plazo' => ['required', 'integer', 'min:1'],
            'tasa_efectiva' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'puntos' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'retencion' => ['nullable', 'numeric', 'min:0'],
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
            'retencion' => 'retención en la fuente',
        ];
    }
}
