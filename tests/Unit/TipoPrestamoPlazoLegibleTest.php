<?php

namespace Tests\Unit;

use App\Models\TipoPrestamo;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TipoPrestamoPlazoLegibleTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: int, 2: string}>
     */
    public static function conversiones(): array
    {
        return [
            'vivienda: 480 quincenas -> 20 años' => ['Vivienda — compra de inmueble', 480, '480 quincenas (≈ 20 años)'],
            'educación: 72 quincenas -> 36 meses' => ['Educación', 72, '72 quincenas (≈ 36 meses)'],
            'calamidad: 72 quincenas -> hasta 36 meses' => ['Calamidad doméstica y necesidades especiales', 72, 'Hasta 72 quincenas (≈ 36 meses)'],
            'vehículo: 168 quincenas -> 7 años' => ['Vehículo', 168, '168 quincenas (≈ 7 años)'],
        ];
    }

    #[DataProvider('conversiones')]
    public function test_plazo_maximo_legible_muestra_quincenas_con_su_equivalencia(string $nombre, int $plazoMaximo, string $esperado): void
    {
        $tipoPrestamo = TipoPrestamo::factory()->make(['nombre' => $nombre, 'plazo_maximo' => $plazoMaximo]);

        $this->assertSame($esperado, $tipoPrestamo->plazo_maximo_legible);
    }

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function equivalencias(): array
    {
        return [
            '1 quincena' => [1, '1 quincena (≈ 1 mes)'],
            '24 quincenas = 12 meses' => [24, '24 quincenas (≈ 12 meses)'],
            '78 quincenas = 39 meses' => [78, '78 quincenas (≈ 39 meses)'],
            '120 quincenas (5 años) sigue en meses' => [120, '120 quincenas (≈ 60 meses)'],
            '121 quincenas pasa a años' => [121, '121 quincenas (≈ 5 años)'],
            'años no exactos: 132 -> 6 (5,5 redondea hacia arriba)' => [132, '132 quincenas (≈ 6 años)'],
            'años no exactos: 150 -> 6' => [150, '150 quincenas (≈ 6 años)'],
            '168 quincenas = 7 años' => [168, '168 quincenas (≈ 7 años)'],
            '240 quincenas = 10 años' => [240, '240 quincenas (≈ 10 años)'],
            '480 quincenas = 20 años' => [480, '480 quincenas (≈ 20 años)'],
        ];
    }

    #[DataProvider('equivalencias')]
    public function test_equivalencia_en_meses_hasta_120_quincenas_y_en_anios_desde_ahi(int $quincenas, string $esperado): void
    {
        $this->assertSame($esperado, TipoPrestamo::plazoEnQuincenas($quincenas));
    }
}
