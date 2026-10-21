<?php

namespace App\Models;

use App\Enums\EstadoSolicitud;
use Database\Factories\SolicitudCreditoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'cedula_empleado',
    'id_tipo',
    'monto_solicitado',
    'plazo_solicitado',
    'fecha_solicitud',
    'estado',
    'fecha_decision',
    'motivo_rechazo',
])]
class SolicitudCredito extends Model
{
    /** @use HasFactory<SolicitudCreditoFactory> */
    use HasFactory;

    protected $table = 'solicitudes_credito';

    protected $primaryKey = 'id_solicitud';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'monto_solicitado' => 'decimal:2',
            'fecha_solicitud' => 'date',
            'fecha_decision' => 'date',
            'estado' => EstadoSolicitud::class,
        ];
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
     * @return HasOne<Prestamo, $this>
     */
    public function prestamo(): HasOne
    {
        return $this->hasOne(Prestamo::class, 'id_solicitud', 'id_solicitud');
    }

    protected function plazoSolicitadoLegible(): Attribute
    {
        return Attribute::get(fn (): string => TipoPrestamo::plazoEnQuincenas((int) $this->plazo_solicitado));
    }

    /**
     * Envíos al comité, del más reciente al más antiguo.
     *
     * @return HasMany<EnvioComite, $this>
     */
    public function envios(): HasMany
    {
        return $this->hasMany(EnvioComite::class, 'id_solicitud', 'id_solicitud')->orderByDesc('enviado_en')->orderByDesc('id_envio');
    }

    /**
     * @return HasOne<EnvioComite, $this>
     */
    public function ultimoEnvio(): HasOne
    {
        return $this->hasOne(EnvioComite::class, 'id_solicitud', 'id_solicitud')->latestOfMany('id_envio');
    }

    /**
     * @return HasMany<DocumentoSolicitud, $this>
     */
    public function documentos(): HasMany
    {
        return $this->hasMany(DocumentoSolicitud::class, 'id_solicitud', 'id_solicitud');
    }
}
