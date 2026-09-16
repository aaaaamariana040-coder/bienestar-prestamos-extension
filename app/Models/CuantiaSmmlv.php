<?php

namespace App\Models;

use Database\Factories\CuantiaSmmlvFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['id_tipo', 'tramo_desde_smmlv', 'tramo_hasta_smmlv', 'cuantia_maxima_smmlv'])]
class CuantiaSmmlv extends Model
{
    /** @use HasFactory<CuantiaSmmlvFactory> */
    use HasFactory;

    protected $table = 'cuantias_smmlv';

    protected $primaryKey = 'id_cuantia';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tramo_desde_smmlv' => 'decimal:2',
            'tramo_hasta_smmlv' => 'decimal:2',
            'cuantia_maxima_smmlv' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<TipoPrestamo, $this>
     */
    public function tipoPrestamo(): BelongsTo
    {
        return $this->belongsTo(TipoPrestamo::class, 'id_tipo', 'id_tipo');
    }
}
