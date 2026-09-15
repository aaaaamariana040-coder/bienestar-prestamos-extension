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
