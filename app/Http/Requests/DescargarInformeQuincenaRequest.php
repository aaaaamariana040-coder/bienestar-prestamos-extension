<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Descarga del informe desde la vista previa: lleva las exclusiones marcadas en
 * ese momento (no exige el motivo: el informe solo las refleja).
 */
class DescargarInformeQuincenaRequest extends FormRequest
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
            'excluir' => ['nullable', 'array'],
            'excluir.*' => ['integer'],
            'motivo' => ['nullable', 'array'],
            'motivo.*' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * id_cuota => motivo de las cuotas marcadas como excluidas.
     *
     * @return array<int, string>
     */
    public function exclusiones(): array
    {
        $motivos = (array) $this->input('motivo', []);
        $exclusiones = [];

        foreach ((array) $this->input('excluir', []) as $id) {
            $exclusiones[(int) $id] = trim((string) ($motivos[$id] ?? ''));
        }

        return $exclusiones;
    }
}
