<?php

namespace App\Models;

use App\Enums\EstadoCuota;
use App\Enums\EstadoPrestamo;
use Database\Factories\PrestamoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

#[Fillable([
    'id_solicitud',
    'cedula_empleado',
    'id_tipo',
    'id_tasa',
    'tasa_efectiva_anual',
    'monto_aprobado',
    'num_cuotas',
    'cuota_periodica',
    'fecha_autorizacion',
    'estado',
    'pendiente_desembolso',
    'inicio_primera_cuota',
])]
class Prestamo extends Model
{
    /** @use HasFactory<PrestamoFactory> */
    use HasFactory;

    protected $table = 'prestamos';

    protected $primaryKey = 'id_prestamo';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tasa_efectiva_anual' => 'decimal:4',
            'monto_aprobado' => 'decimal:2',
            'cuota_periodica' => 'decimal:2',
            'fecha_autorizacion' => 'date',
            'estado' => EstadoPrestamo::class,
            'pendiente_desembolso' => 'boolean',
            'inicio_primera_cuota' => 'date',
        ];
    }

    public const MENSAJE_PENDIENTE_DESEMBOLSO = 'No se puede registrar: el préstamo aún no se ha desembolsado. Los pagos y abonos se registran después del desembolso.';

    /**
     * Un pago o abono solo existe sobre un crédito efectivo: mientras el préstamo espera
     * su desembolso no hay saldo al cual aplicarlo.
     *
     * @throws RuntimeException
     */
    public function asegurarDesembolsado(): void
    {
        if ($this->pendiente_desembolso) {
            throw new RuntimeException(self::MENSAJE_PENDIENTE_DESEMBOLSO);
        }
    }

    /**
     * Préstamos cuyas cuotas ya se cobran: se excluyen los aprobados que aún esperan su
     * desembolso (las cuotas empiezan a descontarse a partir de él).
     *
     * @param  Builder<Prestamo>  $consulta
     */
    public function scopeEnCobro(Builder $consulta): void
    {
        $consulta->where('pendiente_desembolso', false);
    }

    /**
     * @return BelongsTo<SolicitudCredito, $this>
     */
    public function solicitudCredito(): BelongsTo
    {
        return $this->belongsTo(SolicitudCredito::class, 'id_solicitud', 'id_solicitud');
    }

    /**
     * @return BelongsTo<Empleado, $this>
     */
    public function empleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class, 'cedula_empleado', 'cedula');
    }

    /**
     * @return BelongsTo<TipoPrestamo, $this>
     */
    public function tipoPrestamo(): BelongsTo
    {
        return $this->belongsTo(TipoPrestamo::class, 'id_tipo', 'id_tipo');
    }

    /**
     * @return BelongsTo<TasaInt, $this>
     */
    public function tasaInt(): BelongsTo
    {
        return $this->belongsTo(TasaInt::class, 'id_tasa', 'id_tasa');
    }

    /**
     * @return HasMany<Cuota, $this>
     */
    public function cuotas(): HasMany
    {
        return $this->hasMany(Cuota::class, 'id_prestamo', 'id_prestamo')->orderBy('numero_cuota');
    }

    /**
     * @return HasMany<Desembolso, $this>
     */
    public function desembolsos(): HasMany
    {
        return $this->hasMany(Desembolso::class, 'id_prestamo', 'id_prestamo')->orderBy('numero_tramo');
    }

    /**
     * Cuántas cuotas lleva pagadas. Usa las cuotas ya cargadas si las hay.
     */
    public function cuotasPagadas(): int
    {
        if ($this->relationLoaded('cuotas')) {
            return $this->cuotas->where('estado', EstadoCuota::Pagada)->count();
        }

        return $this->cuotas()->pagadas()->count();
    }

    /**
     * Tope común de «Cuota X de Y»: nunca se muestran más cuotas pagadas que el total
     * actual (el total cambia al reliquidar). Lo usan progreso_cuotas y el Historial de pagos.
     */
    public static function toparAlTotal(int $pagadas, int $total): int
    {
        return min($pagadas, $total);
    }

    /**
     * «Cuota 2 de 36»: pagadas sobre el total de cuotas actual (num_cuotas, que
     * se ajusta al reliquidar). Un préstamo cancelado siempre muestra el total.
     */
    public function getProgresoCuotasAttribute(): string
    {
        $total = (int) $this->num_cuotas;
        $pagadas = $this->estado === EstadoPrestamo::Cancelado ? $total : self::toparAlTotal($this->cuotasPagadas(), $total);

        return "Cuota {$pagadas} de {$total}";
    }

    /**
     * @return HasMany<Pago, $this>
     */
    public function pagos(): HasMany
    {
        return $this->hasMany(Pago::class, 'id_prestamo', 'id_prestamo')->orderByDesc('fecha_pago');
    }

    /**
     * @return HasMany<Reliquidacion, $this>
     */
    public function reliquidaciones(): HasMany
    {
        return $this->hasMany(Reliquidacion::class, 'id_prestamo', 'id_prestamo')->orderByDesc('fecha');
    }

    /**
     * @return HasMany<AbonoCesantias, $this>
     */
    public function abonosCesantias(): HasMany
    {
        return $this->hasMany(AbonoCesantias::class, 'id_prestamo', 'id_prestamo')->orderByDesc('fecha_abono');
    }
}
