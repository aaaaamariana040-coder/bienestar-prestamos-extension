<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class ConfirmarQuincenaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `vistas` trae las cuotas que la persona vio (id_cuota => valor_total) para
     * compararlas con el estado actual; `excluir` las ids marcadas para excluir
     * y `motivo` el motivo de cada una (id_cuota => texto).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'vistas' => ['required', 'array', 'min:1'],
            'vistas.*' => ['required', 'numeric'],
            'excluir' => ['nullable', 'array'],
            'excluir.*' => ['integer'],
            'motivo' => ['nullable', 'array'],
            'motivo.*' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ((array) $this->input('excluir', []) as $idCuota) {
                if (! array_key_exists($idCuota, (array) $this->input('vistas', []))) {
                    $validator->errors()->add('excluir', "La cuota #{$idCuota} no estaba en la vista previa.");

                    continue;
                }

                if (trim((string) $this->input("motivo.{$idCuota}", '')) === '') {
                    $validator->errors()->add("motivo.{$idCuota}", "Indique el motivo de excluir la cuota #{$idCuota}.");
                }
            }
        });
    }

    /**
     * Motivos de las cuotas excluidas: id_cuota => motivo.
     *
     * @return array<int, string>
     */
    public function exclusiones(): array
    {
        $motivos = (array) $this->input('motivo', []);

        return collect((array) $this->input('excluir', []))
            ->mapWithKeys(fn ($idCuota) => [(int) $idCuota => trim((string) ($motivos[$idCuota] ?? ''))])
            ->all();
    }
}
