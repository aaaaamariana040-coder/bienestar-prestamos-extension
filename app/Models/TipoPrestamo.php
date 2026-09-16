<?php

namespace App\Models;

use App\Enums\UnidadPlazo;
use Database\Factories\TipoPrestamoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['nombre', 'descripcion', 'plazo_maximo', 'unidad_plazo', 'habilitada', 'fuente_normativa'])]
class TipoPrestamo extends Model
{
    /** @use HasFactory<TipoPrestamoFactory> */
    use HasFactory;

    protected $table = 'tipos_prestamo';

    protected $primaryKey = 'id_tipo';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unidad_plazo' => UnidadPlazo::class,
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
     * plazo_maximo se almacena en cuotas quincenales; aquí se presenta en la unidad
     * que usa el Acuerdo Directivo No. 19/2022 para cada modalidad (años o meses).
     */
    protected function plazoMaximoLegible(): Attribute
    {
        return Attribute::make(
            get: function (): string {
                $meses = $this->plazo_maximo / 2;

                if ($this->unidad_plazo === UnidadPlazo::Anios) {
                    return $this->formatearCantidad($meses / 12).' años';
                }

                $texto = $this->formatearCantidad($meses).' meses';

                return $this->esCalamidadDomestica() ? "Hasta {$texto}" : $texto;
            },
        );
    }

    private function esCalamidadDomestica(): bool
    {
        return $this->nombre === 'Calamidad doméstica y necesidades especiales';
    }

    private function formatearCantidad(float $cantidad): string
    {
        return rtrim(rtrim(number_format($cantidad, 2, '.', ''), '0'), '.');
    }
}
