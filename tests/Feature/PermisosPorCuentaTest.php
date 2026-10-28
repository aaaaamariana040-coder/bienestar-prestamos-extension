<?php

namespace Tests\Feature;

use App\Enums\EstadoPrestamo;
use App\Enums\RolUsuario;
use App\Models\Auditoria;
use App\Models\DocumentoSolicitud;
use App\Models\Empleado;
use App\Models\Prestamo;
use App\Models\SolicitudCredito;
use App\Models\Usuario;
use App\Services\PermisosUsuarioService;
use Database\Seeders\SistemaUsuarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Permisos por cuenta: qué ve cada cuenta en el menú y en las pantallas, el formulario
 * de usuarios (casillas, defaults, Personalizado), el modelo y la migración que rellena
 * las cuentas existentes.
 */
class PermisosPorCuentaTest extends TestCase
{
    use RefreshDatabase;

    private function cuenta(array $permisos, bool $administrador = false): Usuario
    {
        $fabrica = Usuario::factory()->conPermisos($permisos);

        return ($administrador ? $fabrica->principal() : $fabrica)->create();
    }

    // ───────── Menú ─────────

    public function test_el_menu_muestra_solo_lo_que_la_cuenta_puede_usar(): void
    {
        $cuenta = $this->cuenta(['empleados.ver', 'solicitudes.ver', 'solicitudes.registrar']);

        $respuesta = $this->actingAs($cuenta)->get(route('inicio'))->assertOk();

        $respuesta->assertSee(route('empleados.index'), false);
        $respuesta->assertSee(route('solicitudes.index'), false);
        $respuesta->assertSee(route('solicitudes.create'), false);

        foreach (['empleados.create', 'usuarios.index', 'auditoria.index', 'simulador.index', 'prestamos.index', 'quincenas.index', 'reportes.cartera', 'tipos-prestamo.index', 'presupuestos.index', 'smmlv.index', 'proveedores.index'] as $ruta) {
            $respuesta->assertDontSee(route($ruta), false);
        }
    }

    public function test_una_cuenta_sin_permisos_ve_solo_el_inicio(): void
    {
        $respuesta = $this->actingAs(Usuario::factory()->sinPermisos()->create())->get(route('inicio'))->assertOk();

        foreach (['empleados.index', 'solicitudes.index', 'simulador.index', 'prestamos.index', 'quincenas.index', 'reportes.cartera', 'usuarios.index', 'auditoria.index'] as $ruta) {
            $respuesta->assertDontSee(route($ruta), false);
        }

        $respuesta->assertSee(route('password.edit'), false);
    }

    public function test_usuarios_y_auditoria_aparecen_en_el_menu_solo_del_administrador(): void
    {
        $this->actingAs(Usuario::factory()->principal()->create())->get(route('inicio'))
            ->assertSee(route('usuarios.index'), false)->assertSee(route('auditoria.index'), false);

        $this->actingAs(Usuario::factory()->create())->get(route('inicio'))
            ->assertDontSee(route('usuarios.index'), false)->assertDontSee(route('auditoria.index'), false);
    }

    public function test_el_aviso_de_quincenas_pendientes_solo_lo_ve_quien_puede_confirmar(): void
    {
        $sinConfirmar = $this->cuenta(['quincenas.ver']);
        $confirmador = $this->cuenta(['quincenas.ver', 'quincenas.confirmar']);

        $this->actingAs($sinConfirmar)->get(route('inicio'))->assertOk()->assertDontSee('Quincenas pendientes de confirmar');
        $this->actingAs($confirmador)->get(route('inicio'))->assertOk();
    }

    // ───────── Botones de las pantallas ─────────

    public function test_la_solicitud_muestra_aprobar_rechazar_documentos_y_borrado_solo_con_cada_permiso(): void
    {
        $solicitud = SolicitudCredito::factory()->create();
        $documento = DocumentoSolicitud::factory()->create(['id_solicitud' => $solicitud->id_solicitud]);

        $soloVer = $this->actingAs($this->cuenta(['solicitudes.ver']))->get(route('solicitudes.show', $solicitud))->assertOk();
        foreach ([route('solicitudes.aprobar', $solicitud), route('solicitudes.rechazar', $solicitud), route('documentos.store', $solicitud), 'action="'.route('documentos.destroy', $documento).'"', route('solicitudes.comite.previa', $solicitud)] as $url) {
            $soloVer->assertDontSee($url, false);
        }
        $soloVer->assertSee(route('documentos.download', $documento), false);

        $this->actingAs($this->cuenta(['solicitudes.ver', 'solicitudes.aprobar']))->get(route('solicitudes.show', $solicitud))
            ->assertSee(route('solicitudes.aprobar', $solicitud), false)
            ->assertDontSee(route('solicitudes.rechazar', $solicitud), false);

        $this->actingAs($this->cuenta(['solicitudes.ver', 'solicitudes.rechazar']))->get(route('solicitudes.show', $solicitud))
            ->assertSee(route('solicitudes.rechazar', $solicitud), false)
            ->assertDontSee(route('solicitudes.aprobar', $solicitud), false);

        $this->actingAs($this->cuenta(['solicitudes.ver', 'solicitudes.registrar']))->get(route('solicitudes.show', $solicitud))
            ->assertSee(route('documentos.store', $solicitud), false)
            ->assertDontSee('action="'.route('documentos.destroy', $documento).'"', false);

        $this->actingAs($this->cuenta(['solicitudes.ver', 'solicitudes.eliminar-documentos']))->get(route('solicitudes.show', $solicitud))
            ->assertSee('action="'.route('documentos.destroy', $documento).'"', false);

        $this->actingAs($this->cuenta(['solicitudes.ver', 'solicitudes.enviar-comite']))->get(route('solicitudes.show', $solicitud))
            ->assertSee(route('solicitudes.comite.previa', $solicitud), false);
    }

    public function test_el_prestamo_muestra_pago_abono_y_cancelacion_solo_con_cada_permiso(): void
    {
        $prestamo = Prestamo::factory()->create(['estado' => EstadoPrestamo::Vigente]);

        $soloVer = $this->actingAs($this->cuenta(['prestamos.ver']))->get(route('prestamos.show', $prestamo))->assertOk();
        foreach ([route('pagos.store-abono', $prestamo), route('pagos.store-cancelacion', $prestamo), route('desembolsos.store', $prestamo)] as $url) {
            $soloVer->assertDontSee($url, false);
        }

        $this->actingAs($this->cuenta(['prestamos.ver', 'pagos.registrar-abono']))->get(route('prestamos.show', $prestamo))
            ->assertSee(route('pagos.store-abono', $prestamo), false)
            ->assertDontSee(route('pagos.store-cancelacion', $prestamo), false);

        $this->actingAs($this->cuenta(['prestamos.ver', 'pagos.cancelar-prestamo']))->get(route('prestamos.show', $prestamo))
            ->assertSee(route('pagos.store-cancelacion', $prestamo), false)
            ->assertDontSee(route('pagos.store-abono', $prestamo), false);

        $this->actingAs($this->cuenta(['prestamos.ver', 'desembolsos.registrar']))->get(route('prestamos.show', $prestamo))
            ->assertSee(route('desembolsos.store', $prestamo), false);
    }

    public function test_empleados_muestra_crear_editar_y_eliminar_solo_con_cada_permiso(): void
    {
        $empleado = Empleado::factory()->create();

        $soloVer = $this->actingAs($this->cuenta(['empleados.ver']))->get(route('empleados.index'))->assertOk();
        $soloVer->assertDontSee(route('empleados.create'), false)->assertDontSee('href="'.route('empleados.edit', $empleado).'"', false)->assertDontSee('data-eliminar-action="'.route('empleados.destroy', $empleado).'"', false);

        $this->actingAs($this->cuenta(['empleados.ver', 'empleados.gestionar']))->get(route('empleados.index'))
            ->assertSee(route('empleados.create'), false)->assertSee('href="'.route('empleados.edit', $empleado).'"', false)->assertDontSee('data-eliminar-action="'.route('empleados.destroy', $empleado).'"', false);

        $this->actingAs($this->cuenta(['empleados.ver', 'empleados.eliminar']))->get(route('empleados.index'))
            ->assertSee('data-eliminar-action="'.route('empleados.destroy', $empleado).'"', false)->assertDontSee('href="'.route('empleados.edit', $empleado).'"', false);
    }

    public function test_un_cambio_de_permisos_rige_en_la_siguiente_peticion(): void
    {
        $cuenta = $this->cuenta(['empleados.ver']);
        $this->actingAs($cuenta)->get(route('empleados.index'))->assertOk();

        $cuenta->update(['permisos' => []]);

        $this->actingAs($cuenta->fresh())->get(route('empleados.index'))->assertForbidden();
    }

    // ───────── Modelo ─────────

    public function test_una_cuenta_deshabilitada_no_tiene_ningun_permiso(): void
    {
        $cuenta = Usuario::factory()->principal()->create(['habilitado' => false]);

        $this->assertFalse($cuenta->tienePermiso('usuarios.gestionar'));
        $this->assertFalse($cuenta->tienePermiso('empleados.ver'));
        $this->assertFalse($cuenta->puedeOperar());
    }

    public function test_los_exclusivos_del_administrador_no_cuentan_en_otro_rol_aunque_esten_guardados(): void
    {
        $funcionario = Usuario::factory()->conPermisos(['usuarios.gestionar', 'auditoria.ver', 'empleados.ver'])->create();

        $this->assertFalse($funcionario->tienePermiso('usuarios.gestionar'));
        $this->assertFalse($funcionario->tienePermiso('auditoria.ver'));
        $this->assertSame(['empleados.ver'], $funcionario->permisosAsignados());
        $this->actingAs($funcionario)->get(route('usuarios.index'))->assertForbidden();
    }

    public function test_los_permisos_desconocidos_se_ignoran(): void
    {
        $cuenta = Usuario::factory()->conPermisos(['empleados.ver', 'permiso.viejo'])->create();

        $this->assertSame(['empleados.ver'], $cuenta->permisosAsignados());
        $this->assertFalse($cuenta->tienePermiso('permiso.viejo'));
    }

    public function test_puede_operar_significa_tener_algun_permiso_operativo(): void
    {
        $this->assertTrue(Usuario::factory()->create()->puedeOperar());
        $this->assertTrue($this->cuenta(['simulador.usar'])->puedeOperar());
        $this->assertFalse(Usuario::factory()->sinPermisos()->create()->puedeOperar());
        $this->assertFalse($this->cuenta(['usuarios.gestionar', 'auditoria.ver'], administrador: true)->puedeOperar());
    }

    public function test_una_cuenta_que_nace_sin_lista_recibe_los_permisos_de_su_perfil(): void
    {
        $creada = Usuario::create(['cedula' => '9990001', 'nombre' => 'Persona', 'apellido' => 'Ficticia', 'contrasena_hash' => 'x', 'rol' => RolUsuario::FuncionarioBienestar, 'habilitado' => true]);
        $administrador = Usuario::create(['cedula' => '9990002', 'nombre' => 'Persona', 'apellido' => 'Ficticia', 'contrasena_hash' => 'x', 'rol' => RolUsuario::Administrador, 'habilitado' => true, 'es_principal' => true]);
        $vacia = Usuario::create(['cedula' => '9990003', 'nombre' => 'Persona', 'apellido' => 'Ficticia', 'contrasena_hash' => 'x', 'rol' => RolUsuario::FuncionarioBienestar, 'permisos' => [], 'habilitado' => true]);

        $this->assertContains('solicitudes.aprobar', $creada->fresh()->permisosAsignados());
        $this->assertNotContains('usuarios.gestionar', $creada->fresh()->permisosAsignados());
        $this->assertContains('usuarios.gestionar', $administrador->fresh()->permisosAsignados());
        $this->assertSame([], $vacia->fresh()->permisosAsignados());
    }

    public function test_el_usuario_sistema_no_tiene_permisos(): void
    {
        $this->seed(SistemaUsuarioSeeder::class);

        $sistema = Usuario::sistema();

        $this->assertSame([], $sistema->permisos);
        $this->assertFalse($sistema->tienePermiso('empleados.ver'));
    }

    public function test_la_factory_tiene_estados_sin_permisos_y_con_permisos(): void
    {
        $this->assertSame([], Usuario::factory()->sinPermisos()->create()->permisosAsignados());
        $this->assertSame(['empleados.ver', 'empleados.eliminar'], Usuario::factory()->conPermisos(['empleados.ver', 'empleados.eliminar'])->create()->permisosAsignados());
    }

    // ───────── Formulario de usuarios ─────────

    public function test_el_formulario_de_crear_muestra_las_casillas_agrupadas_marcadas_segun_el_perfil(): void
    {
        $administrador = Usuario::factory()->principal()->create();

        $respuesta = $this->actingAs($administrador)->get(route('usuarios.create'))->assertOk();
        $respuesta->assertSee('Permisos de la cuenta')->assertSee('name="permisos_enviados"', false)
            ->assertSee('Gestionar usuarios')->assertSee('Aprobar solicitudes')->assertSee('Cancelar un préstamo (cancelación total)')
            ->assertDontSee('Restablecer a los permisos del perfil');
        // Una cuenta nueva nunca es la principal: Usuarios y Auditoría salen deshabilitadas y sin marcar.
        $this->assertMatchesRegularExpression('/value="usuarios\.gestionar"[^>]*disabled/s', $respuesta->getContent());
        $this->assertDoesNotMatchRegularExpression('/value="usuarios\.gestionar"[^>]*checked/s', $respuesta->getContent());
        $respuesta->assertSee('Solo el administrador principal gestiona usuarios y auditoría.');

        $comoFuncionario = $this->actingAs($administrador)->withSession(['_old_input' => ['rol' => 'FUNCIONARIO_BIENESTAR']])->get(route('usuarios.create'))->getContent();
        $this->assertDoesNotMatchRegularExpression('/value="usuarios\.gestionar"[^>]*checked/s', $comoFuncionario);
        $this->assertMatchesRegularExpression('/value="solicitudes\.aprobar"[^>]*checked/s', $comoFuncionario);
    }

    public function test_crear_un_usuario_sin_casillas_usa_los_permisos_del_perfil(): void
    {
        $this->actingAs(Usuario::factory()->principal()->create())->post(route('usuarios.store'), $this->datosNuevoUsuario('FUNCIONARIO_BIENESTAR'))->assertRedirect();

        $this->assertSame(
            app(PermisosUsuarioService::class)->defaultsPara(RolUsuario::FuncionarioBienestar),
            Usuario::findOrFail('5550001')->permisosAsignados(),
        );
    }

    public function test_crear_un_usuario_con_casillas_guarda_exactamente_las_marcadas_mas_lo_que_exigen(): void
    {
        $this->actingAs(Usuario::factory()->principal()->create())->post(route('usuarios.store'), $this->datosNuevoUsuario('FUNCIONARIO_BIENESTAR') + [
            'permisos_enviados' => 1,
            'permisos' => ['solicitudes.registrar', 'simulador.usar'],
        ])->assertRedirect();

        $this->assertSame(['solicitudes.ver', 'solicitudes.registrar', 'simulador.usar'], Usuario::findOrFail('5550001')->permisosAsignados());
    }

    public function test_crear_un_usuario_sin_ninguna_casilla_marcada_lo_deja_sin_permisos(): void
    {
        $this->actingAs(Usuario::factory()->principal()->create())->post(route('usuarios.store'), $this->datosNuevoUsuario('FUNCIONARIO_BIENESTAR') + ['permisos_enviados' => 1])->assertRedirect();

        $this->assertSame([], Usuario::findOrFail('5550001')->permisosAsignados());
    }

    public function test_un_permiso_exclusivo_del_administrador_se_rechaza_para_otro_rol(): void
    {
        $this->actingAs(Usuario::factory()->principal()->create())->post(route('usuarios.store'), $this->datosNuevoUsuario('FUNCIONARIO_BIENESTAR') + [
            'permisos_enviados' => 1,
            'permisos' => ['usuarios.gestionar', 'empleados.ver'],
        ])->assertSessionHasErrors('permisos');

        $this->assertNull(Usuario::find('5550001'));
        $this->assertSame(0, Auditoria::count());
    }

    public function test_un_permiso_que_no_existe_se_rechaza(): void
    {
        $this->actingAs(Usuario::factory()->principal()->create())->post(route('usuarios.store'), $this->datosNuevoUsuario('ADMINISTRADOR') + [
            'permisos_enviados' => 1,
            'permisos' => ['permiso.inventado'],
        ])->assertSessionHasErrors('permisos.0');

        $this->assertNull(Usuario::find('5550001'));
    }

    public function test_un_administrador_nuevo_no_recibe_usuarios_ni_auditoria_aunque_lleguen_en_la_peticion(): void
    {
        $this->actingAs(Usuario::factory()->principal()->create())->post(route('usuarios.store'), $this->datosNuevoUsuario('ADMINISTRADOR') + [
            'permisos_enviados' => 1,
            'permisos' => ['usuarios.gestionar', 'auditoria.ver', 'empleados.ver'],
        ])->assertRedirect();

        $nuevo = Usuario::findOrFail('5550001');
        $this->assertSame(['empleados.ver'], $nuevo->permisosAsignados());
        $this->assertNotContains('usuarios.gestionar', (array) $nuevo->permisos);
        $this->assertFalse($nuevo->esPrincipal());
    }

    public function test_editar_sin_casillas_conserva_los_permisos_aunque_cambie_el_nombre(): void
    {
        $administrador = Usuario::factory()->principal()->create();
        $cuenta = $this->cuenta(['empleados.ver', 'simulador.usar']);

        $this->actingAs($administrador)->put(route('usuarios.update', $cuenta), ['nombre' => 'Nuevo', 'apellido' => 'Nombre', 'rol' => 'FUNCIONARIO_BIENESTAR'])->assertRedirect(route('usuarios.index'));

        $this->assertSame(['empleados.ver', 'simulador.usar'], $cuenta->fresh()->permisosAsignados());
        $this->assertSame('Nuevo', $cuenta->fresh()->nombre);
    }

    public function test_cambiar_el_rol_al_editar_no_sobrescribe_las_casillas(): void
    {
        $administrador = Usuario::factory()->principal()->create();
        $cuenta = $this->cuenta(['empleados.ver', 'simulador.usar']);

        $this->actingAs($administrador)->put(route('usuarios.update', $cuenta), ['nombre' => 'A', 'apellido' => 'B', 'rol' => 'ADMINISTRADOR'])->assertRedirect();

        $this->assertSame(RolUsuario::Administrador, $cuenta->fresh()->rol);
        $this->assertSame(['empleados.ver', 'simulador.usar'], $cuenta->fresh()->permisosAsignados());
    }

    public function test_pasar_a_un_administrador_a_otro_rol_sin_casillas_le_quita_lo_exclusivo(): void
    {
        $administrador = Usuario::factory()->principal()->create();
        $otro = Usuario::factory()->administrador()->create();

        $this->actingAs($administrador)->put(route('usuarios.update', $otro), ['nombre' => 'A', 'apellido' => 'B', 'rol' => 'FUNCIONARIO_BIENESTAR'])->assertRedirect();

        $this->assertNotContains('usuarios.gestionar', $otro->fresh()->permisosAsignados());
        $this->assertNotContains('auditoria.ver', $otro->fresh()->permisosAsignados());
        $this->assertContains('solicitudes.aprobar', $otro->fresh()->permisosAsignados());
    }

    public function test_la_pantalla_de_editar_muestra_restablecer_y_la_etiqueta_personalizado_solo_si_difiere(): void
    {
        $administrador = Usuario::factory()->principal()->create();
        $personalizada = $this->cuenta(['empleados.ver']);
        $delPerfil = Usuario::factory()->create();

        $conEtiqueta = $this->actingAs($administrador)->get(route('usuarios.edit', $personalizada))->assertOk()
            ->assertSee('Restablecer a los permisos del perfil')->assertSee('id="restablecer-permisos"', false);
        $this->assertMatchesRegularExpression('/id="etiqueta-personalizado" class="badge text-bg-warning ms-2 "/', $conEtiqueta->getContent());

        $sinEtiqueta = $this->actingAs($administrador)->get(route('usuarios.edit', $delPerfil))->getContent();
        $this->assertMatchesRegularExpression('/id="etiqueta-personalizado" class="badge text-bg-warning ms-2 d-none"/', $sinEtiqueta);
    }

    public function test_la_pantalla_de_editar_marca_las_casillas_de_la_cuenta_y_el_script_no_toca_el_rol_al_editar(): void
    {
        $administrador = Usuario::factory()->principal()->create();
        $cuenta = $this->cuenta(['empleados.ver', 'simulador.usar']);

        $html = $this->actingAs($administrador)->get(route('usuarios.edit', $cuenta))->getContent();

        $this->assertMatchesRegularExpression('/value="empleados\.ver"[^>]*checked/s', $html);
        $this->assertMatchesRegularExpression('/value="simulador\.usar"[^>]*checked/s', $html);
        $this->assertDoesNotMatchRegularExpression('/value="solicitudes\.aprobar"[^>]*checked/s', $html);
        $this->assertStringContainsString('const modo = "editar";', $html);
    }

    public function test_el_listado_marca_como_personalizadas_solo_las_cuentas_que_difieren_del_perfil(): void
    {
        $administrador = Usuario::factory()->principal()->create();
        $this->cuenta(['empleados.ver']);

        $html = $this->actingAs($administrador)->get(route('usuarios.index'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'Sus permisos difieren de los del perfil'));
    }

    public function test_restablecer_a_los_defaults_del_perfil_deja_la_cuenta_sin_etiqueta(): void
    {
        $administrador = Usuario::factory()->principal()->create();
        $cuenta = $this->cuenta(['empleados.ver']);
        $defaults = app(PermisosUsuarioService::class)->defaultsPara(RolUsuario::FuncionarioBienestar);

        $this->actingAs($administrador)->put(route('usuarios.update', $cuenta), ['nombre' => 'A', 'apellido' => 'B', 'rol' => 'FUNCIONARIO_BIENESTAR', 'permisos_enviados' => 1, 'permisos' => $defaults])->assertRedirect();

        $this->assertFalse(app(PermisosUsuarioService::class)->esPersonalizado($cuenta->fresh()));
    }

    // ───────── Migración ─────────

    public function test_la_migracion_rellena_las_cuentas_existentes_segun_su_rol(): void
    {
        $this->artisan('migrate:rollback', ['--path' => 'database/migrations/2026_10_26_100100_agrega_permisos_de_seguros_a_usuarios.php'])->assertSuccessful();
        $this->artisan('migrate:rollback', ['--path' => 'database/migrations/2026_10_22_100000_agrega_permisos_a_usuarios_table.php'])->assertSuccessful();

        $fila = fn (string $cedula, string $rol, bool $habilitado = true): array => [
            'cedula' => $cedula, 'nombre' => 'Persona', 'apellido' => 'Ficticia', 'contrasena_hash' => 'x',
            'rol' => $rol, 'habilitado' => $habilitado, 'created_at' => now(), 'updated_at' => now(),
        ];
        DB::table('usuarios')->insert([
            $fila('7770001', 'ADMINISTRADOR'),
            $fila('7770002', 'FUNCIONARIO_BIENESTAR'),
            $fila('0000000000', 'FUNCIONARIO_BIENESTAR', false),
        ]);

        $this->artisan('migrate')->assertSuccessful();

        $administrador = Usuario::findOrFail('7770001');
        $funcionario = Usuario::findOrFail('7770002');
        $todos = array_keys(config('permisos.catalogo'));

        // Esta migración es anterior a «principal»: el administrador no lo es y no recibe Usuarios ni Auditoría.
        $this->assertEqualsCanonicalizing(array_values(array_diff($todos, ['usuarios.gestionar', 'auditoria.ver'])), $administrador->permisosAsignados());
        $this->assertEqualsCanonicalizing(array_values(array_diff($todos, ['usuarios.gestionar', 'auditoria.ver', 'seguros.gestionar'])), $funcionario->permisosAsignados());
        $this->assertSame([], Usuario::findOrFail('0000000000')->permisos);
    }

    /**
     * @return array<string, string>
     */
    private function datosNuevoUsuario(string $rol): array
    {
        return ['cedula' => '5550001', 'nombre' => 'Persona', 'apellido' => 'Ficticia', 'password' => 'Clave12345', 'password_confirmation' => 'Clave12345', 'rol' => $rol];
    }
}
