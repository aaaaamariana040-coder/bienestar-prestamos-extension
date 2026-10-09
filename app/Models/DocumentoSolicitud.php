<?php

namespace App\Models;

use Database\Factories\DocumentoSolicitudFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'id_solicitud',
    'id_tipo_documento',
    'nombre_original',
    'ruta',
    'tamano',
    'tipo_mime',
    'cedula_usu',
])]
class DocumentoSolicitud extends Model
{
    /** @use HasFactory<DocumentoSolicitudFactory> */
    use HasFactory;

    public const MAX_POR_SOLICITUD = 15;

    public const TAMANO_MAXIMO_BYTES = 5 * 1024 * 1024;

    /**
     * Tipo MIME detectado en el contenido del archivo => extensión con la que se guarda.
     *
     * @var array<string, string>
     */
    public const MIMES_PERMITIDOS = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    protected $table = 'documentos_solicitud';

    protected $primaryKey = 'id_documento';

    /**
     * @return BelongsTo<SolicitudCredito, $this>
     */
    public function solicitud(): BelongsTo
    {
        return $this->belongsTo(SolicitudCredito::class, 'id_solicitud', 'id_solicitud');
    }

    /**
     * @return BelongsTo<TipoDocumento, $this>
     */
    public function tipoDocumento(): BelongsTo
    {
        return $this->belongsTo(TipoDocumento::class, 'id_tipo_documento', 'id_tipo_documento');
    }

    /**
     * @return BelongsTo<Usuario, $this>
     */
    public function subidoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'cedula_usu', 'cedula');
    }

    /**
     * Tamaño legible, por ejemplo «1,2 MB» o «340 KB».
     */
    public function getTamanoLegibleAttribute(): string
    {
        if ($this->tamano >= 1024 * 1024) {
            return number_format($this->tamano / (1024 * 1024), 1, ',', '.').' MB';
        }

        return number_format(max(1, (int) round($this->tamano / 1024)), 0, ',', '.').' KB';
    }
}
