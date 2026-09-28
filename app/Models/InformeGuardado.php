<?php

namespace App\Models;

use App\Enums\TipoInforme;
use Database\Factories\InformeGuardadoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Copia inmutable del resultado de un reporte (RF-009) tal como estaba el día
 * en que se generó, para poder consultarla después (RF-010).
 */
#[Fillable(['tipo', 'parametros', 'datos', 'fecha_corte', 'generado_por'])]
class InformeGuardado extends Model
{
    /** @use HasFactory<InformeGuardadoFactory> */
    use HasFactory;

    protected $table = 'informes_guardados';

    protected $primaryKey = 'id_informe';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo' => TipoInforme::class,
            'parametros' => 'array',
            'datos' => 'array',
            'fecha_corte' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Los informes guardados son inmutables y no se pueden modificar.');
        });

        static::deleting(function (): never {
            throw new LogicException('Los informes guardados son inmutables y no se pueden eliminar.');
        });
    }

    /**
     * @return BelongsTo<Usuario, $this>
     */
    public function generadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'generado_por', 'cedula');
    }
}
