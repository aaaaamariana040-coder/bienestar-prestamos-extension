<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProveedorTest extends TestCase
{
    use RefreshDatabase;

    public function test_funcionario_puede_registrar_un_proveedor(): void
    {
        $funcionario = Usuario::factory()->create();

        $this->actingAs($funcionario)->post(route('proveedores.store'), [
            'id_proveedor' => 'PROV-001',
            'nit' => '900123456-7',
            'nombre_empresa' => 'Constructora ABC',
        ])->assertRedirect(route('proveedores.index'));

        $this->assertDatabaseHas('proveedores', [
            'id_proveedor' => 'PROV-001',
            'nit' => '900123456-7',
            'nombre_empresa' => 'Constructora ABC',
        ]);
    }

    public function test_invitado_es_redirigido_a_login(): void
    {
        $this->get(route('proveedores.index'))->assertRedirect(route('login'));
    }
}
