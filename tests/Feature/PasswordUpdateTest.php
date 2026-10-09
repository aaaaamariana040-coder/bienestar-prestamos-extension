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
            ->assertSee('Mínimo 8 caracteres, al menos una mayúscula, una minúscula y un número. La confirmación debe coincidir con la contraseña.')
            ->assertSee('title="Mínimo 8 caracteres, al menos una mayúscula', false);
    }

    public function test_contrasena_nueva_debil_es_rechazada_y_no_se_cambia(): void
    {
        $usuario = Usuario::factory()->create();

        foreach (['nuevaclave1', 'NUEVACLAVE1', 'NuevaClave', 'Nc1'] as $clave) {
            $this->actingAs($usuario)->put(route('password.update'), [
                'current_password' => 'password',
                'password' => $clave,
                'password_confirmation' => $clave,
            ])->assertSessionHasErrors('password');
        }

        $this->assertTrue(Hash::check('password', $usuario->fresh()->contrasena_hash));
    }

    public function test_usuario_autenticado_puede_cambiar_su_propia_contrasena(): void
    {
        $usuario = Usuario::factory()->create();

        $this->actingAs($usuario)->put(route('password.update'), [
            'current_password' => 'password',
            'password' => 'NuevaClave1',
            'password_confirmation' => 'NuevaClave1',
        ])->assertRedirect(route('password.edit'));

        $this->assertTrue(Hash::check('NuevaClave1', $usuario->fresh()->contrasena_hash));
    }

    public function test_no_se_puede_cambiar_la_contrasena_sin_la_contrasena_actual_correcta(): void
    {
        $usuario = Usuario::factory()->create();

        $this->actingAs($usuario)->put(route('password.update'), [
            'current_password' => 'clave-incorrecta',
            'password' => 'NuevaClave1',
            'password_confirmation' => 'NuevaClave1',
        ])->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('password', $usuario->fresh()->contrasena_hash));
    }

    public function test_invitado_es_redirigido_a_login(): void
    {
        $this->get(route('password.edit'))->assertRedirect(route('login'));
    }
}
