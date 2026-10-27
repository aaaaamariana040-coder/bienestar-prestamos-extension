<?php

namespace App\Models;

use Database\Factories\TasaDtfFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * DTF trimestre anticipado del Banco de la República desde una fecha de
 * vigencia. `dtf_nominal` es el nominal trimestre anticipado (%) que se digita
 * y `valor` el efectivo anual (%) que se calcula a partir de él.
 */
#[Fillable(['fecha_vigencia', 'dtf_nominal', 'valor', 'fuente_normativa'])]
class TasaDtf extends Model
{
    /** @use HasFactory<TasaDtfFactory> */
    use HasFactory;

    protected $table = 'tasas_dtf';

    protected $primaryKey = 'id_tasa_dtf';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha_vigencia' => 'date:Y-m-d',
            'dtf_nominal' => 'decimal:4',
            'valor' => 'decimal:4',
        ];
    }

    /**
     * El nominal con coma decimal y al menos 2 decimales: 9,81.
     *
     * @return Attribute<string, never>
     */
    protected function nominalLegible(): Attribute
    {
        return Attribute::get(fn (): string => self::formatearNominal((float) $this->dtf_nominal));
    }

    /**
     * El efectivo anual con coma decimal y 2 decimales: 10,44.
     *
     * @return Attribute<string, never>
     */
    protected function efectivoLegible(): Attribute
    {
        return Attribute::get(fn (): string => number_format((float) $this->valor, 2, ',', '.'));
    }

    public static function formatearNominal(float $nominal): string
    {
        [$entero, $decimales] = explode(',', number_format($nominal, 4, ',', ''));

        return $entero.','.substr(rtrim($decimales, '0').'00', 0, max(2, strlen(rtrim($decimales, '0'))));
    }
}
