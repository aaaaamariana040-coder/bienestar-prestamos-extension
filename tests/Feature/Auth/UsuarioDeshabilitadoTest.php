<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\EnsureUsuarioHabilitado;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UsuarioDeshabilitadoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function rutasProtegidas(): array
    {
        return [
            'empleados' => route('empleados.index'),
            'prestamos' => route('prestamos.index'),
            'reportes' => route('reportes.prestamos'),
        ];
    }

    public function test_un_usuario_deshabilitado_con_sesion_activa_es_expulsado_en_empleados_prestamos_y_reportes(): void
    {
        foreach ([Usuario::factory()->administrador(), Usuario::factory()] as $factory) {
            $deshabilitado = $factory->create(['habilitado' => false]);

            foreach ($this->rutasProtegidas() as $ruta) {
                $this->actingAs($deshabilitado)
                    ->get($ruta)
                    ->assertRedirect(route('login'))
                    ->assertSessionHasErrors(['cedula' => EnsureUsuarioHabilitado::MENSAJE_CUENTA_DESHABILITADA]);

                $this->assertGuest();
            }
        }
    }

    public function test_un_usuario_deshabilitado_es_expulsado_tambien_en_rutas_de_escritura(): void
    {
        $deshabilitado = Usuario::factory()->administrador()->create(['habilitado' => false]);

        $this->actingAs($deshabilitado)
            ->post(route('empleados.store'), ['cedula' => '900000001'])
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseMissing('empleados', ['cedula' => '900000001']);
    }

    public function test_al_deshabilitar_a_un_usuario_con_sesion_iniciada_su_siguiente_peticion_lo_expulsa_y_la_sesion_queda_invalidada(): void
    {
        $usuario = Usuario::factory()->create();

        $this->post(route('login'), ['cedula' => $usuario->cedula, 'password' => 'password']);
        $this->assertAuthenticatedAs($usuario);
        $this->get(route('empleados.index'))->assertOk();

        $usuario->update(['habilitado' => false]);
        Auth::forgetGuards();

        $this->get(route('empleados.index'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['cedula' => EnsureUsuarioHabilitado::MENSAJE_CUENTA_DESHABILITADA]);
        $this->assertGuest();

        $this->get(route('empleados.index'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_el_mensaje_de_expulsion_se_muestra_en_la_pantalla_de_login(): void
    {
        $deshabilitado = Usuario::factory()->create(['habilitado' => false]);

        $this->actingAs($deshabilitado)->get(route('empleados.index'));

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Tu cuenta está deshabilitada. Comunícate con el administrador.');
    }

    public function test_un_usuario_habilitado_no_es_afectado(): void
    {
        foreach ([Usuario::factory()->administrador(), Usuario::factory()] as $factory) {
            $habilitado = $factory->create();

            foreach ($this->rutasProtegidas() as $ruta) {
                $this->actingAs($habilitado)->get($ruta)->assertOk();

                $this->assertAuthenticatedAs($habilitado);
            }
        }
    }

    public function test_un_usuario_habilitado_puede_iniciar_y_cerrar_sesion(): void
    {
        $usuario = Usuario::factory()->create();

        $this->post(route('login'), ['cedula' => $usuario->cedula, 'password' => 'password'])
            ->assertRedirect(route('empleados.index'));
        $this->assertAuthenticatedAs($usuario);

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_un_usuario_deshabilitado_puede_cerrar_su_sesion_sin_mensaje_de_error(): void
    {
        $deshabilitado = Usuario::factory()->create(['habilitado' => false]);

        $this->actingAs($deshabilitado)
            ->post(route('logout'))
            ->assertRedirect(route('login'))
            ->assertSessionHasNoErrors();

        $this->assertGuest();
    }

    public function test_un_usuario_habilitado_puede_cambiar_su_contrasena(): void
    {
        $usuario = Usuario::factory()->create();

        $this->actingAs($usuario)->put(route('password.update'), [
            'current_password' => 'password',
            'password' => 'nueva-clave-segura',
            'password_confirmation' => 'nueva-clave-segura',
        ])->assertRedirect(route('password.edit'));

        $this->assertTrue(Hash::check('nueva-clave-segura', $usuario->fresh()->contrasena_hash));
    }

    public function test_un_usuario_deshabilitado_no_puede_cambiar_su_contrasena(): void
    {
        $deshabilitado = Usuario::factory()->create(['habilitado' => false]);

        $this->actingAs($deshabilitado)->put(route('password.update'), [
            'current_password' => 'password',
            'password' => 'nueva-clave-segura',
            'password_confirmation' => 'nueva-clave-segura',
        ])->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertTrue(Hash::check('password', $deshabilitado->fresh()->contrasena_hash));
    }

    public function test_login_y_logout_quedan_fuera_del_middleware_habilitado(): void
    {
        $rutas = app('router')->getRoutes();

        foreach (['GET', 'POST'] as $metodo) {
            $middleware = $rutas->match(Request::create('/login', $metodo))->gatherMiddleware();

            $this->assertNotContains('habilitado', $middleware);
        }

        $middlewareLogout = $rutas->match(Request::create('/logout', 'POST'))->gatherMiddleware();

        $this->assertContains('auth', $middlewareLogout);
        $this->assertNotContains('habilitado', $middlewareLogout);
    }

    public function test_el_middleware_habilitado_corre_despues_de_la_autenticacion(): void
    {
        $middleware = app('router')->getRoutes()
            ->match(Request::create('/empleados', 'GET'))
            ->gatherMiddleware();

        $this->assertContains('auth', $middleware);
        $this->assertContains('habilitado', $middleware);
        $this->assertLessThan(array_search('habilitado', $middleware, true), array_search('auth', $middleware, true));
    }
}
