<?php

namespace Tests\Unit;

use App\Services\PresupuestoService;
use Tests\TestCase;

class PresupuestoServiceTest extends TestCase
{
    public function test_sugerencia_vivienda_reparte_segun_el_articulo_18_del_acuerdo_19_de_2022(): void
    {
        $sugerencia = app(PresupuestoService::class)->sugerenciaVivienda(10_000_000);

        $this->assertSame(5_950_000.0, $sugerencia['Vivienda — compra de inmueble']);
        $this->assertSame(1_050_000.0, $sugerencia['Vivienda — construcción']);
        $this->assertSame(1_000_000.0, $sugerencia['Vivienda — cancelación total de hipoteca (deshipoteca)']);
        $this->assertSame(1_000_000.0, $sugerencia['Vivienda — reparaciones o mejoras']);
        $this->assertSame(1_000_000.0, $sugerencia['Vivienda — cambio de vivienda']);

        $this->assertSame(10_000_000.0, array_sum($sugerencia));
    }
}
