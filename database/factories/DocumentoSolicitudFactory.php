<?php

namespace Database\Factories;

use App\Models\DocumentoSolicitud;
use App\Models\SolicitudCredito;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DocumentoSolicitud>
 */
class DocumentoSolicitudFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id_solicitud' => SolicitudCredito::factory(),
            'id_tipo_documento' => TipoDocumento::factory(),
            'nombre_original' => fake()->word().'.pdf',
            'ruta' => 'documentos-solicitud/prueba/'.Str::random(40).'.pdf',
            'tamano' => fake()->numberBetween(10_000, 4_000_000),
            'tipo_mime' => 'application/pdf',
            'cedula_usu' => Usuario::factory(),
        ];
    }
}
