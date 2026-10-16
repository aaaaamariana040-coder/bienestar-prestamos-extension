<?php

namespace App\Models;

use App\Enums\EstadoCuota;
use App\Enums\EstadoPrestamo;
use Database\Factories\PrestamoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        ];
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

        return $this->cuotas()->where('estado', EstadoCuota::Pagada)->count();
    }

    /**
     * «Cuota 2 de 36»: pagadas sobre el total de cuotas actual (num_cuotas, que
     * se ajusta al reliquidar). Un préstamo cancelado siempre muestra el total.
     */
    public function getProgresoCuotasAttribute(): string
    {
        $total = (int) $this->num_cuotas;
        $pagadas = $this->estado === EstadoPrestamo::Cancelado ? $total : min($this->cuotasPagadas(), $total);

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
