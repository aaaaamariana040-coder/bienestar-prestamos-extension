<?php

namespace Tests\Feature\Auth;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
    }

    public function test_users_can_authenticate_using_cedula_and_password(): void
    {
        $usuario = Usuario::factory()->create();

        $response = $this->post('/login', [
            'cedula' => $usuario->cedula,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($usuario);
        $response->assertRedirect(route('empleados.index'));
    }

    public function test_users_cannot_authenticate_with_invalid_password(): void
    {
        $usuario = Usuario::factory()->create();

        $response = $this->post('/login', [
            'cedula' => $usuario->cedula,
            'password' => 'contrasena-incorrecta',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors('cedula');
    }

    public function test_disabled_users_cannot_authenticate(): void
    {
        $usuario = Usuario::factory()->create(['habilitado' => false]);

        $this->post('/login', [
            'cedula' => $usuario->cedula,
            'password' => 'password',
        ]);

        $this->assertGuest();
    }
}
