<?php

namespace Tests\Feature;

use App\Enums\EstadoCuota;
use App\Models\Prestamo;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrestamoTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_usuario_autenticado_puede_listar_los_prestamos(): void
    {
        $funcionario = Usuario::factory()->create();
        Prestamo::factory()->create();

        $this->actingAs($funcionario)->get(route('prestamos.index'))->assertOk();
    }

    public function test_un_usuario_autenticado_puede_ver_la_tabla_de_amortizacion_de_un_prestamo(): void
    {
        $funcionario = Usuario::factory()->create();
        $prestamo = Prestamo::factory()->create();

        $prestamo->cuotas()->create([
            'numero_cuota' => 1,
            'fecha_vencimiento' => now()->addDays(15),
            'valor_capital' => 80_000,
            'valor_interes' => 5_000,
            'valor_total' => 85_000,
            'estado' => EstadoCuota::Pendiente,
        ]);

        $this->actingAs($funcionario)->get(route('prestamos.show', $prestamo))
            ->assertOk()
            ->assertSee('Tabla de amortización')
            ->assertSee('85,000.00');
    }

    public function test_invitado_es_redirigido_a_login(): void
    {
        $this->get(route('prestamos.index'))->assertRedirect(route('login'));
    }
}
