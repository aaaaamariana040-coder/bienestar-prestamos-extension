<?php

namespace Database\Seeders;

use App\Models\Usuario;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        Usuario::factory()->administrador()->create([
            'cedula' => '1000000000',
            'nombre' => 'Administrador',
            'apellido' => 'Bienestar',
        ]);

        $this->call([
            TasaVehiculoSeeder::class,
            TipoPrestamoSeeder::class,
            TipoDocumentoSeeder::class,
            SistemaUsuarioSeeder::class,
        ]);
    }
}
