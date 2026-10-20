<?php

namespace App\Models;

use App\Enums\EstadoProcesoQuincena;
use App\Enums\ModoMarcado;
use App\Enums\TipoProcesoQuincena;
use Database\Factories\ProcesoQuincenaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'anio',
    'numero_quincena',
    'tipo',
    'estado',
    'modo_marcado',
    'cedula_usu',
    'iniciado_en',
    'finalizado_en',
    'resumen',
])]
class ProcesoQuincena extends Model
{
    /** @use HasFactory<ProcesoQuincenaFactory> */
    use HasFactory;

    protected $table = 'procesos_quincena';

    protected $primaryKey = 'id_proceso';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'anio' => 'integer',
            'numero_quincena' => 'integer',
            'tipo' => TipoProcesoQuincena::class,
            'estado' => EstadoProcesoQuincena::class,
            'modo_marcado' => ModoMarcado::class,
            'iniciado_en' => 'datetime',
            'finalizado_en' => 'datetime',
            'resumen' => 'array',
        ];
    }

    /**
     * Procesos reales ya confirmados o completados, del más reciente al más antiguo.
     *
     * @param  Builder<ProcesoQuincena>  $consulta
     */
    public function scopeConfirmados(Builder $consulta): void
    {
        $consulta->where('tipo', TipoProcesoQuincena::Real)
            ->where('estado', EstadoProcesoQuincena::Completado)
            ->orderByDesc('anio')
            ->orderByDesc('numero_quincena');
    }

    /**
     * @return HasMany<Pago, $this>
     */
    public function pagos(): HasMany
    {
        return $this->hasMany(Pago::class, 'id_proceso_quincena', 'id_proceso');
    }

    /**
     * @return BelongsTo<Usuario, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'cedula_usu', 'cedula');
    }
}
