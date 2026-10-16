<?php

namespace Tests\Feature;

use App\Enums\TipoProcesoQuincena;
use App\Models\ProcesoQuincena;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcesoQuincenaModeloTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_permite_dos_procesos_reales_de_la_misma_quincena_y_anio(): void
    {
        ProcesoQuincena::factory()->create(['anio' => 2026, 'numero_quincena' => 20]);

        $this->expectException(UniqueConstraintViolationException::class);

        ProcesoQuincena::factory()->create(['anio' => 2026, 'numero_quincena' => 20]);
    }

    public function test_permite_la_misma_quincena_en_otro_anio_o_otra_quincena(): void
    {
        ProcesoQuincena::factory()->create(['anio' => 2026, 'numero_quincena' => 20]);
        ProcesoQuincena::factory()->create(['anio' => 2027, 'numero_quincena' => 20]);
        ProcesoQuincena::factory()->create(['anio' => 2026, 'numero_quincena' => 21]);

        $this->assertSame(3, ProcesoQuincena::count());
        $this->assertSame(TipoProcesoQuincena::Real, ProcesoQuincena::first()->tipo);
    }
}
