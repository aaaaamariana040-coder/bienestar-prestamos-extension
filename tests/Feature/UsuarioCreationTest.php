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
            ->assertSee('Mínimo 8 caracteres. La confirmación debe coincidir con la contraseña.')
            ->assertSee('title="Mínimo 8 caracteres.', false);
    }

    public function test_administrador_puede_crear_usuario_para_un_empleado_existente(): void
    {
        $administrador = Usuario::factory()->administrador()->create();
        $empleado = Empleado::factory()->create();

        $this->actingAs($administrador)->post(route('usuarios.store'), [
            'cedula' => $empleado->cedula,
            'rol' => RolUsuario::FuncionarioBienestar->value,
            'password' => 'clave-segura',
            'password_confirmation' => 'clave-segura',
        ])->assertRedirect(route('empleados.index'));

        $usuario = Usuario::findOrFail($empleado->cedula);
        $this->assertTrue(Hash::check('clave-segura', $usuario->contrasena_hash));
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
            'password' => 'clave-segura',
            'password_confirmation' => 'clave-segura',
        ])->assertForbidden();
    }

    public function test_invitado_es_redirigido_a_login(): void
    {
        $this->get(route('usuarios.create'))->assertRedirect(route('login'));
    }
}
