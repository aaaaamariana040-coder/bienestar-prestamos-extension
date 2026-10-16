<?php

namespace App\Services;

use App\Enums\EstadoCuota;
use App\Enums\EstadoPrestamo;
use App\Enums\EstadoProcesoQuincena;
use App\Enums\ModoMarcado;
use App\Enums\OrigenPago;
use App\Enums\ReglaVencimiento;
use App\Enums\TipoProcesoQuincena;
use App\Models\Cuota;
use App\Models\ProcesoQuincena;
use App\Models\Usuario;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Proceso de la quincena: calcula las cuotas que vencen en una quincena de los
 * préstamos Vigente y En mora y, en modo real, las deja registradas para
 * confirmar o las paga a nombre del usuario Sistema usando PagoService.
 *
 * La regla de vencimiento, el modo de marcado y el resto de decisiones
 * abiertas de Bienestar salen de config/quincena.php (docs/quincena-automatica.md).
 */
class QuincenaService
{
    private const ESTADOS_ELEGIBLES = [EstadoPrestamo::Vigente, EstadoPrestamo::EnMora];

    public function __construct(
        private readonly CalendarioQuincenalService $calendario,
        private readonly PagoService $pagoService,
        private readonly AuditoriaService $auditoriaService,
    ) {
        //
    }

    /**
     * Simulación (por defecto) o proceso real de una quincena. La simulación no
     * escribe nada; el proceso real es idempotente por año y quincena.
     *
     * @return array{
     *     anio: int,
     *     quincena: int,
     *     inicio: string,
     *     corte: string,
     *     simulacion: bool,
     *     regla_vencimiento: string,
     *     modo_marcado: string,
     *     cuotas: list<array{id_cuota: int, id_prestamo: int, cedula_empleado: string, numero_cuota: int, fecha_vencimiento: string, valor_total: float}>,
     *     total: float,
     *     prestamos_incluidos: list<int>,
     *     excluidos: list<array{id_prestamo: int, id_cuota: int, numero_cuota: int, motivo: string}>,
     *     advertencias: list<string>,
     *     vencidas_anteriores: array{cuotas: int, prestamos: int},
     *     pagos_creados: int,
     *     ya_procesado: bool,
     *     proceso: ProcesoQuincena|null
     * }
     */
    public function procesar(int $anio, int $quincena, bool $simulacion = true): array
    {
        $regla = $this->reglaVencimiento();
        $modo = $this->modoMarcado();
        $periodo = $this->calendario->quincena($anio, $quincena);

        if ($simulacion) {
            return $this->calcular($periodo, $regla, $modo, true);
        }

        if ($periodo['inicio']->greaterThan($this->calendario->hoy())) {
            throw new RuntimeException("La quincena {$quincena} de {$anio} todavía no empieza: el proceso real solo corre sobre quincenas ya iniciadas.");
        }

        $sistema = Usuario::sistema();

        if ($existente = $this->procesoReal($anio, $quincena)) {
            return $this->resultadoDeProceso($existente);
        }

        try {
            return DB::transaction(fn () => $this->ejecutar($periodo, $regla, $modo, $sistema));
        } catch (UniqueConstraintViolationException) {
            return $this->resultadoDeProceso($this->procesoReal($anio, $quincena));
        }
    }

    public function reglaVencimiento(): ReglaVencimiento
    {
        $valor = config('quincena.regla_vencimiento');

        return ReglaVencimiento::tryFrom((string) $valor)
            ?? throw new InvalidArgumentException("quincena.regla_vencimiento no es válida: «{$valor}». Use calendario o cada_15_dias.");
    }

    public function modoMarcado(): ModoMarcado
    {
        $valor = config('quincena.modo_marcado');

        return ModoMarcado::tryFrom((string) $valor)
            ?? throw new InvalidArgumentException("quincena.modo_marcado no es válido: «{$valor}». Use confirmar o automatico.");
    }

    /**
     * @param  array{anio: int, numero: int, inicio: CarbonImmutable, corte: CarbonImmutable}  $periodo
     * @return array<string, mixed>
     */
    private function ejecutar(array $periodo, ReglaVencimiento $regla, ModoMarcado $modo, Usuario $sistema): array
    {
        $proceso = ProcesoQuincena::create([
            'anio' => $periodo['anio'],
            'numero_quincena' => $periodo['numero'],
            'tipo' => TipoProcesoQuincena::Real,
            'estado' => EstadoProcesoQuincena::PendienteConfirmacion,
            'modo_marcado' => $modo,
            'cedula_usu' => $sistema->cedula,
            'iniciado_en' => now(),
        ]);

        $resultado = $this->calcular($periodo, $regla, $modo, false);

        if ($modo === ModoMarcado::Automatico) {
            $fechaPago = $periodo['corte']->min($this->calendario->hoy())->toDateString();

            foreach ($resultado['cuotas'] as $fila) {
                $cuota = Cuota::with('prestamo')->findOrFail($fila['id_cuota']);

                $this->pagoService->registrarPagoCuota($cuota->prestamo, $cuota, $fechaPago, $sistema, OrigenPago::Automatico);
                $resultado['pagos_creados']++;
            }
        }

        $resultado['ya_procesado'] = false;

        $proceso->update([
            'estado' => $modo === ModoMarcado::Automatico ? EstadoProcesoQuincena::Completado : EstadoProcesoQuincena::PendienteConfirmacion,
            'finalizado_en' => now(),
            'resumen' => $resultado,
        ]);

        $this->auditoriaService->registrarComoSistema(
            'Proceso de quincena',
            "Quincena {$periodo['numero']} de {$periodo['anio']} ({$modo->value}): ".count($resultado['cuotas']).' cuotas, '.$resultado['pagos_creados'].' pagos creados',
        );

        $resultado['proceso'] = $proceso;

        return $resultado;
    }

    /**
     * Calcula sin escribir nada.
     *
     * @param  array{anio: int, numero: int, inicio: CarbonImmutable, corte: CarbonImmutable}  $periodo
     * @return array<string, mixed>
     */
    private function calcular(array $periodo, ReglaVencimiento $regla, ModoMarcado $modo, bool $simulacion): array
    {
        $incluidas = collect();
        $excluidos = [];

        foreach ($this->candidatas($periodo, $regla) as $cuota) {
            $motivo = $this->motivoDeExclusion($cuota);

            if ($motivo !== null) {
                $excluidos[] = [
                    'id_prestamo' => $cuota->id_prestamo,
                    'id_cuota' => $cuota->id_cuota,
                    'numero_cuota' => $cuota->numero_cuota,
                    'motivo' => $motivo,
                ];

                continue;
            }

            $incluidas->push($cuota);
        }

        $porPrestamo = $incluidas->groupBy('id_prestamo');

        $advertencias = $porPrestamo
            ->filter(fn (Collection $cuotas) => $cuotas->count() > 1)
            ->map(fn (Collection $cuotas, int $idPrestamo) => "El préstamo #{$idPrestamo} tiene {$cuotas->count()} cuotas que vencen en la misma quincena (cuotas ".$cuotas->pluck('numero_cuota')->implode(', ').').')
            ->values()
            ->all();

        return [
            'anio' => $periodo['anio'],
            'quincena' => $periodo['numero'],
            'inicio' => $periodo['inicio']->toDateString(),
            'corte' => $periodo['corte']->toDateString(),
            'simulacion' => $simulacion,
            'regla_vencimiento' => $regla->value,
            'modo_marcado' => $modo->value,
            'cuotas' => $incluidas->map(fn (Cuota $cuota) => [
                'id_cuota' => $cuota->id_cuota,
                'id_prestamo' => $cuota->id_prestamo,
                'cedula_empleado' => (string) $cuota->prestamo->cedula_empleado,
                'numero_cuota' => $cuota->numero_cuota,
                'fecha_vencimiento' => $cuota->fecha_vencimiento->toDateString(),
                'valor_total' => (float) $cuota->valor_total,
            ])->values()->all(),
            'total' => round((float) $incluidas->sum(fn (Cuota $cuota) => (float) $cuota->valor_total), 2),
            'prestamos_incluidos' => $porPrestamo->keys()->map(fn ($id) => (int) $id)->values()->all(),
            'excluidos' => $excluidos,
            'advertencias' => $advertencias,
            'vencidas_anteriores' => $this->vencidasAnteriores($periodo),
            'pagos_creados' => 0,
            'ya_procesado' => $this->procesoReal($periodo['anio'], $periodo['numero']) !== null,
            'proceso' => null,
        ];
    }

    /**
     * Cuotas cuya fecha de vencimiento cae en la quincena según la regla. Con
     * «calendario» es la quincena calendario de la fecha de vencimiento; con
     * «cada_15_dias» es el ciclo de 15 días, contado desde la autorización del
     * préstamo, que contiene el corte de la quincena.
     *
     * @param  array{anio: int, numero: int, inicio: CarbonImmutable, corte: CarbonImmutable}  $periodo
     * @return Collection<int, Cuota>
     */
    private function candidatas(array $periodo, ReglaVencimiento $regla): Collection
    {
        $consulta = Cuota::query()->with('prestamo')->orderBy('id_prestamo')->orderBy('numero_cuota');

        if ($regla === ReglaVencimiento::Calendario) {
            return $consulta
                ->whereDate('fecha_vencimiento', '>=', $periodo['inicio']->toDateString())
                ->whereDate('fecha_vencimiento', '<=', $periodo['corte']->toDateString())
                ->get();
        }

        return $consulta
            ->whereDate('fecha_vencimiento', '>=', $periodo['corte']->subDays(15)->toDateString())
            ->whereDate('fecha_vencimiento', '<=', $periodo['corte']->addDays(15)->toDateString())
            ->get()
            ->filter(function (Cuota $cuota) use ($periodo) {
                $autorizacion = CarbonImmutable::parse($cuota->prestamo->fecha_autorizacion->toDateString());

                return $this->cicloDe($autorizacion, CarbonImmutable::parse($cuota->fecha_vencimiento->toDateString()))
                    === $this->cicloDe($autorizacion, $periodo['corte']);
            })
            ->values();
    }

    /**
     * Número de ciclo de 15 días (1, 2, …) que contiene una fecha, contado desde
     * la autorización: los 15 días siguientes a la autorización son el ciclo 1,
     * y una cuota que vence justo al cumplirse el ciclo pertenece a él.
     */
    private function cicloDe(CarbonImmutable $autorizacion, CarbonImmutable $fecha): int
    {
        $dias = (int) round($autorizacion->startOfDay()->diffInDays($fecha->startOfDay(), false));

        return max(1, intdiv($dias + 14, 15));
    }

    private function motivoDeExclusion(Cuota $cuota): ?string
    {
        if (! in_array($cuota->prestamo->estado, self::ESTADOS_ELEGIBLES, true)) {
            return "Préstamo {$cuota->prestamo->estado->value}: solo se procesan préstamos Vigente y En mora";
        }

        return match ($cuota->estado) {
            EstadoCuota::Pendiente => null,
            EstadoCuota::Pagada => 'La cuota ya está pagada',
            EstadoCuota::Vencida => 'La cuota está en estado Vencida: no se paga desde el proceso',
        };
    }

    /**
     * Informativo: cuotas pendientes de préstamos elegibles que vencieron antes
     * de la quincena. El proceso no las paga.
     *
     * @param  array{anio: int, numero: int, inicio: CarbonImmutable, corte: CarbonImmutable}  $periodo
     * @return array{cuotas: int, prestamos: int}
     */
    private function vencidasAnteriores(array $periodo): array
    {
        $consulta = Cuota::query()
            ->where('estado', EstadoCuota::Pendiente)
            ->whereDate('fecha_vencimiento', '<', $periodo['inicio']->toDateString())
            ->whereHas('prestamo', fn ($q) => $q->whereIn('estado', self::ESTADOS_ELEGIBLES));

        return [
            'cuotas' => (clone $consulta)->count(),
            'prestamos' => (clone $consulta)->distinct()->count('id_prestamo'),
        ];
    }

    private function procesoReal(int $anio, int $quincena): ?ProcesoQuincena
    {
        return ProcesoQuincena::query()
            ->where('anio', $anio)
            ->where('numero_quincena', $quincena)
            ->where('tipo', TipoProcesoQuincena::Real)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function resultadoDeProceso(ProcesoQuincena $proceso): array
    {
        return array_merge($proceso->resumen ?? [], [
            'simulacion' => false,
            'ya_procesado' => true,
            'proceso' => $proceso,
        ]);
    }
}
