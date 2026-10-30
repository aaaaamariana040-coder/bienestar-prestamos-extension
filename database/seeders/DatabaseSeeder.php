<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Carga solo los catálogos y el usuario «Sistema». NO crea ningún administrador ni usuario con
     * contraseña conocida, en ningún entorno: el administrador principal se crea únicamente con
     * `php artisan app:preparar-instalacion`, que pide la contraseña por consola.
     */
    public function run(): void
    {
        $this->call([
            TasaVehiculoSeeder::class,
            TipoPrestamoSeeder::class,
            TipoDocumentoSeeder::class,
            SistemaUsuarioSeeder::class,
        ]);
    }
}
