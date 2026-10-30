<?php

namespace Tests\Feature;

use App\Console\Commands\PrepararInstalacionCommand;
use App\Models\Empleado;
use App\Models\Prestamo;
use App\Models\TasaVehiculo;
use App\Models\TipoDocumento;
use App\Models\TipoPrestamo;
use App\Models\Usuario;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

class PrepararInstalacionTest extends TestCase
{
    use RefreshDatabase;

    private function ejecutar(string $contrasena = 'ClaveSegura1', string $confirmacion = ''): PendingCommand
    {
        return $this->artisan('app:preparar-instalacion', ['--cedula' => '1020304050', '--nombre' => 'Ana', '--apellido' => 'Pérez'])
            ->expectsQuestion(PrepararInstalacionCommand::PREGUNTA_CONTRASENA, $contrasena)
            ->expectsQuestion(PrepararInstalacionCommand::PREGUNTA_CONFIRMACION, $confirmacion === '' ? $contrasena : $confirmacion);
    }

    public function test_carga_catalogos_sistema_y_administrador_principal(): void
    {
        $this->ejecutar()->assertSuccessful();

        $this->assertSame(8, TipoPrestamo::count());
        $this->assertSame(8, TasaVehiculo::count());
        $this->assertSame(5, TipoDocumento::count());
        $this->assertFalse(Usuario::sistema()->habilitado);

        $principal = Usuario::where('es_principal', true)->sole();
        $this->assertSame('1020304050', $principal->cedula);
        $this->assertTrue($principal->esPrincipal());
        $this->assertTrue(Hash::check('ClaveSegura1', $principal->contrasena_hash));
    }

    public function test_es_idempotente_y_no_cambia_la_contrasena_del_principal(): void
    {
        $this->ejecutar()->assertSuccessful();
        $conteos = [TipoPrestamo::count(), TasaVehiculo::count(), TipoDocumento::count(), Usuario::count()];
        $hash = Usuario::find('1020304050')->contrasena_hash;

        $this->artisan('app:preparar-instalacion')->assertSuccessful();

        $this->assertSame($conteos, [TipoPrestamo::count(), TasaVehiculo::count(), TipoDocumento::count(), Usuario::count()]);
        $this->assertSame($hash, Usuario::find('1020304050')->contrasena_hash);
    }

    public function test_no_carga_datos_de_practica_ni_ficticios(): void
    {
        $this->ejecutar()->assertSuccessful();

        $this->assertSame(0, Empleado::count());
        $this->assertSame(0, Prestamo::count());
        $this->assertSame(2, Usuario::count(), 'Solo Sistema y el administrador principal.');
        $this->assertNull(Usuario::find('1000000000'), 'No crea el administrador de DatabaseSeeder.');
    }

    public function test_el_seeder_por_defecto_no_crea_ningun_administrador_ni_usuario_con_contrasena_conocida(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame([Usuario::CEDULA_SISTEMA], Usuario::pluck('cedula')->all());
        $this->assertNull(Usuario::where('es_principal', true)->first());
        $this->assertSame(8, TipoPrestamo::count());
    }

    public function test_no_tiene_contrasena_por_defecto_y_exige_una_valida(): void
    {
        $this->ejecutar('')->expectsOutputToContain('La contraseña es obligatoria.')->assertFailed();
        $this->assertNull(Usuario::where('es_principal', true)->first());

        $this->ejecutar('password')->expectsOutputToContain('mayúscula')->assertFailed();
        $this->assertNull(Usuario::where('es_principal', true)->first());
    }

    public function test_rechaza_confirmacion_distinta_y_cedula_reservada(): void
    {
        $this->ejecutar('ClaveSegura1', 'ClaveSegura2')->expectsOutputToContain('no coincide')->assertFailed();

        $this->artisan('app:preparar-instalacion', ['--cedula' => Usuario::CEDULA_SISTEMA, '--nombre' => 'Ana', '--apellido' => 'Pérez'])
            ->expectsOutputToContain('reservada')
            ->assertFailed();

        $this->assertNull(Usuario::where('es_principal', true)->first());
    }
}
