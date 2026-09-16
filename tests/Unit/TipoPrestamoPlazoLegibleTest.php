<?php

namespace Tests\Unit;

use App\Enums\UnidadPlazo;
use App\Models\TipoPrestamo;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TipoPrestamoPlazoLegibleTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: int, 2: UnidadPlazo, 3: string}>
     */
    public static function conversiones(): array
    {
        return [
            'vivienda: 480 quincenas -> 20 años' => ['Vivienda — compra de inmueble', 480, UnidadPlazo::Anios, '20 años'],
            'educación: 72 quincenas -> 36 meses' => ['Educación', 72, UnidadPlazo::Meses, '36 meses'],
            'calamidad: 72 quincenas -> hasta 36 meses' => ['Calamidad doméstica y necesidades especiales', 72, UnidadPlazo::Meses, 'Hasta 36 meses'],
            'vehículo: 168 quincenas -> 7 años' => ['Vehículo', 168, UnidadPlazo::Anios, '7 años'],
        ];
    }

    #[DataProvider('conversiones')]
    public function test_plazo_maximo_legible_convierte_quincenas_a_la_unidad_del_acuerdo(
        string $nombre,
        int $plazoMaximo,
        UnidadPlazo $unidadPlazo,
        string $esperado,
    ): void {
        $tipoPrestamo = TipoPrestamo::factory()->make([
            'nombre' => $nombre,
            'plazo_maximo' => $plazoMaximo,
            'unidad_plazo' => $unidadPlazo,
        ]);

        $this->assertSame($esperado, $tipoPrestamo->plazo_maximo_legible);
    }
}
