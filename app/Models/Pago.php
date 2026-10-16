<?php

namespace App\Models;

use App\Enums\OrigenPago;
use App\Enums\TipoPago;
use Database\Factories\PagoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'id_prestamo',
    'id_cuota',
    'fecha_pago',
    'monto',
    'tipo',
    'origen',
    'monto_aplicado_interes',
    'monto_aplicado_capital',
    'cedula_usu',
])]
class Pago extends Model
{
    /** @use HasFactory<PagoFactory> */
    use HasFactory;

    protected $table = 'pagos';

    protected $primaryKey = 'id_pago';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha_pago' => 'date',
            'monto' => 'decimal:2',
            'tipo' => TipoPago::class,
            'origen' => OrigenPago::class,
            'monto_aplicado_interes' => 'decimal:2',
            'monto_aplicado_capital' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Prestamo, $this>
     */
    public function prestamo(): BelongsTo
    {
        return $this->belongsTo(Prestamo::class, 'id_prestamo', 'id_prestamo');
    }

    /**
     * @return BelongsTo<Cuota, $this>
     */
    public function cuota(): BelongsTo
    {
        return $this->belongsTo(Cuota::class, 'id_cuota', 'id_cuota');
    }

    /**
     * @return BelongsTo<Usuario, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'cedula_usu', 'cedula');
    }
}
