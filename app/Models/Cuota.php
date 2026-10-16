<?php

namespace App\Models;

use App\Enums\EstadoCuota;
use Database\Factories\CuotaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

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
     * Definición única de «cuota pagada»: la cuota quedó en estado Pagada
     * (por pago regular o por cancelación total).
     *
     * @param  Builder<Cuota>  $consulta
     */
    public function scopePagadas(Builder $consulta): void
    {
        $consulta->where('estado', EstadoCuota::Pagada);
    }

    /**
     * Variante «hasta una fecha» de las pagadas: además tienen un pago registrado
     * con fecha igual o anterior a la indicada. Con una fecha de hoy o posterior
     * da lo mismo que scopePagadas.
     *
     * @param  Builder<Cuota>  $consulta
     */
    public function scopePagadasHasta(Builder $consulta, Carbon $fecha): void
    {
        $consulta->pagadas()->whereHas('pagos', fn (Builder $pagos) => $pagos->whereDate('fecha_pago', '<=', $fecha->toDateString()));
    }

    /**
     * @return BelongsTo<Prestamo, $this>
     */
    public function prestamo(): BelongsTo
    {
        return $this->belongsTo(Prestamo::class, 'id_prestamo', 'id_prestamo');
    }

    /**
     * @return HasMany<Pago, $this>
     */
    public function pagos(): HasMany
    {
        return $this->hasMany(Pago::class, 'id_cuota', 'id_cuota');
    }
}
