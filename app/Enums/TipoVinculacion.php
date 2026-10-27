<?php

namespace App\Enums;

enum TipoVinculacion: string
{
    case Vinculado = 'vinculado';
    case Docente = 'docente';
    case DocenteOcasional = 'docente_ocasional';
    case Jubilado = 'jubilado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Vinculado => 'Vinculado',
            self::Docente => 'Docente',
            self::DocenteOcasional => 'Docente ocasional',
            self::Jubilado => 'Jubilado',
        };
    }

    /**
     * Valores guardados en la base, en el orden de los formularios y listas.
     *
     * @return list<string>
     */
    public static function valores(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Valor guardado => nombre para mostrar.
     *
     * @return array<string, string>
     */
    public static function opciones(): array
    {
        $opciones = [];

        foreach (self::cases() as $tipo) {
            $opciones[$tipo->value] = $tipo->etiqueta();
        }

        return $opciones;
    }
}
