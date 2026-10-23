<?php

namespace App\Models;

use App\Enums\MotivoReliquidacion;
use Database\Factories\ReliquidacionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['id_prestamo', 'motivo', 'opcion_elegida', 'automatica', 'saldo_anterior', 'saldo_nuevo', 'tasa_aplicada', 'fecha', 'cedula_usu'])]
class Reliquidacion extends Model
{
    /** @use HasFactory<ReliquidacionFactory> */
    use HasFactory;

    protected $table = 'reliquidaciones';

    protected $primaryKey = 'id_reliquidacion';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'motivo' => MotivoReliquidacion::class,
            'automatica' => 'boolean',
            'saldo_anterior' => 'decimal:2',
            'saldo_nuevo' => 'decimal:2',
            'tasa_aplicada' => 'decimal:4',
            'fecha' => 'date',
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
     * @return BelongsTo<Usuario, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'cedula_usu', 'cedula');
    }
}
