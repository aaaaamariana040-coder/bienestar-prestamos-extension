<?php

namespace Tests\Feature;

use App\Enums\OrigenPago;
use App\Enums\RolUsuario;
use App\Models\Pago;
use App\Models\Usuario;
use App\Services\AuditoriaService;
use Database\Seeders\SistemaUsuarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class UsuarioSistemaProtegidoTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $administrador;

    private Usuario $sistema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SistemaUsuarioSeeder::class);
        $this->sistema = Usuario::sistema();
        $this->administrador = Usuario::factory()->administrador()->create(['nombre' => 'Ada', 'apellido' => 'Admin']);
        Usuario::factory()->create(['nombre' => 'Fernando', 'apellido' => 'Funcionario']);
        $this->actingAs($this->administrador);
    }

    public function test_el_usuario_sistema_se_llama_sistema(): void
    {
        $this->assertSame('Sistema', $this->sistema->nombre_completo);
        $this->assertTrue($this->sistema->esSistema());
        $this->assertFalse($this->administrador->esSistema());
    }

    public function test_no_aparece_en_el_listado_de_usuarios(): void
    {
        $respuesta = $this->get(route('usuarios.index'))->assertOk()->assertSee('Ada Admin')->assertSee('Fernando Funcionario');

        $respuesta->assertDontSee('0000000000');
        $this->assertNotContains(Usuario::CEDULA_SISTEMA, $respuesta->viewData('usuarios')->pluck('cedula')->all());
        $this->assertSame(2, $respuesta->viewData('usuarios')->total());
    }

    public function test_la_pantalla_de_edicion_responde_403(): void
    {
        $this->get(route('usuarios.edit', $this->sistema))->assertForbidden();
    }

    public function test_un_put_directo_no_puede_cambiar_nombre_ni_rol(): void
    {
        $this->put(route('usuarios.update', $this->sistema), [
            'nombre' => 'Otro',
            'apellido' => 'Nombre',
            'rol' => RolUsuario::Administrador->value,
        ])->assertForbidden();

        $sistema = Usuario::sistema();
        $this->assertSame('Sistema', $sistema->nombre_completo);
        $this->assertSame(RolUsuario::FuncionarioBienestar, $sistema->rol);
    }

    public function test_un_patch_directo_no_puede_habilitarlo(): void
    {
        $this->patch(route('usuarios.habilitar', $this->sistema))->assertForbidden();

        $this->assertFalse(Usuario::sistema()->habilitado);
        $this->assertFalse(Usuario::sistema()->puedeOperar());
    }

    public function test_un_patch_directo_para_deshabilitarlo_tambien_se_rechaza(): void
    {
        $this->patch(route('usuarios.deshabilitar', $this->sistema))->assertForbidden();
    }

    public function test_no_se_le_puede_cambiar_la_contrasena(): void
    {
        $hashOriginal = $this->sistema->contrasena_hash;

        $this->put(route('usuarios.update', $this->sistema), [
            'nombre' => 'Sistema',
            'apellido' => '',
            'rol' => RolUsuario::FuncionarioBienestar->value,
            'password' => 'NuevaClave#2026',
            'password_confirmation' => 'NuevaClave#2026',
        ])->assertForbidden();

        $this->assertSame($hashOriginal, Usuario::sistema()->contrasena_hash);
    }

    public function test_la_ruta_de_contrasena_propia_no_sirve_para_el_usuario_sistema(): void
    {
        $hashOriginal = $this->sistema->contrasena_hash;

        $this->actingAs($this->sistema)
            ->put(route('password.update'), [
                'current_password' => 'lo-que-sea',
                'password' => 'NuevaClave#2026',
                'password_confirmation' => 'NuevaClave#2026',
            ])
            ->assertRedirect(route('login'));

        $this->assertSame($hashOriginal, Usuario::sistema()->contrasena_hash);
    }

    public function test_no_se_puede_eliminar_por_ruta_ni_por_el_modelo(): void
    {
        $this->delete('/usuarios/'.Usuario::CEDULA_SISTEMA)->assertStatus(405);
        $this->assertNotNull(Usuario::find(Usuario::CEDULA_SISTEMA));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('No se puede eliminar al usuario Sistema.');

        $this->sistema->delete();
    }

    public function test_no_se_puede_crear_otro_usuario_con_la_cedula_reservada(): void
    {
        $this->post(route('usuarios.store'), [
            'cedula' => Usuario::CEDULA_SISTEMA,
            'nombre' => 'Intruso',
            'apellido' => 'Falso',
            'password' => 'ClaveSegura#2026',
            'password_confirmation' => 'ClaveSegura#2026',
            'rol' => RolUsuario::Administrador->value,
        ])->assertSessionHasErrors('cedula');

        $this->assertSame(1, Usuario::where('cedula', Usuario::CEDULA_SISTEMA)->count());
        $this->assertSame('Sistema', Usuario::sistema()->nombre_completo);
    }

    public function test_la_cedula_reservada_tambien_se_rechaza_si_el_usuario_sistema_aun_no_existe(): void
    {
        Usuario::query()->whereKey(Usuario::CEDULA_SISTEMA)->delete();

        $this->post(route('usuarios.store'), [
            'cedula' => Usuario::CEDULA_SISTEMA,
            'nombre' => 'Intruso',
            'apellido' => 'Falso',
            'password' => 'ClaveSegura#2026',
            'password_confirmation' => 'ClaveSegura#2026',
            'rol' => RolUsuario::Administrador->value,
        ])->assertSessionHasErrors(['cedula' => 'Esa cédula está reservada para el sistema.']);

        $this->assertNull(Usuario::find(Usuario::CEDULA_SISTEMA));
    }

    public function test_la_auditoria_sigue_mostrando_sistema_como_autor(): void
    {
        app(AuditoriaService::class)->registrarComoSistema('Pago automático de cuota', 'Préstamo #1 — cuota 2 de 36');

        $this->get(route('auditoria.index'))
            ->assertOk()
            ->assertSee('Pago automático de cuota')
            ->assertSee('<td>Sistema</td>', false)
            ->assertSee('<option value="0000000000"', false);

        $this->get(route('auditoria.index', ['cedula_usu' => Usuario::CEDULA_SISTEMA]))
            ->assertOk()
            ->assertSee('Pago automático de cuota');
    }

    public function test_los_pagos_automaticos_conservan_a_sistema_como_autor(): void
    {
        $pago = Pago::factory()->create(['cedula_usu' => Usuario::CEDULA_SISTEMA, 'origen' => OrigenPago::Automatico]);

        $this->assertSame('Sistema', $pago->fresh()->usuario->nombre_completo);
        $this->assertSame(OrigenPago::Automatico, $pago->fresh()->origen);
    }
}
