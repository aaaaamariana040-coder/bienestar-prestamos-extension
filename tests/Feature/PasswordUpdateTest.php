<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_formulario_de_cambio_de_contrasena_muestra_las_reglas(): void
    {
        $this->actingAs(Usuario::factory()->create())
            ->get(route('password.edit'))
            ->assertOk()
            ->assertSee('Mínimo 8 caracteres. La confirmación debe coincidir con la contraseña.')
            ->assertSee('title="Mínimo 8 caracteres.', false);
    }

    public function test_usuario_autenticado_puede_cambiar_su_propia_contrasena(): void
    {
        $usuario = Usuario::factory()->create();

        $this->actingAs($usuario)->put(route('password.update'), [
            'current_password' => 'password',
            'password' => 'nueva-clave-segura',
            'password_confirmation' => 'nueva-clave-segura',
        ])->assertRedirect(route('password.edit'));

        $this->assertTrue(Hash::check('nueva-clave-segura', $usuario->fresh()->contrasena_hash));
    }

    public function test_no_se_puede_cambiar_la_contrasena_sin_la_contrasena_actual_correcta(): void
    {
        $usuario = Usuario::factory()->create();

        $this->actingAs($usuario)->put(route('password.update'), [
            'current_password' => 'clave-incorrecta',
            'password' => 'nueva-clave-segura',
            'password_confirmation' => 'nueva-clave-segura',
        ])->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('password', $usuario->fresh()->contrasena_hash));
    }

    public function test_invitado_es_redirigido_a_login(): void
    {
        $this->get(route('password.edit'))->assertRedirect(route('login'));
    }
}
