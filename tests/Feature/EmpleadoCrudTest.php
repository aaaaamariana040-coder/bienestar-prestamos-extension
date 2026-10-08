<?php

namespace Tests\Feature;

use App\Models\Empleado;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmpleadoCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_capacidad_endeudamiento_is_calculated_automatically_when_omitted(): void
    {
        $this->actingAs(Usuario::factory()->create());

        $this->post(route('empleados.store'), $this->datosEmpleado(['salario' => 2_000_000, 'capacidad_endeudamiento' => '']));

        $this->assertDatabaseHas('empleados', [
            'cedula' => '900000001',
            'capacidad_endeudamiento' => '1000000.00',
        ]);
    }

    public function test_capacidad_endeudamiento_manual_es_respetada(): void
    {
        $this->actingAs(Usuario::factory()->create());

        $this->post(route('empleados.store'), $this->datosEmpleado([
            'salario' => 2_000_000,
            'capacidad_endeudamiento' => 700000,
        ]));

        $this->assertDatabaseHas('empleados', [
            'cedula' => '900000001',
            'capacidad_endeudamiento' => '700000.00',
        ]);
    }

    public function test_fechas_de_credito_enviadas_de_mas_no_se_persisten(): void
    {
        $this->actingAs(Usuario::factory()->create());

        $this->post(route('empleados.store'), $this->datosEmpleado([
            'fecha_solicitud_credito' => '2026-01-01',
            'fecha_autorizacion_credito' => '2026-01-15',
        ]));

        $empleado = Empleado::firstOrFail();

        $this->assertArrayNotHasKey('fecha_solicitud_credito', $empleado->getAttributes());
        $this->assertArrayNotHasKey('fecha_autorizacion_credito', $empleado->getAttributes());
    }

    public function test_cedula_con_letras_con_puntos_o_de_mas_de_10_digitos_es_rechazada(): void
    {
        $this->actingAs(Usuario::factory()->create());
        $empleadosAntes = Empleado::count();

        foreach (['12345abc', '1.234.567', '12345678901', '123-456'] as $cedula) {
            $this->post(route('empleados.store'), $this->datosEmpleado(['cedula' => $cedula]))
                ->assertSessionHasErrors('cedula');
        }

        $this->assertSame($empleadosAntes, Empleado::count());
    }

    public function test_mensaje_de_cedula_invalida_esta_en_espanol(): void
    {
        $this->actingAs(Usuario::factory()->create());

        $this->post(route('empleados.store'), $this->datosEmpleado(['cedula' => '12345678901']))
            ->assertSessionHasErrors(['cedula' => 'La cédula debe contener solo números, máximo 10 dígitos, sin puntos ni caracteres especiales.']);
    }

    public function test_cedula_se_normaliza_quitando_espacios(): void
    {
        $this->actingAs(Usuario::factory()->create());

        $this->post(route('empleados.store'), $this->datosEmpleado(['cedula' => ' 1234 567890 ']))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('empleados', ['cedula' => '1234567890']);
    }

    public function test_no_se_puede_crear_un_empleado_con_estado_retirado(): void
    {
        $this->actingAs(Usuario::factory()->create());
        $empleadosAntes = Empleado::count();

        $this->post(route('empleados.store'), $this->datosEmpleado(['estado' => 'Retirado']))
            ->assertSessionHasErrors(['estado' => 'Un empleado nuevo solo puede crearse con estado Activo.']);

        $this->assertSame($empleadosAntes, Empleado::count());
    }

    public function test_formulario_de_crear_restringe_la_cedula_y_no_ofrece_retirado(): void
    {
        $this->actingAs(Usuario::factory()->create());

        $this->get(route('empleados.create'))
            ->assertSee('inputmode="numeric"', false)
            ->assertSee('maxlength="10"', false)
            ->assertSee('pattern="[0-9]{1,10}"', false)
            ->assertDontSee('<option value="Retirado"', false);
    }

    public function test_listado_busca_por_cedula_o_nombre_parcial_sin_distinguir_mayusculas(): void
    {
        $this->actingAs(Usuario::factory()->create());
        Empleado::factory()->create(['cedula' => '1000000009', 'nombre_completo' => 'Prueba Solicitud Lista']);
        Empleado::factory()->create(['cedula' => '2000000001', 'nombre_completo' => 'Otra Persona']);

        $this->get(route('empleados.index', ['q' => 'SOLICITUD']))
            ->assertOk()
            ->assertSee('Prueba Solicitud Lista')
            ->assertDontSee('Otra Persona');

        $this->get(route('empleados.index', ['q' => '00000009']))
            ->assertOk()
            ->assertSee('Prueba Solicitud Lista')
            ->assertDontSee('Otra Persona');
    }

    public function test_listado_filtra_por_estado_y_conserva_filtros_en_la_paginacion(): void
    {
        $this->actingAs(Usuario::factory()->create());
        Empleado::factory()->create(['nombre_completo' => 'Persona Activa', 'estado' => 'Activo']);
        Empleado::factory()->create(['nombre_completo' => 'Persona Retirada', 'estado' => 'Retirado']);
        Empleado::factory()->count(16)->create(['estado' => 'Retirado']);

        $this->get(route('empleados.index', ['estado' => 'Retirado']))
            ->assertOk()
            ->assertDontSee('Persona Activa')
            ->assertSee('estado=Retirado', false);

        $this->get(route('empleados.index', ['estado' => 'Activo']))
            ->assertOk()
            ->assertSee('Persona Activa')
            ->assertDontSee('Persona Retirada');
    }

    public function test_puede_ver_editar_y_eliminar_un_empleado(): void
    {
        $this->actingAs(Usuario::factory()->create());
        $empleado = Empleado::factory()->create();

        $this->get(route('empleados.show', $empleado))->assertOk();

        $this->put(route('empleados.update', $empleado), $this->datosEmpleado([], incluirCedula: false))
            ->assertRedirect(route('empleados.index'));

        $this->assertDatabaseHas('empleados', ['cedula' => $empleado->cedula, 'nombre_completo' => 'Ana Actualizada']);

        $this->delete(route('empleados.destroy', $empleado))
            ->assertRedirect(route('empleados.index'));

        $this->assertDatabaseMissing('empleados', ['cedula' => $empleado->cedula]);
    }

    public function test_editar_no_permite_cambiar_el_estado_a_retirado(): void
    {
        $this->actingAs(Usuario::factory()->create());
        $empleado = Empleado::factory()->create();

        $this->from(route('empleados.edit', $empleado))
            ->put(route('empleados.update', $empleado), $this->datosEmpleado(['estado' => 'Retirado'], incluirCedula: false))
            ->assertRedirect(route('empleados.edit', $empleado))
            ->assertSessionHasErrors('estado');

        $this->assertDatabaseHas('empleados', ['cedula' => $empleado->cedula, 'estado' => 'Activo']);
        $this->assertDatabaseCount('reliquidaciones', 0);
    }

    public function test_un_empleado_retirado_se_puede_editar_y_reactivar(): void
    {
        $this->actingAs(Usuario::factory()->create());
        $empleado = Empleado::factory()->retirado()->create();

        $this->put(route('empleados.update', $empleado), $this->datosEmpleado(['estado' => 'Retirado'], incluirCedula: false))
            ->assertRedirect(route('empleados.index'));
        $this->assertDatabaseHas('empleados', ['cedula' => $empleado->cedula, 'estado' => 'Retirado']);

        $this->put(route('empleados.update', $empleado), $this->datosEmpleado(['estado' => 'Activo'], incluirCedula: false))
            ->assertRedirect(route('empleados.index'));
        $this->assertDatabaseHas('empleados', ['cedula' => $empleado->cedula, 'estado' => 'Activo']);
    }

    public function test_no_se_puede_eliminar_un_empleado_con_usuario_asociado(): void
    {
        $usuario = Usuario::factory()->create();
        $this->actingAs($usuario);

        $response = $this->delete(route('empleados.destroy', $usuario->cedula));

        $response->assertRedirect(route('empleados.index'));
        $this->assertDatabaseHas('empleados', ['cedula' => $usuario->cedula]);
    }

    /**
     * @param  array<string, mixed>  $sobrescribir
     * @return array<string, mixed>
     */
    private function datosEmpleado(array $sobrescribir = [], bool $incluirCedula = true): array
    {
        $datos = [
            'nombre_completo' => 'Ana Actualizada',
            'tipo_vinculacion' => 'Empleado',
            'cargo' => 'Analista',
            'dependencia' => 'Bienestar',
            'salario' => 2_000_000,
            'fecha_vinculacion' => '2020-01-01',
            'estado' => 'Activo',
            'capacidad_endeudamiento' => '',
        ];

        if ($incluirCedula) {
            $datos['cedula'] = '900000001';
        }

        return array_merge($datos, $sobrescribir);
    }
}
