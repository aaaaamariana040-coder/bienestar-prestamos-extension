<?php

namespace App\Models;

use Database\Factories\TasaIntFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['id_tipo', 'anio', 'tasa_anual', 'fuente_normativa'])]
class TasaInt extends Model
{
    /** @use HasFactory<TasaIntFactory> */
    use HasFactory;

    protected $table = 'tasas_int';

    protected $primaryKey = 'id_tasa';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tasa_anual' => 'decimal:4',
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
