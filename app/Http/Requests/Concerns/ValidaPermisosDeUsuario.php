<?php

namespace App\Http\Requests\Concerns;

use App\Enums\RolUsuario;
use App\Services\PermisosUsuarioService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

/**
 * Las casillas de permisos del formulario de usuarios. El formulario manda
 * `permisos_enviados=1` junto con las casillas marcadas: así se distingue «no se
 * marcó ninguna» de «el formulario no incluía casillas» (que conserva o aplica los
 * permisos del perfil).
 */
trait ValidaPermisosDeUsuario
{
    /**
     * @return array<string, mixed>
     */
    protected function reglasDePermisos(): array
    {
        return [
            'permisos' => ['nullable', 'array'],
            'permisos.*' => ['string', Rule::in(array_keys(config('permisos.catalogo')))],
            'permisos_enviados' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function mensajesDePermisos(): array
    {
        return [
            'permisos.array' => 'Los permisos no tienen un formato válido.',
            'permisos.*.in' => 'Hay un permiso que no existe en el catálogo.',
        ];
    }

    /**
     * Los permisos exclusivos del Administrador solo se aceptan si el rol lo es.
     */
    public function withValidator(Validator $validador): void
    {
        $validador->after(function (Validator $validador): void {
            if ($validador->errors()->isNotEmpty() || ! $this->boolean('permisos_enviados')) {
                return;
            }

            if (RolUsuario::tryFrom((string) $this->input('rol')) === RolUsuario::Administrador) {
                return;
            }

            $exclusivos = app(PermisosUsuarioService::class)->exclusivosDeAdministradorEn((array) $this->input('permisos', []));

            if ($exclusivos !== []) {
                $validador->errors()->add('permisos', 'Solo una cuenta con rol Administrador puede tener: '.implode(', ', $exclusivos).'.');
            }
        });
    }

    /**
     * Las casillas marcadas, o null si el formulario no las incluía.
     *
     * @return list<string>|null
     */
    public function permisosSolicitados(): ?array
    {
        if (! $this->boolean('permisos_enviados')) {
            return null;
        }

        return array_values(array_map('strval', (array) $this->validated('permisos', [])));
    }
}
