<?php

namespace Database\Seeders;

use App\Models\Empleado;
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
        $empleado = Empleado::factory()->create([
            'cedula' => '1000000000',
            'nombre_completo' => 'Administrador de Bienestar',
        ]);

        Usuario::factory()->administrador()->create([
            'cedula' => $empleado->cedula,
            'nombre_completo' => $empleado->nombre_completo,
        ]);

        $this->call([
            TasaVehiculoSeeder::class,
            TipoPrestamoSeeder::class,
        ]);
    }
}
