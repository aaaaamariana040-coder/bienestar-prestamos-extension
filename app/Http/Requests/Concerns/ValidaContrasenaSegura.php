<?php

namespace App\Http\Requests\Concerns;

use Closure;

/**
 * Reglas de contraseña: mínimo 8 caracteres, al menos una mayúscula, una
 * minúscula y un número, más confirmación. El texto de ayuda de
 * components/reglas-password.blade.php debe reflejar exactamente estas reglas.
 */
trait ValidaContrasenaSegura
{
    /**
     * @return array<int, mixed>
     */
    protected function reglasContrasena(): array
    {
        return [
            'required',
            'string',
            'min:8',
            $this->debeContener('/\p{Lu}/u', 'La contraseña debe contener al menos una letra mayúscula.'),
            $this->debeContener('/\p{Ll}/u', 'La contraseña debe contener al menos una letra minúscula.'),
            $this->debeContener('/[0-9]/', 'La contraseña debe contener al menos un número.'),
            'confirmed',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function mensajesContrasena(): array
    {
        return [
            'password.required' => 'La contraseña es obligatoria.',
            'password.min' => 'La contraseña debe tener mínimo 8 caracteres.',
            'password.confirmed' => 'La confirmación de la contraseña no coincide.',
        ];
    }

    private function debeContener(string $patron, string $mensaje): Closure
    {
        return function (string $atributo, mixed $valor, Closure $fallar) use ($patron, $mensaje): void {
            if (is_string($valor) && ! preg_match($patron, $valor)) {
                $fallar($mensaje);
            }
        };
    }
}
