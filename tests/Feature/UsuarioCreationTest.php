<?php

namespace Tests\Feature;

use App\Enums\RolUsuario;
use App\Models\Empleado;
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
        $empleado = Empleado::factory()->create();

        $casos = [
            'Corta1a' => 'La contraseña debe tener mínimo 8 caracteres.',
            'sinmayuscula1' => 'La contraseña debe contener al menos una letra mayúscula.',
            'SINMINUSCULA1' => 'La contraseña debe contener al menos una letra minúscula.',
            'SinNumeroAqui' => 'La contraseña debe contener al menos un número.',
        ];

        foreach ($casos as $clave => $mensaje) {
            $this->actingAs($administrador)->post(route('usuarios.store'), [
                'cedula' => $empleado->cedula,
                'rol' => RolUsuario::FuncionarioBienestar->value,
                'password' => $clave,
                'password_confirmation' => $clave,
            ])->assertSessionHasErrors(['password' => $mensaje]);
        }

        $this->actingAs($administrador)->post(route('usuarios.store'), [
            'cedula' => $empleado->cedula,
            'rol' => RolUsuario::FuncionarioBienestar->value,
            'password' => 'ClaveSegura1',
            'password_confirmation' => 'ClaveSegura2',
        ])->assertSessionHasErrors(['password' => 'La confirmación de la contraseña no coincide.']);

        $this->assertDatabaseMissing('usuarios', ['cedula' => $empleado->cedula]);
    }

    public function test_administrador_puede_crear_usuario_para_un_empleado_existente(): void
    {
        $administrador = Usuario::factory()->administrador()->create();
        $empleado = Empleado::factory()->create();

        $this->actingAs($administrador)->post(route('usuarios.store'), [
            'cedula' => $empleado->cedula,
            'rol' => RolUsuario::FuncionarioBienestar->value,
            'password' => 'ClaveSegura1',
            'password_confirmation' => 'ClaveSegura1',
        ])->assertRedirect(route('empleados.index'));

        $usuario = Usuario::findOrFail($empleado->cedula);
        $this->assertTrue(Hash::check('ClaveSegura1', $usuario->contrasena_hash));
        $this->assertSame(RolUsuario::FuncionarioBienestar, $usuario->rol);
    }

    public function test_funcionario_bienestar_no_puede_crear_usuarios(): void
    {
        $funcionario = Usuario::factory()->create();
        $empleado = Empleado::factory()->create();

        $this->actingAs($funcionario)->get(route('usuarios.create'))->assertForbidden();

        $this->actingAs($funcionario)->post(route('usuarios.store'), [
            'cedula' => $empleado->cedula,
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
