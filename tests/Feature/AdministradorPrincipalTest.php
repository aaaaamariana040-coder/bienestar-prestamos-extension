<?php

namespace Tests\Feature;

use App\Enums\RolUsuario;
use App\Models\Auditoria;
use App\Models\Empleado;
use App\Models\Usuario;
use App\Services\CambioDeUsuarioNoPermitidoException;
use App\Services\UsuarioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Usuarios y Auditoría son exclusivos del administrador principal (usuarios.es_principal),
 * la columna «Función» de la auditoría ahora se llama «Acción» y el formulario de empleados
 * limita la cédula a dígitos y el nombre a letras.
 */
class AdministradorPrincipalTest extends TestCase
{
    use RefreshDatabase;

    // ───────── Administrador principal ─────────

    public function test_el_principal_tiene_usuarios_y_auditoria_aunque_su_lista_guardada_no_los_traiga(): void
    {
        $principal = Usuario::factory()->principal()->conPermisos(['empleados.ver'])->create();

        $this->assertTrue($principal->tienePermiso('usuarios.gestionar'));
        $this->assertTrue($principal->tienePermiso('auditoria.ver'));
        $this->actingAs($principal)->get(route('usuarios.index'))->assertOk();
        $this->actingAs($principal)->get(route('auditoria.index'))->assertOk();
    }

    public function test_el_formulario_del_principal_muestra_usuarios_y_auditoria_marcados_y_bloqueados(): void
    {
        $principal = Usuario::factory()->principal()->create();

        $html = $this->actingAs($principal)->get(route('usuarios.edit', $principal))->assertOk()->getContent();

        foreach (['usuarios\.gestionar', 'auditoria\.ver'] as $permiso) {
            $this->assertMatchesRegularExpression('/value="'.$permiso.'"[^>]*checked[^>]*disabled|value="'.$permiso.'"[^>]*disabled[^>]*checked/s', $html);
        }

        $this->assertStringContainsString('Fijo: pertenece al administrador principal.', $html);
        $this->assertStringContainsString('<input type="hidden" name="rol" value="ADMINISTRADOR">', $html);
    }

    public function test_el_servidor_no_deja_quitarle_usuarios_y_auditoria_al_principal_aunque_manipulen_la_peticion(): void
    {
        $principal = Usuario::factory()->principal()->create();

        $this->actingAs($principal)->put(route('usuarios.update', $principal), [
            'nombre' => 'Nuevo',
            'apellido' => 'Nombre',
            'rol' => 'ADMINISTRADOR',
            'permisos_enviados' => 1,
            'permisos' => array_values(array_diff($principal->permisosAsignados(), ['usuarios.gestionar', 'auditoria.ver'])),
        ])->assertSessionHasNoErrors();

        $this->assertTrue($principal->fresh()->tienePermiso('usuarios.gestionar'));
        $this->assertTrue($principal->fresh()->tienePermiso('auditoria.ver'));
    }

    public function test_un_administrador_que_no_es_el_principal_ve_usuarios_y_auditoria_deshabilitados_con_la_nota(): void
    {
        $principal = Usuario::factory()->principal()->create();
        $otro = Usuario::factory()->administrador()->create();

        $html = $this->actingAs($principal)->get(route('usuarios.edit', $otro))->assertOk()->getContent();

        foreach (['usuarios\.gestionar', 'auditoria\.ver'] as $permiso) {
            $this->assertMatchesRegularExpression('/value="'.$permiso.'"[^>]*disabled/s', $html);
            $this->assertDoesNotMatchRegularExpression('/value="'.$permiso.'"[^>]*checked/s', $html);
        }

        $this->assertStringContainsString('Solo el administrador principal gestiona usuarios y auditoría.', $html);
    }

    public function test_el_servidor_ignora_usuarios_y_auditoria_si_llegan_para_un_administrador_que_no_es_el_principal(): void
    {
        $principal = Usuario::factory()->principal()->create();
        $otro = Usuario::factory()->administrador()->create();

        $this->actingAs($principal)->put(route('usuarios.update', $otro), [
            'nombre' => 'Otro',
            'apellido' => 'Administrador',
            'rol' => 'ADMINISTRADOR',
            'permisos_enviados' => 1,
            'permisos' => ['usuarios.gestionar', 'auditoria.ver', 'empleados.ver'],
        ])->assertSessionHasNoErrors();

        $this->assertSame(['empleados.ver'], $otro->fresh()->permisosAsignados());
        $this->assertFalse($otro->fresh()->tienePermiso('usuarios.gestionar'));
    }

    public function test_un_administrador_que_no_es_el_principal_no_accede_a_usuarios_ni_a_auditoria(): void
    {
        $otro = Usuario::factory()->administrador()->create();

        $this->actingAs($otro)->get(route('usuarios.index'))->assertForbidden();
        $this->actingAs($otro)->get(route('usuarios.create'))->assertForbidden();
        $this->actingAs($otro)->get(route('auditoria.index'))->assertForbidden();
        $this->actingAs($otro)->get(route('empleados.index'))->assertOk();
    }

    public function test_el_menu_de_un_administrador_que_no_es_el_principal_no_muestra_usuarios_ni_auditoria(): void
    {
        $this->actingAs(Usuario::factory()->administrador()->create())->get(route('empleados.index'))
            ->assertOk()
            ->assertDontSee(route('usuarios.index'), false)
            ->assertDontSee(route('auditoria.index'), false);

        $this->actingAs(Usuario::factory()->principal()->create())->get(route('empleados.index'))
            ->assertSee(route('usuarios.index'), false)
            ->assertSee(route('auditoria.index'), false);
    }

    public function test_el_principal_no_se_puede_deshabilitar(): void
    {
        $principal = Usuario::factory()->principal()->create();
        $otro = Usuario::factory()->administrador()->create();

        $this->actingAs($principal)->patch(route('usuarios.deshabilitar', $principal))->assertForbidden();
        $this->actingAs($principal)->patch(route('usuarios.deshabilitar', $otro))->assertForbidden();

        $this->assertTrue($principal->fresh()->habilitado);

        $this->expectException(CambioDeUsuarioNoPermitidoException::class);
        $this->expectExceptionMessage('El administrador principal no se puede deshabilitar.');

        app(UsuarioService::class)->deshabilitar($principal, $otro);
    }

    public function test_el_principal_no_puede_dejar_de_ser_administrador(): void
    {
        $principal = Usuario::factory()->principal()->create();

        $this->actingAs($principal)->put(route('usuarios.update', $principal), [
            'nombre' => 'A',
            'apellido' => 'B',
            'rol' => 'FUNCIONARIO_BIENESTAR',
        ])->assertSessionHasErrors(['permisos' => 'El administrador principal no puede dejar de ser Administrador.']);

        $this->assertSame(RolUsuario::Administrador, $principal->fresh()->rol);
        $this->assertTrue($principal->fresh()->esPrincipal());
    }

    public function test_solo_puede_haber_un_principal(): void
    {
        Usuario::factory()->principal()->create();

        $this->expectException(LogicException::class);

        Usuario::factory()->principal()->create();
    }

    public function test_la_migracion_marca_un_solo_principal_el_administrador_activo_mas_antiguo(): void
    {
        $this->artisan('migrate:rollback', ['--path' => 'database/migrations/2026_10_29_110000_agrega_es_principal_a_usuarios_table.php'])->assertSuccessful();

        $fila = fn (string $cedula, string $rol, string $creada, bool $habilitado = true): array => [
            'cedula' => $cedula, 'nombre' => 'Persona', 'apellido' => 'Ficticia', 'contrasena_hash' => 'x',
            'rol' => $rol, 'habilitado' => $habilitado, 'created_at' => $creada, 'updated_at' => $creada,
        ];
        DB::table('usuarios')->insert([
            $fila('0000000000', 'ADMINISTRADOR', '2020-01-01 00:00:00'),
            $fila('7770001', 'ADMINISTRADOR', '2021-01-01 00:00:00', false),
            $fila('7770002', 'ADMINISTRADOR', '2022-01-01 00:00:00'),
            $fila('7770003', 'ADMINISTRADOR', '2023-01-01 00:00:00'),
            $fila('7770004', 'FUNCIONARIO_BIENESTAR', '2019-01-01 00:00:00'),
        ]);

        $this->artisan('migrate')->assertSuccessful();

        $this->assertSame(['7770002'], Usuario::where('es_principal', true)->pluck('cedula')->all());
    }

    // ───────── «Acción» en la auditoría ─────────

    public function test_la_auditoria_dice_accion_y_no_funcion(): void
    {
        $principal = Usuario::factory()->principal()->create();
        Auditoria::create(['cedula_usu' => $principal->cedula, 'fecha' => now()->toDateString(), 'hora' => now()->format('H:i:s'), 'funcion_realizada' => 'Login', 'detalle' => 'x']);

        $respuesta = $this->actingAs($principal)->get(route('auditoria.index'))->assertOk();

        $respuesta->assertSee('<label for="funcion_realizada" class="form-label">Acción</label>', false)
            ->assertSee('<th>Acción</th>', false)
            ->assertDontSee('Función');
    }

    // ───────── Formulario de empleados ─────────

    /**
     * @param  array<string, mixed>  $sobrescribir
     * @return array<string, mixed>
     */
    private function datosEmpleado(array $sobrescribir = []): array
    {
        return array_merge([
            'cedula' => '900000001',
            'nombre_completo' => 'Ana Pérez',
            'tipo_vinculacion' => 'vinculado',
            'salario' => 2_000_000,
            'estado' => 'Activo',
            'capacidad_endeudamiento' => '',
        ], $sobrescribir);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function cedulasInvalidas(): array
    {
        return ['letras' => ['12345abc'], 'puntos' => ['1.234.567'], 'guion' => ['123-456'], 'símbolos' => ['123#456'], 'espacio y letra' => ['12 3a'], 'unicode' => ['１２３４５']];
    }

    #[DataProvider('cedulasInvalidas')]
    public function test_la_cedula_con_letras_o_simbolos_se_rechaza_en_el_servidor(string $cedula): void
    {
        $this->actingAs(Usuario::factory()->create())->post(route('empleados.store'), $this->datosEmpleado(['cedula' => $cedula]))
            ->assertSessionHasErrors(['cedula' => 'La cédula debe contener solo números, máximo 10 dígitos, sin puntos ni caracteres especiales.']);

        $this->assertSame(0, Empleado::count());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nombresInvalidos(): array
    {
        return ['con dígitos' => ['Ana 2 Pérez'], 'solo números' => ['12345'], 'dígito pegado' => ['Ana3'], 'símbolo' => ['Ana@Pérez'], 'paréntesis' => ['Ana (Pérez)']];
    }

    #[DataProvider('nombresInvalidos')]
    public function test_el_nombre_con_numeros_o_simbolos_se_rechaza_y_se_conserva_lo_digitado(string $nombre): void
    {
        $this->actingAs(Usuario::factory()->create())->from(route('empleados.create'))
            ->post(route('empleados.store'), $this->datosEmpleado(['nombre_completo' => $nombre, 'cargo' => 'Analista']))
            ->assertRedirect(route('empleados.create'))
            ->assertSessionHasErrors(['nombre_completo' => 'El nombre solo puede tener letras (con tildes y ñ), espacios, apóstrofos, guiones y puntos; no admite números.'])
            ->assertSessionHasInput('cargo', 'Analista')
            ->assertSessionHasInput('cedula', '900000001');

        $this->assertSame(0, Empleado::count());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nombresValidos(): array
    {
        return [
            'tilde' => ['María José Pérez'],
            'eñe' => ['Begoña Muñoz'],
            'apóstrofo' => ["Sean O'Hara"],
            'apóstrofo tipográfico' => ['Sean O’Hara'],
            'guion' => ['Ana-María Gómez'],
            'punto' => ['Luis De la Cruz Jr.'],
        ];
    }

    #[DataProvider('nombresValidos')]
    public function test_los_nombres_con_tilde_enie_apostrofo_guion_y_punto_se_aceptan(string $nombre): void
    {
        $this->actingAs(Usuario::factory()->create())->post(route('empleados.store'), $this->datosEmpleado(['nombre_completo' => $nombre]))
            ->assertSessionHasNoErrors();

        $this->assertSame($nombre, Empleado::findOrFail('900000001')->nombre_completo);
    }

    public function test_la_edicion_tambien_rechaza_nombres_con_numeros(): void
    {
        $this->actingAs(Usuario::factory()->create());
        $empleado = Empleado::factory()->create(['nombre_completo' => 'Persona Uno']);
        $datos = $this->datosEmpleado(['nombre_completo' => 'Persona 1']);
        unset($datos['cedula']);

        $this->put(route('empleados.update', $empleado), $datos)->assertSessionHasErrors('nombre_completo');
        $this->put(route('empleados.update', $empleado), [...$datos, 'nombre_completo' => 'Persona Dos Jr.'])->assertSessionHasNoErrors();

        $this->assertSame('Persona Dos Jr.', $empleado->fresh()->nombre_completo);
    }

    public function test_el_formulario_limpia_la_cedula_y_el_nombre_en_el_navegador(): void
    {
        $html = $this->actingAs(Usuario::factory()->create())->get(route('empleados.create'))->assertOk()->getContent();

        $this->assertStringContainsString('inputmode="numeric"', $html);
        $this->assertStringContainsString("cedulaCampo.value.replace(/\\D/g, '')", $html);
        $this->assertStringContainsString("nombreCampo.value.replace(/[^\\p{L}\\s'’.\\-]/gu, '')", $html);
    }
}
