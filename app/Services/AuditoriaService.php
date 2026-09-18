<?php

namespace App\Services;

use App\Models\Auditoria;
use App\Models\Usuario;

class AuditoriaService
{
    public function registrar(Usuario $actor, string $funcion, ?string $detalle = null): Auditoria
    {
        $ahora = now();

        return Auditoria::create([
            'cedula_usu' => $actor->cedula,
            'fecha' => $ahora->toDateString(),
            'hora' => $ahora->toTimeString(),
            'funcion_realizada' => $funcion,
            'detalle' => $detalle,
        ]);
    }
}
