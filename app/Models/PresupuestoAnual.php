<?php

namespace App\Models;

use Database\Factories\PresupuestoAnualFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['anio', 'id_tipo', 'monto_asignado', 'monto_comprometido'])]
class PresupuestoAnual extends Model
{
    /** @use HasFactory<PresupuestoAnualFactory> */
    use HasFactory;

    protected $table = 'presupuestos_anuales';

    protected $primaryKey = 'id_presupuesto';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'monto_asignado' => 'decimal:2',
            'monto_comprometido' => 'decimal:2',
            'monto_disponible' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $presupuesto): void {
            $presupuesto->monto_disponible = $presupuesto->monto_asignado - $presupuesto->monto_comprometido;
        });
    }

    /**
     * Lo que queda por prestar: asignado menos comprometido.
     */
    protected function montoDisponibleCalculado(): Attribute
    {
        return Attribute::get(fn (): float => round((float) $this->monto_asignado - (float) $this->monto_comprometido, 2));
    }

    /**
     * @return BelongsTo<TipoPrestamo, $this>
     */
    public function tipoPrestamo(): BelongsTo
    {
        return $this->belongsTo(TipoPrestamo::class, 'id_tipo', 'id_tipo');
    }

    /**
     * @return HasMany<PresupuestoHistorial, $this>
     */
    public function historial(): HasMany
    {
        return $this->hasMany(PresupuestoHistorial::class, 'id_presupuesto', 'id_presupuesto')->orderByDesc('fecha');
    }
}
