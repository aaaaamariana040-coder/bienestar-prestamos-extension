<?php

namespace Tests\Feature;

use App\Enums\RolUsuario;
use App\Models\Usuario;
use App\Services\PermisosUsuarioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\RecorreLasRutas;
use Tests\TestCase;

/**
 * El catálogo de permisos (config/permisos.php), sus defaults y la cobertura de las
 * rutas: ninguna ruta de la aplicación queda sin permiso, y cada permiso protege algo.
 */
class PermisosCatalogoTest extends TestCase
{
    use RecorreLasRutas;
    use RefreshDatabase;

    /**
     * Rutas con nombre que no llevan «can:» a propósito: el inicio y el cambio de la
     * propia contraseña (cualquier cuenta habilitada puede usarlos).
     */
    private const NOMBRES_SIN_PERMISO = ['inicio', 'password.edit', 'password.update', 'login', 'logout'];

    /**
     * Rutas sin nombre permitidas: la portada, el inicio de sesión (POST) y la salud.
     */
    private const URIS_SIN_NOMBRE = ['/', 'login', 'up'];

    private function permisos(): PermisosUsuarioService
    {
        return app(PermisosUsuarioService::class);
    }

    // ───────── Catálogo ─────────

    public function test_cada_permiso_tiene_grupo_nombre_y_descripcion(): void
    {
        foreach ($this->permisos()->catalogo() as $permiso => $datos) {
            $this->assertMatchesRegularExpression('/^[a-z]+(\.[a-z-]+)$/', $permiso);

            foreach (['grupo', 'nombre', 'descripcion'] as $campo) {
                $this->assertNotEmpty($datos[$campo] ?? null, "El permiso {$permiso} no tiene {$campo}.");
            }
        }
    }

    public function test_las_dependencias_y_los_exclusivos_apuntan_a_permisos_del_catalogo(): void
    {
        $catalogo = $this->permisos()->nombres();

        foreach (config('permisos.dependencias') as $permiso => $exigidos) {
            $this->assertContains($permiso, $catalogo);
            $this->assertNotContains($permiso, $exigidos, 'Un permiso no puede exigirse a sí mismo.');

            foreach ($exigidos as $exigido) {
                $this->assertContains($exigido, $catalogo);
            }
        }

        foreach ($this->permisos()->soloAdministrador() as $permiso) {
            $this->assertContains($permiso, $catalogo);
        }

        $this->assertSame(['usuarios.gestionar', 'auditoria.ver'], $this->permisos()->soloAdministrador());
    }

    public function test_gestionar_implica_ver_en_el_catalogo(): void
    {
        foreach (['empleados', 'modalidades', 'presupuesto', 'smmlv', 'proveedores'] as $modulo) {
            $this->assertSame(["{$modulo}.ver"], config('permisos.dependencias')["{$modulo}.gestionar"]);
        }

        $this->assertSame(['empleados.ver', 'empleados.eliminar', 'solicitudes.ver', 'solicitudes.registrar'], $this->permisos()->normalizar(['solicitudes.registrar', 'empleados.eliminar']));
    }

    public function test_normalizar_descarta_lo_desconocido_agrega_dependencias_y_ordena_segun_el_catalogo(): void
    {
        $normalizados = $this->permisos()->normalizar(['pagos.registrar-abono', 'no.existe', 'prestamos.ver', 'pagos.registrar-abono', 'empleados.gestionar']);

        $this->assertSame(['empleados.ver', 'empleados.gestionar', 'prestamos.ver', 'pagos.registrar-abono'], $normalizados);
    }

    // ───────── Defaults ─────────

    public function test_los_defaults_del_funcionario_son_el_catalogo_menos_usuarios_auditoria_y_gestionar_seguros(): void
    {
        $esperados = array_values(array_diff($this->permisos()->nombres(), ['usuarios.gestionar', 'auditoria.ver', 'seguros.gestionar']));

        $this->assertSame($esperados, $this->permisos()->defaultsPara(RolUsuario::FuncionarioBienestar));
        $this->assertSame($esperados, Usuario::factory()->create()->permisosAsignados());
        $this->assertCount(count($this->permisos()->nombres()) - 3, $esperados);
    }

    public function test_los_defaults_del_administrador_son_todos_los_permisos(): void
    {
        $this->assertSame($this->permisos()->nombres(), $this->permisos()->defaultsPara(RolUsuario::Administrador));
        $this->assertSame($this->permisos()->nombres(), Usuario::factory()->principal()->create()->permisosAsignados());
    }

    public function test_un_administrador_que_no_es_el_principal_no_tiene_usuarios_ni_auditoria(): void
    {
        $esperados = array_values(array_diff($this->permisos()->nombres(), $this->permisos()->soloAdministrador()));

        $this->assertSame($esperados, Usuario::factory()->administrador()->create()->permisosAsignados());
        $this->assertSame($esperados, $this->permisos()->defaultsPara(RolUsuario::Administrador, false));
    }

    public function test_los_defaults_se_cambian_en_un_solo_lugar_y_solo_afectan_a_cuentas_nuevas(): void
    {
        $existente = Usuario::factory()->create();

        config(['permisos.defaults.FUNCIONARIO_BIENESTAR' => ['*', '!usuarios.gestionar', '!auditoria.ver', '!pagos.cancelar-prestamo']]);

        $nueva = Usuario::factory()->create();

        $this->assertNotContains('pagos.cancelar-prestamo', $this->permisos()->defaultsPara(RolUsuario::FuncionarioBienestar));
        $this->assertNotContains('pagos.cancelar-prestamo', $nueva->permisosAsignados());
        $this->assertContains('pagos.cancelar-prestamo', $existente->fresh()->permisosAsignados(), 'Las cuentas existentes conservan su propia lista.');
    }

    public function test_la_sintaxis_de_los_defaults_acepta_nombres_sueltos_y_exclusiones(): void
    {
        config(['permisos.defaults.FUNCIONARIO_BIENESTAR' => ['solicitudes.registrar', 'simulador.usar']]);

        $this->assertSame(['solicitudes.ver', 'solicitudes.registrar', 'simulador.usar'], $this->permisos()->defaultsPara('FUNCIONARIO_BIENESTAR'));
        $this->assertSame([], $this->permisos()->defaultsPara('PERFIL_QUE_NO_EXISTE'));
    }

    public function test_un_perfil_distinto_del_administrador_nunca_recibe_los_exclusivos_aunque_el_default_los_pida(): void
    {
        config(['permisos.defaults.FUNCIONARIO_BIENESTAR' => ['*']]);

        $this->assertNotContains('usuarios.gestionar', $this->permisos()->defaultsPara(RolUsuario::FuncionarioBienestar));
        $this->assertNotContains('auditoria.ver', $this->permisos()->defaultsPara(RolUsuario::FuncionarioBienestar));
    }

    // ───────── Gates y rutas ─────────

    public function test_hay_un_gate_por_cada_permiso_del_catalogo(): void
    {
        foreach ($this->permisos()->nombres() as $permiso) {
            $this->assertTrue(Gate::has($permiso), "Falta el Gate {$permiso}.");
        }
    }

    public function test_toda_ruta_tiene_un_permiso_o_esta_en_la_lista_permitida(): void
    {
        $sinProteger = [];

        foreach (Route::getRoutes()->getRoutes() as $ruta) {
            $nombre = $ruta->getName();

            if ($nombre !== null && (str_starts_with($nombre, 'storage.') || str_starts_with($nombre, 'ignition.'))) {
                continue;
            }

            if ($this->permisoDeLaRuta($ruta) !== null) {
                continue;
            }

            $permitida = $nombre === null
                ? in_array($ruta->uri(), self::URIS_SIN_NOMBRE, true)
                : in_array($nombre, self::NOMBRES_SIN_PERMISO, true);

            if (! $permitida) {
                $sinProteger[] = ($nombre ?? $ruta->uri()).' ['.implode('|', $ruta->methods()).']';
            }
        }

        $this->assertSame([], $sinProteger, 'Rutas sin middleware can: ni lugar en la lista permitida.');
    }

    public function test_las_rutas_de_la_lista_permitida_son_solo_de_sesion_o_publicas(): void
    {
        foreach (['inicio', 'password.edit', 'password.update'] as $nombre) {
            $ruta = Route::getRoutes()->getByName($nombre);

            $this->assertContains('auth', $ruta->gatherMiddleware());
            $this->assertContains('habilitado', $ruta->gatherMiddleware());
        }
    }

    public function test_todo_permiso_de_una_ruta_existe_en_el_catalogo(): void
    {
        foreach (Route::getRoutes()->getRoutes() as $ruta) {
            $permiso = $this->permisoDeLaRuta($ruta);

            if ($permiso !== null) {
                $this->assertContains($permiso, $this->permisos()->nombres(), "La ruta {$ruta->getName()} exige un permiso que no está en el catálogo: {$permiso}.");
            }
        }
    }

    public function test_cada_permiso_del_catalogo_protege_al_menos_una_ruta(): void
    {
        $usados = [];

        foreach (Route::getRoutes()->getRoutes() as $ruta) {
            if (($permiso = $this->permisoDeLaRuta($ruta)) !== null) {
                $usados[$permiso] = true;
            }
        }

        $this->assertSame([], array_values(array_diff($this->permisos()->nombres(), array_keys($usados))), 'Permisos del catálogo que no protegen ninguna ruta.');
    }

    public function test_sin_el_permiso_cada_ruta_responde_403_y_con_solo_el_permiso_pasa(): void
    {
        $todos = $this->permisos()->nombres();
        $revisadas = 0;
        $principal = null;

        foreach ($this->rutasDeLaAplicacion() as $ruta) {
            $permiso = $this->permisoDeLaRuta($ruta);

            if ($permiso === null) {
                continue;
            }

            $revisadas++;
            $sinEl = Usuario::factory()->administrador()->conPermisos(array_values(array_diff($todos, [$permiso])))->create();

            // Usuarios y Auditoría solo los tiene el administrador principal (el único que existe).
            $soloEl = in_array($permiso, $this->permisos()->soloAdministrador(), true)
                ? ($principal ??= Usuario::factory()->principal()->create())
                : Usuario::factory()->administrador()->conPermisos([$permiso])->create();

            $this->assertSame(403, $this->estadoDeLaRuta($sinEl, $ruta), "{$ruta->getName()}: sin «{$permiso}» debería dar 403.");
            $this->assertNotSame(403, $this->estadoDeLaRuta($soloEl, $ruta), "{$ruta->getName()}: con solo «{$permiso}» no debería dar 403.");
        }

        $this->assertGreaterThan(75, $revisadas);
    }

    public function test_una_cuenta_sin_permisos_solo_entra_al_inicio_y_a_su_contrasena(): void
    {
        $sinPermisos = Usuario::factory()->sinPermisos()->create();
        $pasaron = [];

        foreach ($this->rutasDeLaAplicacion() as $ruta) {
            $estado = $this->estadoDeLaRuta($sinPermisos, $ruta);

            if ($estado !== 403) {
                $pasaron[] = $ruta->getName();
            }
        }

        $this->assertSame(['inicio', 'password.edit', 'password.update'], $pasaron);
    }

    public function test_un_formulario_con_datos_invalidos_tampoco_pasa_sin_el_permiso(): void
    {
        $funcionario = Usuario::factory()->create();

        $this->actingAs($funcionario)->post(route('usuarios.store'), [])->assertForbidden();
        $this->actingAs(Usuario::factory()->conPermisos(['empleados.ver'])->create())->post(route('empleados.store'), [])->assertForbidden();
        $this->actingAs(Usuario::factory()->conPermisos(['reportes.ver'])->create())->post(route('informes.store'), [])->assertForbidden();
        $this->actingAs($funcionario)->post(route('informes.store'), [])->assertStatus(302)->assertSessionHasErrors();
    }
}
