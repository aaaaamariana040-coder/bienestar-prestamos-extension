<?php

namespace App\Services;

use App\Enums\EstadoDesembolso;
use App\Models\Desembolso;
use App\Models\Prestamo;
use App\Models\Usuario;
use RuntimeException;

class DesembolsoService
{
    public function __construct(
        private readonly AuditoriaService $auditoriaService,
        private readonly CalendarioQuincenalService $calendario,
    ) {
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

        $primeraCuota = $prestamo->pendiente_desembolso ? $this->elegirPrimeraCuota($datos) : null;
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

        $detalle = "Préstamo #{$prestamo->id_prestamo} — tramo {$numeroTramo} por {$desembolso->valor}";
        $primeraCuota = $this->fijarPrimeraCuota($prestamo, $desembolso, $primeraCuota);

        if ($primeraCuota !== null) {
            $detalle .= " — primera cuota: {$primeraCuota['fecha']} (propuesta: {$primeraCuota['propuesta']}, cierre de novedades: {$primeraCuota['cierre']}; criterio: ".$this->criterioDelCierre().')';

            if ($primeraCuota['motivo'] !== null) {
                $detalle .= " — quincena elegida por el funcionario ({$primeraCuota['opcion']}): {$primeraCuota['motivo']}";
            }
        }

        $this->auditoriaService->registrar($actor, 'Registro de desembolso', $detalle);

        return $desembolso;
    }

    /**
     * Quincena elegida para la primera cuota: la propuesta por el cierre de novedades o, con
     * motivo, la anterior (solo si su vencimiento sigue siendo posterior al desembolso) o la
     * siguiente. Se valida antes de guardar el desembolso.
     *
     * @param  array<string, mixed>  $datos
     * @return array{fecha: string, propuesta: string, cierre: string, opcion: string, motivo: ?string}
     */
    private function elegirPrimeraCuota(array $datos): array
    {
        $opciones = $this->calendario->opcionesPrimeraCuota($datos['fecha']);
        $opcion = (string) ($datos['primera_cuota_opcion'] ?? 'propuesta');
        $motivo = trim((string) ($datos['motivo_primera_cuota'] ?? ''));

        if (! in_array($opcion, ['propuesta', 'anterior', 'siguiente'], true)) {
            throw new RuntimeException('La quincena elegida para la primera cuota no es válida.');
        }

        if ($opcion === 'anterior' && $opciones['anterior'] === null) {
            throw new RuntimeException('No se puede elegir la quincena anterior: su vencimiento no es posterior a la fecha del desembolso.');
        }

        if ($opcion !== 'propuesta' && $motivo === '') {
            throw new RuntimeException('Elegir una quincena distinta a la propuesta exige indicar el motivo.');
        }

        return [
            'fecha' => $opciones[$opcion]['vencimiento'],
            'propuesta' => $opciones['propuesta']['vencimiento'],
            'cierre' => $opciones[$opcion]['cierre'],
            'opcion' => $opcion,
            'motivo' => $opcion === 'propuesta' ? null : $motivo,
        ];
    }

    private function criterioDelCierre(): string
    {
        $dias = (int) config('quincena.cierre_novedades_dias_antes');

        return "cierre {$dias} día(s) antes del vencimiento; el desembolso el día del cierre ".(config('quincena.desembolso_dia_cierre_entra') ? 'sí' : 'no').' entra';
    }

    /**
     * Con el primer desembolso de un préstamo aprobado con la regla nueva, las cuotas
     * empiezan a descontarse en la quincena elegida (por defecto, la que deja el cierre de
     * novedades de nómina) y las demás siguen el calendario. Los préstamos anteriores (sin la
     * marca) y los desembolsos posteriores no cambian nada.
     *
     * @param  ?array{fecha: string, propuesta: string, cierre: string, opcion: string, motivo: ?string}  $primeraCuota
     * @return ?array{fecha: string, propuesta: string, cierre: string, opcion: string, motivo: ?string}
     */
    private function fijarPrimeraCuota(Prestamo $prestamo, Desembolso $desembolso, ?array $primeraCuota): ?array
    {
        if ($primeraCuota === null || ! $prestamo->pendiente_desembolso) {
            return null;
        }

        $cuotas = $prestamo->cuotas()->get();
        $fechas = $this->calendario->vencimientosDesde($primeraCuota['fecha'], $cuotas->count());

        foreach ($cuotas->values() as $i => $cuota) {
            $cuota->update(['fecha_vencimiento' => $fechas[$i]]);
        }

        $prestamo->update([
            'pendiente_desembolso' => false,
            'inicio_primera_cuota' => $primeraCuota['fecha'],
            'motivo_primera_cuota' => $primeraCuota['motivo'],
        ]);

        return $primeraCuota;
    }
}
