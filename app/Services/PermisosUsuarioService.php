<?php

namespace App\Services;

use App\Enums\RolUsuario;
use App\Models\Usuario;

/**
 * Catálogo de permisos y sus reglas (config/permisos.php), sin base de datos:
 * defaults por perfil, dependencias («gestionar» implica «ver»), permisos
 * exclusivos del administrador principal y diferencias entre dos listas.
 */
class PermisosUsuarioService
{
    /**
     * @return array<string, array{grupo: string, nombre: string, descripcion: string}>
     */
    public function catalogo(): array
    {
        return config('permisos.catalogo');
    }

    /**
     * @return list<string>
     */
    public function nombres(): array
    {
        return array_keys($this->catalogo());
    }

    /**
     * El catálogo agrupado por módulo, en el orden de la configuración.
     *
     * @return array<string, array<string, array{grupo: string, nombre: string, descripcion: string}>>
     */
    public function agrupado(): array
    {
        $grupos = [];

        foreach ($this->catalogo() as $permiso => $datos) {
            $grupos[$datos['grupo']][$permiso] = $datos;
        }

        return $grupos;
    }

    /**
     * @return list<string>
     */
    public function soloAdministrador(): array
    {
        return config('permisos.solo_administrador');
    }

    /**
     * Permisos por defecto de un perfil, ya sin los exclusivos si el perfil no es
     * Administrador (ni la cuenta es la principal, con $principal en false) y con las
     * dependencias resueltas.
     *
     * @return list<string>
     */
    public function defaultsPara(RolUsuario|string $rol, bool $principal = true): array
    {
        $clave = $rol instanceof RolUsuario ? $rol->value : $rol;
        $permisos = [];

        foreach ((array) config("permisos.defaults.{$clave}", []) as $regla) {
            if ($regla === '*') {
                $permisos = $this->nombres();
            } elseif (str_starts_with($regla, '!')) {
                $permisos = array_values(array_diff($permisos, [substr($regla, 1)]));
            } else {
                $permisos[] = $regla;
            }
        }

        if ($clave !== RolUsuario::Administrador->value || ! $principal) {
            $permisos = array_values(array_diff($permisos, $this->soloAdministrador()));
        }

        return $this->normalizar($permisos);
    }

    /**
     * Deja solo permisos del catálogo, agrega los que exigen las dependencias, sin
     * repetidos y en el orden del catálogo.
     *
     * @param  array<int, mixed>  $permisos
     * @return list<string>
     */
    public function normalizar(array $permisos): array
    {
        $resultado = array_values(array_intersect($this->nombres(), array_map('strval', $permisos)));
        $dependencias = config('permisos.dependencias');

        for ($i = 0; $i < count($resultado); $i++) {
            foreach ($dependencias[$resultado[$i]] ?? [] as $exigido) {
                if (! in_array($exigido, $resultado, true)) {
                    $resultado[] = $exigido;
                }
            }
        }

        return array_values(array_intersect($this->nombres(), $resultado));
    }

    /**
     * Los permisos de la lista que solo tiene el administrador principal.
     *
     * @param  array<int, mixed>  $permisos
     * @return list<string>
     */
    public function exclusivosDeAdministradorEn(array $permisos): array
    {
        return array_values(array_intersect($this->soloAdministrador(), array_map('strval', $permisos)));
    }

    /**
     * ¿Lo asignado a la cuenta es distinto de lo que le correspondería a su perfil?
     */
    public function esPersonalizado(Usuario $usuario): bool
    {
        return $this->normalizar($usuario->permisosAsignados()) !== $this->defaultsPara($usuario->rol, $usuario->esPrincipal());
    }

    /**
     * @param  array<int, string>  $antes
     * @param  array<int, string>  $despues
     * @return array{agregados: list<string>, quitados: list<string>}
     */
    public function diferencia(array $antes, array $despues): array
    {
        return [
            'agregados' => array_values(array_diff($despues, $antes)),
            'quitados' => array_values(array_diff($antes, $despues)),
        ];
    }

    /**
     * Para el formulario: por cada permiso, los que lo exigen (si se quita este, se
     * quitan también ellos).
     *
     * @return array<string, list<string>>
     */
    public function dependientes(): array
    {
        $dependientes = [];

        foreach (config('permisos.dependencias') as $permiso => $exigidos) {
            foreach ($exigidos as $exigido) {
                $dependientes[$exigido][] = $permiso;
            }
        }

        return $dependientes;
    }
}
