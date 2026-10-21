<?php

namespace Tests\Feature;

use App\Enums\RolUsuario;
use App\Models\Auditoria;
use App\Models\Usuario;
use App\Services\CambioDeUsuarioNoPermitidoException;
use App\Services\PermisosUsuarioService;
use App\Services\UsuarioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Salvaguardas de las cuentas (último administrador activo, auto-bloqueo, al menos una
 * cuenta que gestione usuarios) y auditoría de crear, cambiar rol y permisos,
 * habilitar y deshabilitar.
 */
class UsuarioSalvaguardasTest extends TestCase
{
    use RefreshDatabase;

    private function permisosDelPerfil(RolUsuario $rol): array
    {
        return app(PermisosUsuarioService::class)->defaultsPara($rol);
    }

    /**
     * @param  list<string>|null  $permisos
     */
    private function editar(Usuario $actor, Usuario $objetivo, string $rol, ?array $permisos = null, string $nombre = 'Persona'): TestResponse
    {
        return $this->actingAs($actor)->put(route('usuarios.update', $objetivo), [
            'nombre' => $nombre,
            'apellido' => 'Ficticia',
            'rol' => $rol,
            ...($permisos === null ? [] : ['permisos_enviados' => 1, 'permisos' => $permisos]),
        ]);
    }

    // ───────── Último administrador activo ─────────

    public function test_al_unico_administrador_activo_no_se_le_quita_ningun_permiso(): void
    {
        $administrador = Usuario::factory()->administrador()->create();
        $sinUnPermiso = array_values(array_diff($administrador->permisosAsignados(), ['simulador.usar']));

        $this->editar($administrador, $administrador, 'ADMINISTRADOR', $sinUnPermiso)->assertSessionHasErrors(['permisos' => 'No se pueden quitar permisos al único administrador activo.']);

        $this->assertContains('simulador.usar', $administrador->fresh()->permisosAsignados());
        $this->assertSame(0, Auditoria::count());
    }

    public function test_al_unico_administrador_activo_no_se_le_cambia_el_rol(): void
    {
        $administrador = Usuario::factory()->administrador()->create();

        $this->editar($administrador, $administrador, 'FUNCIONARIO_BIENESTAR')->assertSessionHasErrors(['permisos' => 'No se puede cambiar el rol del único administrador activo.']);

        $this->assertSame(RolUsuario::Administrador, $administrador->fresh()->rol);
        $this->assertSame(0, Auditoria::count());
    }

    public function test_el_unico_administrador_si_puede_cambiar_su_nombre_y_recibir_mas_permisos(): void
    {
        $administrador = Usuario::factory()->administrador()->conPermisos(['usuarios.gestionar', 'auditoria.ver'])->create();

        $this->editar($administrador, $administrador, 'ADMINISTRADOR', [...$administrador->permisosAsignados(), 'simulador.usar'], 'Otro')->assertSessionHasNoErrors();

        $this->assertSame('Otro', $administrador->fresh()->nombre);
        $this->assertContains('simulador.usar', $administrador->fresh()->permisosAsignados());
    }

    public function test_con_otro_administrador_activo_si_se_le_pueden_quitar_permisos_a_uno(): void
    {
        $actor = Usuario::factory()->administrador()->create();
        $otro = Usuario::factory()->administrador()->create();
        $sinUnPermiso = array_values(array_diff($otro->permisosAsignados(), ['simulador.usar']));

        $this->editar($actor, $otro, 'ADMINISTRADOR', $sinUnPermiso)->assertSessionHasNoErrors();

        $this->assertNotContains('simulador.usar', $otro->fresh()->permisosAsignados());
    }

    public function test_un_administrador_deshabilitado_no_cuenta_como_otro_administrador_activo(): void
    {
        $administrador = Usuario::factory()->administrador()->create();
        Usuario::factory()->administrador()->create(['habilitado' => false]);

        $this->editar($administrador, $administrador, 'FUNCIONARIO_BIENESTAR')->assertSessionHasErrors('permisos');
    }

    // ───────── Auto-bloqueo ─────────

    public function test_un_administrador_no_puede_quitarse_a_si_mismo_el_acceso_a_usuarios(): void
    {
        $actor = Usuario::factory()->administrador()->create();
        Usuario::factory()->administrador()->create();
        $sinUsuarios = array_values(array_diff($actor->permisosAsignados(), ['usuarios.gestionar']));

        $this->editar($actor, $actor, 'ADMINISTRADOR', $sinUsuarios)->assertSessionHasErrors(['permisos' => 'No puedes quitarte a ti mismo el acceso a Usuarios.']);

        $this->assertContains('usuarios.gestionar', $actor->fresh()->permisosAsignados());
    }

    public function test_un_administrador_no_puede_pasarse_a_otro_rol_aunque_haya_otro_administrador(): void
    {
        $actor = Usuario::factory()->administrador()->create();
        Usuario::factory()->administrador()->create();

        $this->editar($actor, $actor, 'FUNCIONARIO_BIENESTAR')->assertSessionHasErrors(['permisos' => 'No puedes quitarte a ti mismo el acceso a Usuarios.']);

        $this->assertSame(RolUsuario::Administrador, $actor->fresh()->rol);
    }

    public function test_un_administrador_si_puede_quitarle_usuarios_a_otro_administrador_mientras_quede_quien_gestione(): void
    {
        $actor = Usuario::factory()->administrador()->create();
        $otro = Usuario::factory()->administrador()->create();
        $sinUsuarios = array_values(array_diff($otro->permisosAsignados(), ['usuarios.gestionar']));

        $this->editar($actor, $otro, 'ADMINISTRADOR', $sinUsuarios)->assertSessionHasNoErrors();

        $this->assertNotContains('usuarios.gestionar', $otro->fresh()->permisosAsignados());
        $this->actingAs($otro->fresh())->get(route('usuarios.index'))->assertForbidden();
    }

    // ───────── Al menos una cuenta que gestione usuarios ─────────

    public function test_siempre_debe_quedar_una_cuenta_activa_que_gestione_usuarios(): void
    {
        $unica = Usuario::factory()->administrador()->create();
        $sinGestion = Usuario::factory()->administrador()->conPermisos(['empleados.ver'])->create();
        $sinUsuarios = array_values(array_diff($unica->permisosAsignados(), ['usuarios.gestionar']));

        // Quien actúa (un tercero, por ejemplo desde la consola) intenta dejar al sistema sin gestión de usuarios.
        $this->expectException(CambioDeUsuarioNoPermitidoException::class);
        $this->expectExceptionMessage('Debe quedar al menos una cuenta activa con permiso para gestionar usuarios.');

        app(UsuarioService::class)->actualizar($unica, ['nombre' => 'A', 'apellido' => 'B', 'rol' => 'ADMINISTRADOR', 'permisos' => $sinUsuarios], $sinGestion);
    }

    public function test_deshabilitar_a_la_unica_cuenta_que_gestiona_usuarios_se_rechaza(): void
    {
        $unica = Usuario::factory()->administrador()->create();
        $otro = Usuario::factory()->create();

        $this->expectException(CambioDeUsuarioNoPermitidoException::class);

        app(UsuarioService::class)->deshabilitar($unica, $otro);
    }

    public function test_el_servidor_rechaza_dar_permisos_exclusivos_a_un_funcionario_aunque_se_salte_el_formulario(): void
    {
        $actor = Usuario::factory()->administrador()->create();
        $funcionario = Usuario::factory()->create();

        $this->expectException(CambioDeUsuarioNoPermitidoException::class);

        app(UsuarioService::class)->actualizar($funcionario, ['nombre' => 'A', 'apellido' => 'B', 'rol' => 'FUNCIONARIO_BIENESTAR', 'permisos' => ['usuarios.gestionar']], $actor);
    }

    public function test_el_servidor_rechaza_crear_un_funcionario_con_permisos_exclusivos_sin_pasar_por_el_formulario(): void
    {
        $actor = Usuario::factory()->administrador()->create();

        $this->expectException(CambioDeUsuarioNoPermitidoException::class);

        app(UsuarioService::class)->crear(['cedula' => '5550001', 'nombre' => 'A', 'apellido' => 'B', 'password' => 'Clave12345', 'rol' => 'FUNCIONARIO_BIENESTAR', 'permisos' => ['auditoria.ver']], $actor);
    }

    public function test_deshabilitar_a_un_funcionario_sigue_funcionando(): void
    {
        $actor = Usuario::factory()->administrador()->create();
        $funcionario = Usuario::factory()->create();

        $this->actingAs($actor)->patch(route('usuarios.deshabilitar', $funcionario))->assertRedirect(route('usuarios.index'))->assertSessionHas('status');

        $this->assertFalse($funcionario->fresh()->habilitado);
    }

    // ───────── Auditoría ─────────

    public function test_crear_un_usuario_deja_auditoria_con_su_rol_y_permisos(): void
    {
        $actor = Usuario::factory()->administrador()->create();

        $this->actingAs($actor)->post(route('usuarios.store'), [
            'cedula' => '5550001', 'nombre' => 'Persona', 'apellido' => 'Ficticia', 'password' => 'Clave12345', 'password_confirmation' => 'Clave12345',
            'rol' => 'FUNCIONARIO_BIENESTAR', 'permisos_enviados' => 1, 'permisos' => ['simulador.usar'],
        ])->assertRedirect();

        $auditoria = Auditoria::where('funcion_realizada', 'Creación de usuario')->sole();
        $this->assertSame($actor->cedula, $auditoria->cedula_usu);
        $this->assertStringContainsString('5550001', $auditoria->detalle);
        $this->assertStringContainsString('FUNCIONARIO_BIENESTAR', $auditoria->detalle);
        $this->assertStringContainsString('permisos: simulador.usar', $auditoria->detalle);
    }

    public function test_cambiar_permisos_deja_auditoria_con_quien_a_quien_y_lo_agregado_y_quitado(): void
    {
        $actor = Usuario::factory()->administrador()->create();
        $cuenta = Usuario::factory()->conPermisos(['empleados.ver', 'simulador.usar'])->create();

        $this->editar($actor, $cuenta, 'FUNCIONARIO_BIENESTAR', ['empleados.ver', 'proveedores.ver'])->assertSessionHasNoErrors();

        $auditoria = Auditoria::where('funcion_realizada', 'Cambio de permisos de usuario')->sole();
        $this->assertSame($actor->cedula, $auditoria->cedula_usu);
        $this->assertStringContainsString("Usuario {$cuenta->cedula}", $auditoria->detalle);
        $this->assertStringContainsString('agregó: proveedores.ver', $auditoria->detalle);
        $this->assertStringContainsString('quitó: simulador.usar', $auditoria->detalle);
        $this->assertSame(1, Auditoria::count());
    }

    public function test_cambiar_el_rol_deja_su_propia_auditoria(): void
    {
        $actor = Usuario::factory()->administrador()->create();
        $cuenta = Usuario::factory()->create();

        $this->editar($actor, $cuenta, 'ADMINISTRADOR', $cuenta->permisosAsignados())->assertSessionHasNoErrors();

        $auditoria = Auditoria::where('funcion_realizada', 'Cambio de rol de usuario')->sole();
        $this->assertStringContainsString("Usuario {$cuenta->cedula}", $auditoria->detalle);
        $this->assertStringContainsString('de FUNCIONARIO_BIENESTAR a ADMINISTRADOR', $auditoria->detalle);
    }

    public function test_pasar_a_un_administrador_a_funcionario_audita_el_rol_y_lo_exclusivo_quitado(): void
    {
        $actor = Usuario::factory()->administrador()->create();
        $otro = Usuario::factory()->administrador()->create();

        $this->editar($actor, $otro, 'FUNCIONARIO_BIENESTAR')->assertSessionHasNoErrors();

        $this->assertSame(1, Auditoria::where('funcion_realizada', 'Cambio de rol de usuario')->count());
        $permisos = Auditoria::where('funcion_realizada', 'Cambio de permisos de usuario')->sole();
        $this->assertStringContainsString('quitó: usuarios.gestionar, auditoria.ver', $permisos->detalle);
    }

    public function test_habilitar_y_deshabilitar_dejan_auditoria(): void
    {
        $actor = Usuario::factory()->administrador()->create();
        $cuenta = Usuario::factory()->create();

        $this->actingAs($actor)->patch(route('usuarios.deshabilitar', $cuenta));
        $this->actingAs($actor)->patch(route('usuarios.habilitar', $cuenta));

        $this->assertStringContainsString("Usuario {$cuenta->cedula}", Auditoria::where('funcion_realizada', 'Deshabilitación de usuario')->sole()->detalle);
        $this->assertStringContainsString("Usuario {$cuenta->cedula}", Auditoria::where('funcion_realizada', 'Habilitación de usuario')->sole()->detalle);
        $this->assertSame($actor->cedula, Auditoria::where('funcion_realizada', 'Habilitación de usuario')->sole()->cedula_usu);
    }

    public function test_si_no_cambia_nada_de_rol_ni_permisos_no_hay_auditoria(): void
    {
        $actor = Usuario::factory()->administrador()->create();
        $cuenta = Usuario::factory()->create();

        $this->editar($actor, $cuenta, 'FUNCIONARIO_BIENESTAR', $cuenta->permisosAsignados(), 'Nombre nuevo')->assertSessionHasNoErrors();

        $this->assertSame(0, Auditoria::count());
        $this->assertSame('Nombre nuevo', $cuenta->fresh()->nombre);
    }

    public function test_un_cambio_rechazado_no_deja_auditoria_ni_cambia_la_cuenta(): void
    {
        $actor = Usuario::factory()->administrador()->create();
        $funcionario = Usuario::factory()->create();
        $antes = $funcionario->permisosAsignados();

        $this->editar($actor, $funcionario, 'FUNCIONARIO_BIENESTAR', ['usuarios.gestionar'])->assertSessionHasErrors('permisos');

        $this->assertSame(0, Auditoria::count());
        $this->assertSame($antes, $funcionario->fresh()->permisosAsignados());
    }
}
