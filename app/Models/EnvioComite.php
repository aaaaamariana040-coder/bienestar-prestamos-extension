<?php

namespace App\Models;

use Database\Factories\EnvioComiteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'id_solicitud',
    'cedula_usu',
    'enviado_en',
    'destinatarios',
    'copia',
    'asunto',
    'adjuntos',
    'datos',
    'mailer',
    'simulado',
    'es_reenvio',
])]
class EnvioComite extends Model
{
    /** @use HasFactory<EnvioComiteFactory> */
    use HasFactory;

    protected $table = 'envios_comite';

    protected $primaryKey = 'id_envio';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enviado_en' => 'datetime',
            'destinatarios' => 'array',
            'copia' => 'array',
            'adjuntos' => 'array',
            'datos' => 'array',
            'simulado' => 'boolean',
            'es_reenvio' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<SolicitudCredito, $this>
     */
    public function solicitud(): BelongsTo
    {
        return $this->belongsTo(SolicitudCredito::class, 'id_solicitud', 'id_solicitud');
    }

    /**
     * @return BelongsTo<Usuario, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'cedula_usu', 'cedula');
    }
}
