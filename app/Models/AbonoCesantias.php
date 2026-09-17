<?php

namespace App\Models;

use Database\Factories\AbonoCesantiasFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['id_prestamo', 'anio', 'porcentaje', 'monto', 'fecha_abono'])]
class AbonoCesantias extends Model
{
    /** @use HasFactory<AbonoCesantiasFactory> */
    use HasFactory;

    protected $table = 'abonos_cesantias';

    protected $primaryKey = 'id_abono';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'porcentaje' => 'decimal:2',
            'monto' => 'decimal:2',
            'fecha_abono' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Prestamo, $this>
     */
    public function prestamo(): BelongsTo
    {
        return $this->belongsTo(Prestamo::class, 'id_prestamo', 'id_prestamo');
    }
}
