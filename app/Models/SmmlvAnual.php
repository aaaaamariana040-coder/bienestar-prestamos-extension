<?php

namespace App\Models;

use Database\Factories\SmmlvAnualFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['anio', 'valor', 'fuente_normativa'])]
class SmmlvAnual extends Model
{
    /** @use HasFactory<SmmlvAnualFactory> */
    use HasFactory;

    protected $table = 'smmlv_anual';

    protected $primaryKey = 'id_smmlv';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valor' => 'decimal:2',
        ];
    }

    public static function valorParaAnio(int $anio): ?float
    {
        $valor = static::where('anio', $anio)->value('valor');

        return $valor !== null ? (float) $valor : null;
    }
}
