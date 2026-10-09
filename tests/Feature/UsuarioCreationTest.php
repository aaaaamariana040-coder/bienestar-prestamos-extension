<?php

namespace Tests\Feature;

use App\Enums\RolUsuario;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UsuarioCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_formulario_de_crear_usuario_muestra_las_reglas_de_contrasena(): void
    {
        $this->actingAs(Usuario::factory()->administrador()->create())
            ->get(route('usuarios.create'))
            ->assertOk()
            ->assertSee('Mínimo 8 caracteres, al menos una mayúscula, una minúscula y un número. La confirmación debe coincidir con la contraseña.')
            ->assertSee('title="Mínimo 8 caracteres, al menos una mayúscula', false);
    }

    public function test_contrasena_debil_es_rechazada_con_mensajes_en_espanol(): void
    {
        $administrador = Usuario::factory()->administrador()->create();
        $cedula = '1234567890';
        $casos = [
            'Corta1a' => 'La contraseña debe tener mínimo 8 caracteres.',
            'sinmayuscula1' => 'La contraseña debe contener al menos una letra mayúscula.',
            'SINMINUSCULA1' => 'La contraseña debe contener al menos una letra minúscula.',
            'SinNumeroAqui' => 'La contraseña debe contener al menos un número.',
        ];

        foreach ($casos as $clave => $mensaje) {
            $this->actingAs($administrador)->post(route('usuarios.store'), [
                'cedula' => $cedula,
                'nombre' => 'Camila',
                'apellido' => 'Ospina',
                'rol' => RolUsuario::FuncionarioBienestar->value,
                'password' => $clave,
                'password_confirmation' => $clave,
            ])->assertSessionHasErrors(['password' => $mensaje]);
        }

        $this->actingAs($administrador)->post(route('usuarios.store'), [
            'cedula' => $cedula,
            'nombre' => 'Camila',
            'apellido' => 'Ospina',
            'rol' => RolUsuario::FuncionarioBienestar->value,
            'password' => 'ClaveSegura1',
            'password_confirmation' => 'ClaveSegura2',
        ])->assertSessionHasErrors(['password' => 'La confirmación de la contraseña no coincide.']);

        $this->assertDatabaseMissing('usuarios', ['cedula' => $cedula]);
    }

    public function test_administrador_puede_crear_usuario_sin_empleado_asociado(): void
    {
        $administrador = Usuario::factory()->administrador()->create();
        $cedula = '1234567890';

        $this->actingAs($administrador)->post(route('usuarios.store'), [
            'cedula' => $cedula,
            'nombre' => 'Camila',
            'apellido' => 'Ospina',
            'rol' => RolUsuario::FuncionarioBienestar->value,
            'password' => 'ClaveSegura1',
            'password_confirmation' => 'ClaveSegura1',
        ])->assertRedirect(route('empleados.index'));

        $usuario = Usuario::findOrFail($cedula);
        $this->assertDatabaseMissing('empleados', ['cedula' => $cedula]);
        $this->assertSame('Camila Ospina', $usuario->nombre_completo);
        $this->assertTrue(Hash::check('ClaveSegura1', $usuario->contrasena_hash));
        $this->assertSame(RolUsuario::FuncionarioBienestar, $usuario->rol);
    }

    public function test_formulario_de_crear_usuario_pide_cedula_nombre_apellido_y_no_elige_empleado(): void
    {
        $this->actingAs(Usuario::factory()->administrador()->create())
            ->get(route('usuarios.create'))
            ->assertOk()
            ->assertSee('name="cedula"', false)
            ->assertSee('name="nombre"', false)
            ->assertSee('name="apellido"', false)
            ->assertSee('name="rol"', false)
            ->assertSee('name="password"', false)
            ->assertSee('maxlength="10"', false)
            ->assertDontSee('Selecciona un empleado');
    }

    public function test_crear_usuario_valida_cedula_nombre_y_apellido_en_espanol(): void
    {
        $administrador = Usuario::factory()->administrador()->create();
        $existente = Usuario::factory()->create();
        $base = ['nombre' => 'Camila', 'apellido' => 'Ospina', 'rol' => RolUsuario::FuncionarioBienestar->value, 'password' => 'ClaveSegura1', 'password_confirmation' => 'ClaveSegura1'];

        foreach (['12345abc', '1.234.567', '12345678901'] as $cedulaInvalida) {
            $this->actingAs($administrador)->post(route('usuarios.store'), $base + ['cedula' => $cedulaInvalida])
                ->assertSessionHasErrors(['cedula' => 'La cédula debe contener solo números, máximo 10 dígitos, sin puntos ni caracteres especiales.']);
        }

        $this->actingAs($administrador)->post(route('usuarios.store'), $base + ['cedula' => $existente->cedula])
            ->assertSessionHasErrors(['cedula' => 'Ya existe un usuario con esa cédula.']);

        $this->actingAs($administrador)->post(route('usuarios.store'), ['nombre' => '', 'apellido' => '', 'cedula' => '1234567890'] + $base)
            ->assertSessionHasErrors(['nombre' => 'El nombre es obligatorio.', 'apellido' => 'El apellido es obligatorio.']);

        $this->assertDatabaseMissing('usuarios', ['cedula' => '1234567890']);
    }

    public function test_funcionario_bienestar_no_puede_crear_usuarios(): void
    {
        $funcionario = Usuario::factory()->create();
        $cedula = '1234567890';

        $this->actingAs($funcionario)->get(route('usuarios.create'))->assertForbidden();

        $this->actingAs($funcionario)->post(route('usuarios.store'), [
            'cedula' => $cedula,
            'nombre' => 'Camila',
            'apellido' => 'Ospina',
            'rol' => RolUsuario::FuncionarioBienestar->value,
            'password' => 'ClaveSegura1',
            'password_confirmation' => 'ClaveSegura1',
        ])->assertForbidden();
    }

    public function test_invitado_es_redirigido_a_login(): void
    {
        $this->get(route('usuarios.create'))->assertRedirect(route('login'));
    }
}
