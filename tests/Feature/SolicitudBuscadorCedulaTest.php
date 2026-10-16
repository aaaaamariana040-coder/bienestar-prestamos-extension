<?php

namespace Tests\Feature;

use App\Models\Empleado;
use App\Models\SolicitudCredito;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class SolicitudBuscadorCedulaTest extends TestCase
{
    use RefreshDatabase;

    private function solicitudDeEmpleadoConCedula(string $cedula): SolicitudCredito
    {
        $empleado = Empleado::factory()->create(['cedula' => $cedula, 'nombre_completo' => 'Empleado '.$cedula]);

        return SolicitudCredito::factory()->create(['cedula_empleado' => $empleado->cedula]);
    }

    public function test_filtra_las_solicitudes_cuya_cedula_empieza_por_los_digitos_escritos(): void
    {
        $this->solicitudDeEmpleadoConCedula('1234567890');
        $this->solicitudDeEmpleadoConCedula('1239999999');
        $this->solicitudDeEmpleadoConCedula('9876543210');

        $this->actingAs(Usuario::factory()->create())
            ->get(route('solicitudes.index', ['cedula' => '123']))
            ->assertOk()
            ->assertSee('Empleado 1234567890')
            ->assertSee('Empleado 1239999999')
            ->assertDontSee('Empleado 9876543210');
    }

    public function test_no_encuentra_cedulas_que_contienen_los_digitos_en_medio_o_al_final(): void
    {
        $this->solicitudDeEmpleadoConCedula('1234567890');
        $this->solicitudDeEmpleadoConCedula('9912345678');
        $this->solicitudDeEmpleadoConCedula('5555567890');

        $this->actingAs(Usuario::factory()->create())
            ->get(route('solicitudes.index', ['cedula' => '234']))
            ->assertOk()
            ->assertDontSee('Empleado 1234567890')
            ->assertDontSee('Empleado 9912345678')
            ->assertDontSee('Empleado 5555567890')
            ->assertSee('No hay solicitudes con esa cédula.');
    }

    public function test_sin_texto_muestra_el_listado_completo(): void
    {
        $this->solicitudDeEmpleadoConCedula('1234567890');
        $this->solicitudDeEmpleadoConCedula('9876543210');

        $usuario = Usuario::factory()->create();

        foreach ([[], ['cedula' => ''], ['cedula' => 'abc']] as $consulta) {
            $this->actingAs($usuario)
                ->get(route('solicitudes.index', $consulta))
                ->assertOk()
                ->assertSee('Empleado 1234567890')
                ->assertSee('Empleado 9876543210')
                ->assertDontSee('No hay solicitudes con esa cédula.');
        }
    }

    public function test_ignora_lo_que_no_son_digitos_y_limita_a_diez(): void
    {
        $this->solicitudDeEmpleadoConCedula('1234567890');
        $this->solicitudDeEmpleadoConCedula('9876543210');

        $this->actingAs(Usuario::factory()->create())
            ->get(route('solicitudes.index', ['cedula' => '12%_3-4 ']))
            ->assertOk()
            ->assertSee('Empleado 1234567890')
            ->assertDontSee('Empleado 9876543210');

        $this->actingAs(Usuario::factory()->create())
            ->get(route('solicitudes.index', ['cedula' => '12345678901234']))
            ->assertOk()
            ->assertSee('Empleado 1234567890');
    }

    public function test_sin_resultados_muestra_un_mensaje_y_no_una_tabla_vacia(): void
    {
        $this->solicitudDeEmpleadoConCedula('1234567890');

        $this->actingAs(Usuario::factory()->create())
            ->get(route('solicitudes.index', ['cedula' => '777']))
            ->assertOk()
            ->assertSee('No hay solicitudes con esa cédula.')
            ->assertDontSee('<th>Empleado</th>', false);
    }

    public function test_el_formulario_funciona_sin_javascript_y_conserva_la_cedula_escrita(): void
    {
        $this->solicitudDeEmpleadoConCedula('1234567890');

        $this->actingAs(Usuario::factory()->create())
            ->get(route('solicitudes.index', ['cedula' => '123']))
            ->assertSee('<form method="GET" action="'.route('solicitudes.index').'"', false)
            ->assertSee('name="cedula"', false)
            ->assertSee('value="123"', false)
            ->assertSee('id="resultados-solicitudes"', false)
            ->assertSee('type="submit"', false);
    }

    public function test_el_componente_sin_modo_se_comporta_como_antes(): void
    {
        $html = Blade::render('<x-buscador-cedula url="/simulador/empleado" id="cedula_empleado" name="cedula_empleado" />');

        $this->assertStringContainsString('<input type="text" id="cedula_empleado"', $html);
        $this->assertStringContainsString('name="cedula_empleado"', $html);
        $this->assertStringContainsString('class="form-control"', $html);
        $this->assertStringContainsString('placeholder="Escribe la cédula"', $html);
        $this->assertStringContainsString('<div id="resultado-empleado" class="mt-2" aria-live="polite"></div>', $html);
        $this->assertStringContainsString("fetch(urlBase + '/' + cedula", $html);
        $this->assertStringContainsString("'cedula:' + tipo", $html);
        $this->assertStringContainsString('campo.buscarAhora = buscar;', $html);
        $this->assertStringNotContainsString('formulario', $html);
        $this->assertStringNotContainsString('history.replaceState', $html);
    }

    public function test_el_componente_en_modo_filtro_consulta_la_propia_pagina_y_cae_al_envio_normal(): void
    {
        $html = Blade::render('<x-buscador-cedula modo="filtro" id="cedula" objetivo="resultados-solicitudes" />');

        $this->assertStringContainsString('name="cedula"', $html);
        $this->assertStringContainsString('maxlength="10"', $html);
        $this->assertStringContainsString('setTimeout(filtrar, 250)', $html);
        $this->assertStringContainsString('"resultados-solicitudes"', $html);
        $this->assertStringContainsString('formulario.submit()', $html);
        $this->assertStringNotContainsString('resultado-empleado', $html);
        $this->assertStringNotContainsString('fetch(urlBase', $html);
    }

    public function test_el_simulador_y_el_registro_de_solicitud_siguen_usando_el_buscador_exacto(): void
    {
        $usuario = Usuario::factory()->administrador()->create();

        $this->actingAs($usuario)
            ->get(route('solicitudes.create'))
            ->assertOk()
            ->assertSee('id="resultado-empleado"', false)
            ->assertSee("fetch(urlBase + '/' + cedula", false)
            ->assertDontSee('formulario.submit()', false);

        $this->actingAs($usuario)
            ->get(route('simulador.index'))
            ->assertOk()
            ->assertSee('id="resultado-empleado"', false)
            ->assertDontSee('formulario.submit()', false);
    }
}
