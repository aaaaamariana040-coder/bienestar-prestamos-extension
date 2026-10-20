<?php

namespace Tests\Feature;

use App\Enums\EstadoCuota;
use App\Models\Empleado;
use App\Models\Prestamo;
use App\Models\TipoPrestamo;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MontosFormatoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function formatosValidos(): array
    {
        return [
            'puntos de miles' => ['7.500.000', '7500000.00'],
            'comas de miles' => ['7,500,000', '7500000.00'],
            'sin separadores' => ['7500000', '7500000.00'],
            'con simbolo y espacios' => ['$ 7.500.000', '7500000.00'],
            'miles con coma decimal' => ['7.500.000,50', '7500000.50'],
            'miles con punto decimal' => ['7,500,000.50', '7500000.50'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function montosRechazados(): array
    {
        return [
            'decimal con punto (7.5)' => ['7.5'],
            'menor al minimo (500)' => ['500'],
            'decimal con coma (7,40)' => ['7,40'],
            'decimal con punto (7.40)' => ['7.40'],
            'texto' => ['abc'],
            'formato de miles invalido' => ['7.50.000'],
        ];
    }

    #[DataProvider('formatosValidos')]
    public function test_solicitud_normaliza_el_monto_escrito_con_separadores(string $escrito, string $guardado): void
    {
        $empleado = Empleado::factory()->create();
        $tipoPrestamo = TipoPrestamo::factory()->create();

        $this->actingAs(Usuario::factory()->create())->post(route('solicitudes.store'), [
            'cedula_empleado' => $empleado->cedula,
            'id_tipo' => $tipoPrestamo->id_tipo,
            'monto_solicitado' => $escrito,
            'plazo_solicitado' => 24,
            'fecha_solicitud' => '2026-01-15',
        ])->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('solicitudes_credito', [
            'cedula_empleado' => $empleado->cedula,
            'monto_solicitado' => $guardado,
        ]);
    }

    #[DataProvider('montosRechazados')]
    public function test_solicitud_rechaza_montos_invalidos_o_menores_al_minimo(string $escrito): void
    {
        $empleado = Empleado::factory()->create();
        $tipoPrestamo = TipoPrestamo::factory()->create();

        $this->actingAs(Usuario::factory()->create())->post(route('solicitudes.store'), [
            'cedula_empleado' => $empleado->cedula,
            'id_tipo' => $tipoPrestamo->id_tipo,
            'monto_solicitado' => $escrito,
            'plazo_solicitado' => 24,
            'fecha_solicitud' => '2026-01-15',
        ])->assertSessionHasErrors('monto_solicitado');

        $this->assertDatabaseCount('solicitudes_credito', 0);
    }

    #[DataProvider('formatosValidos')]
    public function test_abono_extraordinario_normaliza_el_monto(string $escrito, string $guardado): void
    {
        $prestamo = Prestamo::factory()->create(['monto_aprobado' => 50_000_000, 'cuota_periodica' => 5_000_000]);
        // El abono se compara siempre contra el saldo de capital pendiente, así que el préstamo necesita cuotas.
        $prestamo->cuotas()->create([
            'numero_cuota' => 1,
            'fecha_vencimiento' => '2026-03-01',
            'valor_capital' => 50_000_000,
            'valor_interes' => 0,
            'valor_total' => 50_000_000,
            'estado' => EstadoCuota::Pendiente,
        ]);

        $this->actingAs(Usuario::factory()->create())->post(route('pagos.store-abono', $prestamo), [
            'monto' => $escrito,
            'fecha_pago' => '2026-02-15',
        ])->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('pagos', [
            'id_prestamo' => $prestamo->id_prestamo,
            'monto' => $guardado,
        ]);
    }

    #[DataProvider('montosRechazados')]
    public function test_abono_extraordinario_rechaza_montos_invalidos_o_menores_al_minimo(string $escrito): void
    {
        $prestamo = Prestamo::factory()->create(['monto_aprobado' => 50_000_000, 'cuota_periodica' => 5_000_000]);
        // El abono se compara siempre contra el saldo de capital pendiente, así que el préstamo necesita cuotas.
        $prestamo->cuotas()->create([
            'numero_cuota' => 1,
            'fecha_vencimiento' => '2026-03-01',
            'valor_capital' => 50_000_000,
            'valor_interes' => 0,
            'valor_total' => 50_000_000,
            'estado' => EstadoCuota::Pendiente,
        ]);

        $this->actingAs(Usuario::factory()->create())->post(route('pagos.store-abono', $prestamo), [
            'monto' => $escrito,
            'fecha_pago' => '2026-02-15',
        ])->assertSessionHasErrors('monto');

        $this->assertDatabaseCount('pagos', 0);
    }

    #[DataProvider('formatosValidos')]
    public function test_abono_de_cesantias_acepta_los_formatos_de_monto(string $escrito, string $guardado): void
    {
        $prestamo = Prestamo::factory()->create(['monto_aprobado' => 50_000_000, 'cuota_periodica' => 5_000_000]);

        $this->actingAs(Usuario::factory()->create())->post(route('cesantias.store', $prestamo), [
            'anio' => 2025,
            'monto' => $escrito,
            'fecha_abono' => '2026-02-15',
        ])->assertSessionDoesntHaveErrors('monto');
    }

    #[DataProvider('montosRechazados')]
    public function test_abono_de_cesantias_rechaza_montos_invalidos_o_menores_al_minimo(string $escrito): void
    {
        $prestamo = Prestamo::factory()->create(['monto_aprobado' => 50_000_000, 'cuota_periodica' => 5_000_000]);

        $this->actingAs(Usuario::factory()->create())->post(route('cesantias.store', $prestamo), [
            'anio' => 2025,
            'monto' => $escrito,
            'fecha_abono' => '2026-02-15',
        ])->assertSessionHasErrors('monto');

        $this->assertDatabaseCount('abonos_cesantias', 0);
    }

    #[DataProvider('formatosValidos')]
    public function test_smmlv_normaliza_el_valor(string $escrito, string $guardado): void
    {
        $this->actingAs(Usuario::factory()->administrador()->create())->post(route('smmlv.store'), [
            'anio' => 2026,
            'valor' => $escrito,
        ])->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('smmlv_anual', ['anio' => 2026, 'valor' => $guardado]);
    }

    #[DataProvider('montosRechazados')]
    public function test_smmlv_rechaza_valores_invalidos_o_menores_al_minimo(string $escrito): void
    {
        $this->actingAs(Usuario::factory()->administrador()->create())->post(route('smmlv.store'), [
            'anio' => 2026,
            'valor' => $escrito,
        ])->assertSessionHasErrors('valor');

        $this->assertDatabaseCount('smmlv_anual', 0);
    }
}
