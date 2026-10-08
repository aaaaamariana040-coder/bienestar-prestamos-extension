<?php

namespace App\Models;

use Database\Factories\EmpleadoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'cedula',
    'nombre_completo',
    'tipo_vinculacion',
    'cargo',
    'dependencia',
    'salario',
    'fecha_vinculacion',
    'estado',
    'capacidad_endeudamiento',
])]
class Empleado extends Model
{
    /** @use HasFactory<EmpleadoFactory> */
    use HasFactory;

    protected $primaryKey = 'cedula';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'salario' => 'decimal:2',
            'capacidad_endeudamiento' => 'decimal:2',
            'fecha_vinculacion' => 'date',
        ];
    }
}
