<?php

namespace Tests\Feature;

use App\Models\Empleado;
use App\Models\Prestamo;
use App\Models\Usuario;
use Tests\TestCase;

/**
 * practica:reiniciar borra la base y recarga los datos de práctica; está protegido. Estas pruebas
 * corren sobre la base SQLite en memoria de phpunit.xml (el comando hace migrate:fresh por su cuenta).
 */
class PracticaReiniciarTest extends TestCase
{
    public function test_falla_sin_practica_habilitada_y_no_pregunta_ni_borra_nada(): void
    {
        config(['practica.habilitada' => false]);

        $this->artisan('migrate:fresh')->assertSuccessful();
        Usuario::factory()->create(['cedula' => '7770001']);

        $this->artisan('practica:reiniciar')
            ->expectsOutputToContain('PRACTICA_HABILITADA=true')
            ->assertFailed();

        $this->assertSame(1, Usuario::count(), 'No borró nada.');
    }

    public function test_el_valor_por_defecto_de_la_variable_es_false(): void
    {
        $this->assertStringContainsString("env('PRACTICA_HABILITADA', false)", file_get_contents(config_path('practica.php')));
        $this->assertStringContainsString('PRACTICA_HABILITADA=false', file_get_contents(base_path('.env.example')));
    }

    public function test_falla_en_produccion_aunque_este_habilitada(): void
    {
        config(['practica.habilitada' => true]);
        $this->artisan('migrate:fresh')->assertSuccessful();
        Usuario::factory()->create(['cedula' => '7770001']);
        $this->app['env'] = 'production';

        $this->artisan('practica:reiniciar')
            ->expectsOutputToContain('producción')
            ->assertFailed();

        $this->assertSame(1, Usuario::count());
    }

    public function test_pide_confirmacion_y_una_respuesta_incorrecta_no_cambia_nada(): void
    {
        config(['practica.habilitada' => true]);

        $this->artisan('migrate:fresh')->assertSuccessful();
        Usuario::factory()->create(['cedula' => '7770001']);

        $this->artisan('practica:reiniciar')
            ->expectsQuestion('Para continuar escriba REINICIAR', 'si')
            ->expectsOutputToContain('Confirmación incorrecta')
            ->assertFailed();

        $this->assertNotNull(Usuario::find('7770001'));
        $this->assertSame(0, Empleado::count());
    }

    public function test_con_la_confirmacion_borra_y_carga_los_datos_de_practica(): void
    {
        config(['practica.habilitada' => true]);

        $this->artisan('migrate:fresh')->assertSuccessful();
        Usuario::factory()->create(['cedula' => '7770001']);

        $this->artisan('practica:reiniciar')
            ->expectsQuestion('Para continuar escriba REINICIAR', 'REINICIAR')
            ->assertSuccessful();

        $this->assertNull(Usuario::find('7770001'), 'Lo anterior se borró.');
        $this->assertNotNull(Usuario::find('1000000000'));
        $this->assertSame(20, Empleado::count());
        $this->assertGreaterThanOrEqual(12, Prestamo::count());
    }
}
