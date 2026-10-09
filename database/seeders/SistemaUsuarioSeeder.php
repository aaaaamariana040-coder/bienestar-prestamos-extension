<?php

namespace Database\Seeders;

use App\Enums\RolUsuario;
use App\Models\Usuario;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class SistemaUsuarioSeeder extends Seeder
{
    /**
     * Crea el usuario «Sistema» (cédula reservada 0000000000), a cuyo nombre
     * quedan las acciones automáticas. Está deshabilitado y con una contraseña
     * aleatoria que nadie conoce, para que no pueda iniciar sesión. Es
     * idempotente: si ya existe no cambia su contraseña, solo se asegura de que
     * siga deshabilitado.
     */
    public function run(): void
    {
        $sistema = Usuario::firstOrCreate(
            ['cedula' => Usuario::CEDULA_SISTEMA],
            [
                'nombre' => 'Sistema',
                'apellido' => 'Automático',
                'contrasena_hash' => Str::random(64),
                'rol' => RolUsuario::FuncionarioBienestar,
                'habilitado' => false,
            ],
        );

        if ($sistema->habilitado) {
            $sistema->update(['habilitado' => false]);
        }
    }
}
