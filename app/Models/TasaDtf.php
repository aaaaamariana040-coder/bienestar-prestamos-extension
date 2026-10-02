<?php

namespace App\Models;

use Database\Factories\TasaDtfFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['fecha_vigencia', 'valor', 'fuente_normativa'])]
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
            'fecha_vigencia' => 'date',
            'valor' => 'decimal:4',
        ];
    }
}
