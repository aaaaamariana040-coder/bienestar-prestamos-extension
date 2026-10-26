<?php

namespace App\Enums;

enum ConceptoSeguro: string
{
    case VidaDeudores = 'vida_deudores';
    case IncendioTerremoto = 'incendio_terremoto';

    public function etiqueta(): string
    {
        return match ($this) {
            self::VidaDeudores => 'Vida de deudores',
            self::IncendioTerremoto => 'Incendio y terremoto',
        };
    }
}
