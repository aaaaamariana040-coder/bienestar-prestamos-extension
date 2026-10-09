<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizaMontos;
use App\Http\Requests\Concerns\ValidaDocumentosSolicitud;
use App\Models\Empleado;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StoreSolicitudCreditoRequest extends FormRequest
{
    use NormalizaMontos;
    use ValidaDocumentosSolicitud;

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
            'cedula_empleado' => ['required', 'string', $this->empleadoExistenteYActivo()],
            'id_tipo' => ['required', 'integer', 'exists:tipos_prestamo,id_tipo'],
            'monto_solicitado' => ['required', 'numeric', 'min:1000'],
            'plazo_solicitado' => ['required', 'integer', 'min:1'],
            'fecha_solicitud' => ['required', 'date'],
            ...$this->reglasDocumentos(obligatorios: false),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->mensajesDocumentos();
    }

    /**
     * El empleado debe existir y estar Activo: un retirado no puede solicitar
     * crédito, aunque el envío se haga sin pasar por el buscador del formulario.
     */
    private function empleadoExistenteYActivo(): Closure
    {
        return function (string $atributo, mixed $cedula, Closure $fallar): void {
            $empleado = Empleado::find($cedula);

            if ($empleado === null) {
                $fallar("No existe un empleado con la cédula {$cedula}.");
            } elseif ($empleado->estado !== 'Activo') {
                $fallar("El empleado con la cédula {$cedula} no está activo y no puede solicitar un crédito.");
            }
        };
    }
}
