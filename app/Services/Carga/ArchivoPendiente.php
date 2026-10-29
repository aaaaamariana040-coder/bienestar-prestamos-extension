<?php

namespace App\Services\Carga;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Archivo subido que espera la confirmación de una carga: vive en el disco privado, en una
 * carpeta propia de la carga, con un nombre al azar (el token), y se borra al confirmar o
 * cancelar.
 */
class ArchivoPendiente
{
    public function __construct(private readonly string $carpeta)
    {
        //
    }

    public function guardar(string $contenido): string
    {
        $token = Str::random(40);

        Storage::disk('local')->put($this->ruta($token), $contenido);

        return $token;
    }

    public function existe(string $token): bool
    {
        return self::tokenValido($token) && Storage::disk('local')->exists($this->ruta($token));
    }

    public function leer(string $token): string
    {
        return (string) Storage::disk('local')->get($this->ruta($token));
    }

    /**
     * Borra del disco únicamente el archivo de ese token.
     */
    public function borrar(string $token): void
    {
        if (self::tokenValido($token)) {
            Storage::disk('local')->delete($this->ruta($token));
        }
    }

    public static function tokenValido(string $token): bool
    {
        return preg_match('/^[A-Za-z0-9]{40}$/', $token) === 1;
    }

    private function ruta(string $token): string
    {
        return $this->carpeta.'/'.$token.'.csv';
    }
}
