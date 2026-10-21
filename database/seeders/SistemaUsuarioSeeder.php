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
     * aleatoria que nadie conoce, para que no pueda iniciar sesión. Se llama
     * «Sistema» (sin apellido). Es idempotente: si ya existe no cambia su
     * contraseña, solo se asegura de que siga deshabilitado y con ese nombre.
     */
    public function run(): void
    {
        $sistema = Usuario::firstOrCreate(
            ['cedula' => Usuario::CEDULA_SISTEMA],
            [
                'nombre' => 'Sistema',
                'apellido' => '',
                'contrasena_hash' => Str::random(64),
                'rol' => RolUsuario::FuncionarioBienestar,
                'permisos' => [],
                'habilitado' => false,
            ],
        );

        $sistema->fill(['nombre' => 'Sistema', 'apellido' => '', 'habilitado' => false]);

        if ($sistema->isDirty()) {
            $sistema->save();
        }
    }
}
