<?php

namespace App\Models;

use App\Enums\EstadoDesembolso;
use Database\Factories\DesembolsoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'id_prestamo',
    'id_proveedor',
    'numero_tramo',
    'porcentaje',
    'fecha',
    'valor',
    'requiere_inspeccion',
    'inspeccion_aprobada',
    'estado',
])]
class Desembolso extends Model
{
    /** @use HasFactory<DesembolsoFactory> */
    use HasFactory;

    protected $table = 'desembolsos';

    protected $primaryKey = 'id_desembolso';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'porcentaje' => 'decimal:2',
            'fecha' => 'date',
            'valor' => 'decimal:2',
            'requiere_inspeccion' => 'boolean',
            'inspeccion_aprobada' => 'boolean',
            'estado' => EstadoDesembolso::class,
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
     * @return BelongsTo<Proveedor, $this>
     */
    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'id_proveedor', 'id_proveedor');
    }
}
