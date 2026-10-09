<?php

namespace Tests\Feature;

use App\Models\Empleado;
use App\Models\Prestamo;
use App\Models\SmmlvAnual;
use App\Models\Usuario;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Database\Seeders\TipoPrestamoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DemoSeederAislamientoTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_seeder_por_defecto_no_crea_datos_de_demostracion(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, Empleado::count());
        $this->assertSame(0, Prestamo::count());
    }

    public function test_no_modifica_datos_existentes_y_solo_agrega_los_parametros_que_faltan(): void
    {
        Storage::fake('local');
        $this->seed(TipoPrestamoSeeder::class);
        Usuario::factory()->administrador()->create(['cedula' => '1000000000']);
        $existente = Empleado::factory()->create(['cedula' => '1098000000', 'nombre_completo' => 'Persona Real', 'salario' => 4_321_000]);
        SmmlvAnual::create(['anio' => now()->year, 'valor' => 2_000_000]);

        $this->seed(DemoSeeder::class);

        $this->assertSame('Persona Real', $existente->fresh()->nombre_completo);
        $this->assertSame('4321000.00', $existente->fresh()->salario);
        $this->assertSame('2000000.00', (string) SmmlvAnual::where('anio', now()->year)->value('valor'));
        $this->assertSame(1, SmmlvAnual::where('anio', now()->year)->count());
        $this->assertSame(8, Empleado::where('cedula', 'like', '9000000%')->count());
    }

    public function test_sin_el_seeder_base_avisa_y_no_crea_nada(): void
    {
        $this->seed(DemoSeeder::class);

        $this->assertSame(0, Empleado::count());
    }
}
