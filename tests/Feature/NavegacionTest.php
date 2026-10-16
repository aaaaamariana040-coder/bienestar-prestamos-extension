<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class NavegacionTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_portada_muestra_logo_y_nombre_de_la_aplicacion_sin_tablas(): void
    {
        $usuario = Usuario::factory()->create(['nombre' => 'Laura', 'apellido' => 'Mejia']);

        $this->actingAs($usuario)
            ->get(route('inicio'))
            ->assertOk()
            ->assertSee('class="portada', false)
            ->assertSee('images/logo-politecnico.png', false)
            ->assertSee('Bienestar Préstamos')
            ->assertSee('Hola, <strong>Laura Mejia</strong>', false)
            ->assertDontSee('<table', false);
    }

    public function test_la_portada_muestra_el_titulo_justo_debajo_del_logo(): void
    {
        $this->actingAs(Usuario::factory()->create())
            ->get(route('inicio'))
            ->assertOk()
            ->assertSeeInOrder([
                '<div class="portada-marca">',
                'images/logo-politecnico.png',
                '<h1 class="portada-titulo">Bienestar Préstamos</h1>',
                '</div>',
                'Hola, <strong>',
            ], false);
    }

    public function test_el_logo_y_el_titulo_de_la_portada_se_apilan_en_columna_solo_en_la_portada(): void
    {
        $css = File::get(public_path('css/tema-institucional.css'));

        $this->assertMatchesRegularExpression(
            '/\.portada-marca\s*\{[^}]*display:\s*flex;[^}]*flex-direction:\s*column;[^}]*align-items:\s*center;/',
            $css
        );
        $this->assertMatchesRegularExpression('/\.portada-logo\s*\{[^}]*max-width:\s*100%;/', $css);
        $this->assertStringContainsString('border-bottom: 4px solid var(--color-acento)', $css);

        $vistasConPortadaMarca = collect(File::allFiles(resource_path('views')))
            ->filter(fn ($archivo): bool => str_contains($archivo->getContents(), 'portada-marca'))
            ->map(fn ($archivo): string => str_replace('\\', '/', $archivo->getRelativePathname()))
            ->values()
            ->all();

        $this->assertSame(['inicio.blade.php'], $vistasConPortadaMarca);
    }

    public function test_la_portada_exige_sesion(): void
    {
        $this->get(route('inicio'))->assertRedirect(route('login'));
    }

    public function test_la_raiz_lleva_a_la_portada_si_hay_sesion_y_al_inicio_publico_si_no(): void
    {
        $this->get('/')->assertOk()->assertSee('Iniciar sesión');

        $this->actingAs(Usuario::factory()->create())->get('/')->assertRedirect(route('inicio'));
    }

    public function test_el_logo_es_el_del_politecnico_y_se_referencia_en_un_solo_lugar(): void
    {
        $this->assertFileExists(public_path('images/logo-politecnico.png'));
        $this->assertSame('89504e47', bin2hex(substr(File::get(public_path('images/logo-politecnico.png')), 0, 4)));

        $archivosConLogo = collect(File::allFiles(resource_path('views')))
            ->filter(fn ($archivo): bool => str_contains($archivo->getContents(), 'logo-politecnico'))
            ->map(fn ($archivo): string => str_replace('\\', '/', $archivo->getRelativePathname()))
            ->values()
            ->all();

        $this->assertSame(['components/logo.blade.php'], $archivosConLogo);
    }

    public function test_el_administrador_ve_todos_los_grupos_del_menu_lateral(): void
    {
        $respuesta = $this->actingAs(Usuario::factory()->administrador()->create())
            ->get(route('inicio'))
            ->assertOk()
            ->assertSee('id="menu-lateral"', false)
            ->assertSee('id="alternar-menu"', false);

        foreach (['administrador', 'empleados', 'modalidades', 'solicitudes-prestamos', 'pagos', 'reportes'] as $grupo) {
            $respuesta->assertSee('data-grupo="'.$grupo.'"', false);
        }

        $respuesta
            ->assertSee('Modalidades y presupuesto')
            ->assertSee('Solicitudes y préstamos')
            ->assertSee(route('usuarios.index'), false)
            ->assertSee(route('auditoria.index'), false)
            ->assertSee(route('empleados.index'), false)
            ->assertSee(route('empleados.create'), false)
            ->assertSee(route('tipos-prestamo.index'), false)
            ->assertSee(route('presupuestos.index'), false)
            ->assertSee(route('solicitudes.index'), false)
            ->assertSee(route('prestamos.index'), false)
            ->assertSee(route('reportes.pagos'), false)
            ->assertSee(route('reportes.cartera'), false);
    }

    public function test_el_funcionario_no_ve_el_grupo_administrador_pero_si_los_demas(): void
    {
        $this->actingAs(Usuario::factory()->create())
            ->get(route('inicio'))
            ->assertOk()
            ->assertDontSee('data-grupo="administrador"', false)
            ->assertDontSee(route('usuarios.index'), false)
            ->assertDontSee(route('auditoria.index'), false)
            ->assertSee('data-grupo="empleados"', false)
            ->assertSee('data-grupo="modalidades"', false)
            ->assertSee('data-grupo="solicitudes-prestamos"', false)
            ->assertSee('data-grupo="pagos"', false)
            ->assertSee('data-grupo="reportes"', false);
    }

    public function test_el_grupo_de_la_pantalla_actual_llega_desplegado_y_resaltado(): void
    {
        $this->actingAs(Usuario::factory()->create())
            ->get(route('empleados.index'))
            ->assertSee('collapse menu-submenu show" id="grupo-empleados"', false)
            ->assertSee('aria-current="page"', false)
            ->assertSee('collapse menu-submenu " id="grupo-reportes"', false);
    }

    public function test_el_menu_colapsa_a_iconos_y_es_responsive(): void
    {
        $css = File::get(public_path('css/tema-institucional.css'));

        $this->assertStringContainsString('body.menu-colapsado .menu-lateral', $css);
        $this->assertStringContainsString('body.menu-abierto .menu-lateral', $css);
        $this->assertStringContainsString('@media (max-width: 991.98px)', $css);
    }

    public function test_el_tema_conserva_los_colores_institucionales_y_no_usa_el_morado(): void
    {
        // Se ignoran los comentarios: el encabezado del tema menciona el morado justamente para prohibirlo.
        $css = strtolower(preg_replace('#/\*.*?\*/#s', '', File::get(public_path('css/tema-institucional.css'))));

        $this->assertStringContainsString('--color-primario: #00ab44', $css);
        $this->assertStringContainsString('--color-acento: #ffd400', $css);
        $this->assertStringContainsString('background-color: var(--color-primario-oscuro)', $css);
        $this->assertStringNotContainsString('79067f', $css);
    }

    public function test_el_login_muestra_el_logo_y_lleva_a_la_portada(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('images/logo-politecnico.png', false);

        $usuario = Usuario::factory()->create();

        $this->post(route('login'), ['cedula' => $usuario->cedula, 'password' => 'password'])
            ->assertRedirect(route('inicio'));
    }
}
