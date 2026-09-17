<?php

namespace App\Models;

use App\Enums\EstadoCuota;
use Database\Factories\CuotaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['numero_cuota', 'fecha_vencimiento', 'valor_capital', 'valor_interes', 'valor_total', 'estado'])]
class Cuota extends Model
{
    /** @use HasFactory<CuotaFactory> */
    use HasFactory;

    protected $table = 'cuotas';

    protected $primaryKey = 'id_cuota';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha_vencimiento' => 'date',
            'valor_capital' => 'decimal:2',
            'valor_interes' => 'decimal:2',
            'valor_total' => 'decimal:2',
            'estado' => EstadoCuota::class,
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
