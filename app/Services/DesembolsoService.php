<?php

namespace App\Services;

use App\Enums\EstadoDesembolso;
use App\Models\Desembolso;
use App\Models\Prestamo;
use App\Models\Usuario;
use RuntimeException;

class DesembolsoService
{
    public function __construct(private readonly AuditoriaService $auditoriaService)
    {
        //
    }

    /**
     * Modalidades que, según el Art. 11 y 23 del Acuerdo 19/2022, se desembolsan
     * en dos tramos iguales: el primero al liquidar el préstamo y el segundo tras
     * inspección técnico-administrativa que compruebe el 80% de inversión del
     * primero. Las demás modalidades tienen desembolso único.
     *
     * @var array<int, string>
     */
    private const MODALIDADES_POR_TRAMOS = [
        'Vivienda — construcción',
        'Vivienda — reparaciones o mejoras',
    ];

    public function tramosEsperados(Prestamo $prestamo): int
    {
        return in_array($prestamo->tipoPrestamo->nombre, self::MODALIDADES_POR_TRAMOS, true) ? 2 : 1;
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function registrar(Prestamo $prestamo, array $datos, Usuario $actor): Desembolso
    {
        $tramosEsperados = $this->tramosEsperados($prestamo);
        $numeroTramo = (int) $datos['numero_tramo'];

        if ($numeroTramo < 1 || $numeroTramo > $tramosEsperados) {
            throw new RuntimeException("La modalidad \"{$prestamo->tipoPrestamo->nombre}\" se desembolsa en {$tramosEsperados} tramo(s).");
        }

        if ($prestamo->desembolsos()->where('numero_tramo', $numeroTramo)->exists()) {
            throw new RuntimeException("El tramo {$numeroTramo} ya fue desembolsado.");
        }

        if ($numeroTramo === 2) {
            $primerTramo = $prestamo->desembolsos()->where('numero_tramo', 1)->first();

            if ($primerTramo === null || $primerTramo->estado !== EstadoDesembolso::Realizado) {
                throw new RuntimeException('No se puede desembolsar el segundo tramo sin haber realizado el primero.');
            }

            if (! ($datos['inspeccion_aprobada'] ?? false)) {
                throw new RuntimeException('El segundo tramo requiere la inspección técnico-administrativa aprobada que compruebe el 80% de inversión del primero (Art. 23).');
            }
        }

        $porcentaje = $tramosEsperados === 1 ? 100 : 50;

        $desembolso = $prestamo->desembolsos()->create([
            'id_proveedor' => $datos['id_proveedor'] ?? null,
            'numero_tramo' => $numeroTramo,
            'porcentaje' => $porcentaje,
            'fecha' => $datos['fecha'],
            'valor' => round((float) $prestamo->monto_aprobado * $porcentaje / 100, 2),
            'requiere_inspeccion' => $numeroTramo === 2,
            'inspeccion_aprobada' => $numeroTramo === 2 ? true : null,
            'estado' => EstadoDesembolso::Realizado,
        ]);

        $this->auditoriaService->registrar(
            $actor,
            'Registro de desembolso',
            "Préstamo #{$prestamo->id_prestamo} — tramo {$numeroTramo} por {$desembolso->valor}",
        );

        return $desembolso;
    }
}
