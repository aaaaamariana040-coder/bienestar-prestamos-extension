<?php

namespace App\Models;

use Database\Factories\TipoDocumentoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['nombre', 'activo'])]
class TipoDocumento extends Model
{
    /** @use HasFactory<TipoDocumentoFactory> */
    use HasFactory;

    protected $table = 'tipos_documento';

    protected $primaryKey = 'id_tipo_documento';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    /**
     * @return HasMany<DocumentoSolicitud, $this>
     */
    public function documentos(): HasMany
    {
        return $this->hasMany(DocumentoSolicitud::class, 'id_tipo_documento', 'id_tipo_documento');
    }
}
