<?php

namespace App\Models;

use App\Enums\EstadoPrestamo;
use App\Enums\TipoVinculacion;
use Database\Factories\EmpleadoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'cedula',
    'nombre_completo',
    'tipo_vinculacion',
    'de_carrera',
    'cargo',
    'dependencia',
    'salario',
    'fecha_vinculacion',
    'estado',
    'capacidad_endeudamiento',
    'origen',
    'lote_carga',
])]
class Empleado extends Model
{
    /** @use HasFactory<EmpleadoFactory> */
    use HasFactory;

    public const ORIGEN_CARGA_INICIAL = 'carga_inicial';

    protected $primaryKey = 'cedula';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo_vinculacion' => TipoVinculacion::class,
            'de_carrera' => 'boolean',
            'salario' => 'decimal:2',
            'capacidad_endeudamiento' => 'decimal:2',
            'fecha_vinculacion' => 'date',
        ];
    }

    /**
     * @return HasMany<Prestamo, $this>
     */
    public function prestamos(): HasMany
    {
        return $this->hasMany(Prestamo::class, 'cedula_empleado', 'cedula');
    }

    /**
     * Préstamos que siguen comprometiendo la capacidad de endeudamiento:
     * Vigente y En mora.
     *
     * @return HasMany<Prestamo, $this>
     */
    public function prestamosActivos(): HasMany
    {
        return $this->prestamos()->whereIn('estado', [EstadoPrestamo::Vigente, EstadoPrestamo::EnMora]);
    }
}
