<?php

namespace App\Models;

use Database\Factories\ProveedorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['id_proveedor', 'nit', 'nombre_empresa', 'direccion', 'num_cuenta_bancaria'])]
class Proveedor extends Model
{
    /** @use HasFactory<ProveedorFactory> */
    use HasFactory;

    protected $table = 'proveedores';

    protected $primaryKey = 'id_proveedor';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @return HasMany<Desembolso, $this>
     */
    public function desembolsos(): HasMany
    {
        return $this->hasMany(Desembolso::class, 'id_proveedor', 'id_proveedor');
    }
}
