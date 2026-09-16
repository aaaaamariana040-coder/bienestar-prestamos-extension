<?php

namespace App\Models;

use App\Enums\CampoPresupuesto;
use Database\Factories\PresupuestoHistorialFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['id_presupuesto', 'campo', 'monto_anterior', 'monto_nuevo', 'motivo', 'cedula_usu', 'fecha'])]
class PresupuestoHistorial extends Model
{
    /** @use HasFactory<PresupuestoHistorialFactory> */
    use HasFactory;

    protected $table = 'presupuesto_historial';

    protected $primaryKey = 'id_historial';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'campo' => CampoPresupuesto::class,
            'monto_anterior' => 'decimal:2',
            'monto_nuevo' => 'decimal:2',
            'fecha' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PresupuestoAnual, $this>
     */
    public function presupuestoAnual(): BelongsTo
    {
        return $this->belongsTo(PresupuestoAnual::class, 'id_presupuesto', 'id_presupuesto');
    }

    /**
     * @return BelongsTo<Usuario, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'cedula_usu', 'cedula');
    }
}
