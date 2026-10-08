<?php

namespace App\Models;

use Database\Factories\TipoPrestamoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['nombre', 'descripcion', 'plazo_maximo', 'habilitada', 'fuente_normativa'])]
class TipoPrestamo extends Model
{
    /** @use HasFactory<TipoPrestamoFactory> */
    use HasFactory;

    public const QUINCENAS_POR_ANIO = 24;

    public const LIMITE_EQUIVALENCIA_EN_MESES = 120;

    protected $table = 'tipos_prestamo';

    protected $primaryKey = 'id_tipo';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'habilitada' => 'boolean',
        ];
    }

    /**
     * @return HasMany<TasaInt, $this>
     */
    public function tasas(): HasMany
    {
        return $this->hasMany(TasaInt::class, 'id_tipo', 'id_tipo')->orderByDesc('anio');
    }

    /**
     * @return HasMany<CuantiaSmmlv, $this>
     */
    public function cuantias(): HasMany
    {
        return $this->hasMany(CuantiaSmmlv::class, 'id_tipo', 'id_tipo')->orderBy('tramo_desde_smmlv');
    }

    /**
     * El plazo se maneja siempre en quincenas (24 por año). Hasta 120 quincenas
     * (5 años) la equivalencia aproximada se da en meses; desde ahí, en años,
     * redondeando al entero más cercano.
     */
    public static function equivalenciaDelPlazo(int $quincenas): string
    {
        if ($quincenas <= self::LIMITE_EQUIVALENCIA_EN_MESES) {
            $meses = max(1, (int) round($quincenas / 2));

            return "≈ {$meses} ".($meses === 1 ? 'mes' : 'meses');
        }

        $anios = (int) round($quincenas / self::QUINCENAS_POR_ANIO);

        return "≈ {$anios} ".($anios === 1 ? 'año' : 'años');
    }

    /**
     * Ejemplo: «72 quincenas (≈ 36 meses)».
     */
    public static function plazoEnQuincenas(int $quincenas): string
    {
        $unidad = $quincenas === 1 ? 'quincena' : 'quincenas';

        return "{$quincenas} {$unidad} (".self::equivalenciaDelPlazo($quincenas).')';
    }

    protected function plazoMaximoLegible(): Attribute
    {
        return Attribute::make(
            get: function (): string {
                $texto = self::plazoEnQuincenas((int) $this->plazo_maximo);

                return $this->esCalamidadDomestica() ? "Hasta {$texto}" : $texto;
            },
        );
    }

    private function esCalamidadDomestica(): bool
    {
        return $this->nombre === 'Calamidad doméstica y necesidades especiales';
    }
}
