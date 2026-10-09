<?php

namespace Database\Seeders;

use App\Models\TipoDocumento;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class TipoDocumentoSeeder extends Seeder
{
    /**
     * Catálogo inicial de tipos de documento. La lista definitiva por
     * modalidad la define Bienestar Social (reunión del 14 de octubre); el
     * catálogo se puede editar en la tabla tipos_documento sin tocar código.
     */
    use WithoutModelEvents;

    public function run(): void
    {
        foreach ([
            'Cédula',
            'Certificado laboral',
            'Certificado de tradición y libertad',
            'Promesa de compraventa',
            'Otros',
        ] as $nombre) {
            TipoDocumento::updateOrCreate(['nombre' => $nombre], ['activo' => true]);
        }
    }
}
