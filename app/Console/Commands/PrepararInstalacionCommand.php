<?php

namespace App\Console\Commands;

use App\Enums\RolUsuario;
use App\Models\Usuario;
use Database\Seeders\SistemaUsuarioSeeder;
use Database\Seeders\TasaVehiculoSeeder;
use Database\Seeders\TipoDocumentoSeeder;
use Database\Seeders\TipoPrestamoSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Carga los datos mínimos de una instalación real (docs/guia-instalacion.md): catálogos normativos,
 * usuario «Sistema» y administrador principal. Es idempotente y nunca carga datos de práctica ni
 * ficticios. La contraseña del administrador se pide por consola: el código no trae ninguna.
 */
#[Signature('app:preparar-instalacion {--cedula= : Cédula del administrador principal} {--nombre= : Nombre del administrador principal} {--apellido= : Apellido del administrador principal}')]
#[Description('Carga los datos mínimos de una instalación real (catálogos, usuario Sistema y administrador principal); es idempotente')]
class PrepararInstalacionCommand extends Command
{
    public const PREGUNTA_CONTRASENA = 'Contraseña (mínimo 8 caracteres, con mayúscula, minúscula y número)';

    public const PREGUNTA_CONFIRMACION = 'Repita la contraseña';

    public function handle(): int
    {
        $this->info('Cargando catálogos normativos (idempotente)...');

        $this->call('db:seed', ['--class' => TasaVehiculoSeeder::class, '--force' => true]);
        $this->call('db:seed', ['--class' => TipoPrestamoSeeder::class, '--force' => true]);
        $this->call('db:seed', ['--class' => TipoDocumentoSeeder::class, '--force' => true]);
        $this->call('db:seed', ['--class' => SistemaUsuarioSeeder::class, '--force' => true]);

        $principal = Usuario::where('es_principal', true)->first();

        if ($principal !== null) {
            $this->info("Ya existe un administrador principal (cédula {$principal->cedula}): no se crea otro ni se cambia su contraseña.");

            return self::SUCCESS;
        }

        return $this->crearAdministradorPrincipal();
    }

    private function crearAdministradorPrincipal(): int
    {
        $this->newLine();
        $this->line('Creación del administrador principal.');

        $datos = [
            'cedula' => preg_replace('/\s+/', '', (string) ($this->option('cedula') ?: $this->ask('Cédula (solo números, máximo 10 dígitos)'))),
            'nombre' => trim((string) ($this->option('nombre') ?: $this->ask('Nombre'))),
            'apellido' => trim((string) ($this->option('apellido') ?: $this->ask('Apellido'))),
        ];

        $validador = Validator::make($datos, [
            'cedula' => ['required', 'regex:/^\d{1,10}$/', Rule::notIn([Usuario::CEDULA_SISTEMA]), 'unique:usuarios,cedula'],
            'nombre' => ['required', 'string', 'max:80'],
            'apellido' => ['required', 'string', 'max:80'],
        ], [
            'cedula.regex' => 'La cédula debe contener solo números, máximo 10 dígitos.',
            'cedula.not_in' => 'Esa cédula está reservada para el sistema.',
            'cedula.unique' => 'Ya existe un usuario con esa cédula.',
        ]);

        if ($validador->fails()) {
            $this->error($validador->errors()->first());

            return self::FAILURE;
        }

        $contrasena = (string) $this->secret(self::PREGUNTA_CONTRASENA);
        $confirmacion = (string) $this->secret(self::PREGUNTA_CONFIRMACION);

        $errorContrasena = $this->errorDeContrasena($contrasena, $confirmacion);

        if ($errorContrasena !== null) {
            $this->error($errorContrasena);

            return self::FAILURE;
        }

        Usuario::create([
            ...$datos,
            'contrasena_hash' => $contrasena,
            'rol' => RolUsuario::Administrador,
            'habilitado' => true,
            'es_principal' => true,
        ]);

        $this->info("Administrador principal creado (cédula {$datos['cedula']}).");

        return self::SUCCESS;
    }

    /**
     * Las mismas reglas que ValidaContrasenaSegura (formularios de usuario).
     */
    private function errorDeContrasena(string $contrasena, string $confirmacion): ?string
    {
        return match (true) {
            $contrasena === '' => 'La contraseña es obligatoria.',
            mb_strlen($contrasena) < 8 => 'La contraseña debe tener mínimo 8 caracteres.',
            ! preg_match('/\p{Lu}/u', $contrasena) => 'La contraseña debe contener al menos una letra mayúscula.',
            ! preg_match('/\p{Ll}/u', $contrasena) => 'La contraseña debe contener al menos una letra minúscula.',
            ! preg_match('/[0-9]/', $contrasena) => 'La contraseña debe contener al menos un número.',
            $contrasena !== $confirmacion => 'La confirmación de la contraseña no coincide.',
            default => null,
        };
    }
}
