<?php

namespace Tests\Feature;

use App\Enums\RolUsuario;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsuarioManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrador_puede_listar_usuarios(): void
    {
        $administrador = Usuario::factory()->administrador()->create();
        Usuario::factory()->count(2)->create();

        $this->actingAs($administrador)->get(route('usuarios.index'))->assertOk();
    }

    public function test_funcionario_bienestar_no_puede_listar_usuarios(): void
    {
        $funcionario = Usuario::factory()->create();

        $this->actingAs($funcionario)->get(route('usuarios.index'))->assertForbidden();
    }

    public function test_administrador_puede_cambiar_el_rol_de_un_usuario(): void
    {
        $administrador = Usuario::factory()->administrador()->create();
        $usuario = Usuario::factory()->create();

        $this->actingAs($administrador)
            ->put(route('usuarios.update', $usuario), ['rol' => RolUsuario::Administrador->value])
            ->assertRedirect(route('usuarios.index'));

        $this->assertSame(RolUsuario::Administrador, $usuario->fresh()->rol);
    }

    public function test_funcionario_bienestar_no_puede_editar_usuarios(): void
    {
        $funcionario = Usuario::factory()->create();
        $usuario = Usuario::factory()->create();

        $this->actingAs($funcionario)
            ->put(route('usuarios.update', $usuario), ['rol' => RolUsuario::Administrador->value])
            ->assertForbidden();
    }

    public function test_administrador_puede_deshabilitar_y_habilitar_un_usuario(): void
    {
        $administrador = Usuario::factory()->administrador()->create();
        $usuario = Usuario::factory()->create();

        $this->actingAs($administrador)
            ->patch(route('usuarios.deshabilitar', $usuario))
            ->assertRedirect(route('usuarios.index'));

        $this->assertFalse($usuario->fresh()->habilitado);

        $this->actingAs($administrador)
            ->patch(route('usuarios.habilitar', $usuario))
            ->assertRedirect(route('usuarios.index'));

        $this->assertTrue($usuario->fresh()->habilitado);
    }

    public function test_administrador_no_puede_deshabilitar_su_propia_cuenta(): void
    {
        $administrador = Usuario::factory()->administrador()->create();

        $this->actingAs($administrador)
            ->patch(route('usuarios.deshabilitar', $administrador))
            ->assertForbidden();

        $this->assertTrue($administrador->fresh()->habilitado);
    }

    public function test_nadie_puede_deshabilitar_a_otro_administrador(): void
    {
        $actor = Usuario::factory()->administrador()->create();
        $otroAdministrador = Usuario::factory()->administrador()->create();

        $this->actingAs($actor)
            ->patch(route('usuarios.deshabilitar', $otroAdministrador))
            ->assertForbidden();

        $this->assertTrue($otroAdministrador->fresh()->habilitado);
    }

    public function test_listado_muestra_desactivado_y_en_gris_el_boton_del_administrador(): void
    {
        $administrador = Usuario::factory()->administrador()->create();
        Usuario::factory()->create(['nombre_completo' => 'Funcionario Normal']);

        $this->actingAs($administrador)
            ->get(route('usuarios.index'))
            ->assertOk()
            ->assertSee('title="El administrador no se puede deshabilitar"', false)
            ->assertSee('class="btn btn-sm btn-outline-secondary" disabled>Deshabilitar</button>', false)
            ->assertSee('btn-outline-danger">Deshabilitar</button>', false);
    }

    public function test_el_rol_del_administrador_si_se_puede_editar(): void
    {
        $actor = Usuario::factory()->administrador()->create();
        $otroAdministrador = Usuario::factory()->administrador()->create();

        $this->actingAs($actor)
            ->put(route('usuarios.update', $otroAdministrador), ['rol' => RolUsuario::FuncionarioBienestar->value])
            ->assertRedirect(route('usuarios.index'));
    }

    public function test_un_usuario_deshabilitado_no_puede_iniciar_sesion(): void
    {
        $usuario = Usuario::factory()->create(['habilitado' => false]);

        $this->post(route('login'), [
            'cedula' => $usuario->cedula,
            'password' => 'password',
        ])->assertSessionHasErrors('cedula');

        $this->assertGuest();
    }
}
