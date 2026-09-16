<?php

namespace App\Models;

use Database\Factories\TasaVehiculoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['plazo_anios', 'es_primera_solicitud', 'puntos_adicionales_ipc'])]
class TasaVehiculo extends Model
{
    /** @use HasFactory<TasaVehiculoFactory> */
    use HasFactory;

    protected $table = 'tasas_vehiculo';

    protected $primaryKey = 'id_tasa_vehiculo';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'es_primera_solicitud' => 'boolean',
            'puntos_adicionales_ipc' => 'decimal:2',
        ];
    }
}
