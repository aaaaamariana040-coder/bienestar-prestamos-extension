<?php

namespace App\Models;

use App\Enums\ConceptoSeguro;
use Database\Factories\PrimaSeguroFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['concepto', 'anio', 'tasa'])]
class PrimaSeguro extends Model
{
    /** @use HasFactory<PrimaSeguroFactory> */
    use HasFactory;

    protected $table = 'primas_seguro';

    protected $primaryKey = 'id_prima';

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'concepto' => ConceptoSeguro::class,
            'tasa' => 'decimal:8',
        ];
    }

    /**
     * La tasa con coma decimal y al menos 7 decimales: 0,0003231.
     *
     * @return Attribute<string, never>
     */
    protected function tasaLegible(): Attribute
    {
        return Attribute::get(fn (): string => self::formatear((float) $this->tasa));
    }

    public static function formatear(float $tasa): string
    {
        [$entero, $decimales] = explode(',', number_format($tasa, 8, ',', ''));

        return $entero.','.(str_ends_with($decimales, '0') ? substr($decimales, 0, 7) : $decimales);
    }
}
