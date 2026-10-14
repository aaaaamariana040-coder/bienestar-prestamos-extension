<?php

namespace App\Models;

use App\Enums\EstadoProcesoQuincena;
use App\Enums\ModoMarcado;
use App\Enums\TipoProcesoQuincena;
use Database\Factories\ProcesoQuincenaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
     * @return BelongsTo<Usuario, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'cedula_usu', 'cedula');
    }
}
