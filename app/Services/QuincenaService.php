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
use App\Models\Prestamo;
use App\Models\ProcesoQuincena;
use App\Models\Usuario;
use BackedEnum;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Proceso de la quincena: calcula las cuotas que vencen en una quincena de los
 * préstamos Vigente y En mora. Con modo «confirmar» una persona revisa la vista
 * previa y las paga con confirmar(); con modo «automatico» el proceso real las
 * paga a nombre del usuario Sistema. En ambos casos usa PagoService.
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
     * escribe nada. El proceso real solo escribe con modo «automatico» y es
     * idempotente por año y quincena; con modo «confirmar» devuelve la vista
     * previa sin escribir (simulacion = true), porque el proceso nace al
     * confirmar la quincena con confirmar().
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

        if ($modo === ModoMarcado::Confirmar) {
            return $this->calcular($periodo, $regla, $modo, true);
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

    /**
     * Confirma una quincena: paga las cuotas que la persona vio en la vista
     * previa, salvo las que excluyó con motivo, y deja el proceso como
     * Completado. Todo ocurre en una sola transacción que empieza insertando la
     * fila del proceso; la restricción única impide la doble confirmación,
     * también la concurrente.
     *
     * Antes de pagar recalcula la quincena en vivo y la compara con lo que la
     * persona vio ($vistas: id_cuota => valor_total). Si el conjunto cambió (un
     * pago manual, una reliquidación, una cancelación) no paga nada y lanza
     * QuincenaCambioException. Una cuota excluida queda Pendiente y aparece como
     * vencida anterior en la quincena siguiente.
     *
     * @param  array<int|string, float|int|string>  $vistas
     * @param  array<int|string, string>  $exclusiones  id_cuota => motivo
     *
     * @throws QuincenaYaConfirmadaException
     * @throws QuincenaCambioException
     * @throws InvalidArgumentException si una exclusión no tiene motivo o no estaba en la vista previa
     * @throws RuntimeException si la quincena no empezó o no tiene cuotas para confirmar
     */
    public function confirmar(int $anio, int $quincena, Usuario $actor, array $vistas, array $exclusiones = []): ProcesoQuincena
    {
        $regla = $this->reglaVencimiento();
        $periodo = $this->calendario->quincena($anio, $quincena);

        if ($periodo['inicio']->greaterThan($this->calendario->hoy())) {
            throw new RuntimeException("La quincena {$quincena} de {$anio} todavía no empieza: solo se confirman quincenas ya iniciadas.");
        }

        $vistas = collect($vistas)->mapWithKeys(fn ($valor, $id) => [(int) $id => round((float) $valor, 2)])->all();
        $exclusiones = collect($exclusiones)->mapWithKeys(fn ($motivo, $id) => [(int) $id => trim((string) $motivo)])->all();

        foreach ($exclusiones as $idCuota => $motivo) {
            if (! array_key_exists($idCuota, $vistas)) {
                throw new InvalidArgumentException("La cuota #{$idCuota} no estaba en la vista previa: no se puede excluir.");
            }

            if ($motivo === '') {
                throw new InvalidArgumentException("La cuota #{$idCuota} se excluyó sin motivo: el motivo es obligatorio.");
            }
        }

        try {
            return DB::transaction(fn () => $this->confirmarEnTransaccion($periodo, $regla, $actor, $vistas, $exclusiones));
        } catch (UniqueConstraintViolationException $e) {
            $confirmado = $this->procesoConfirmado($anio, $quincena);

            throw $confirmado ? new QuincenaYaConfirmadaException($confirmado) : $e;
        }
    }

    /**
     * Quincenas ya iniciadas, sin proceso real confirmado y con al menos una
     * cuota candidata, de la más antigua a la más reciente. Es lo que alimenta
     * el aviso de Inicio y del menú mientras el programador siga apagado.
     *
     * @return list<array{anio: int, numero: int, inicio: string, corte: string, cuotas: int, total: float}>
     */
    public function quincenasPendientes(): array
    {
        $regla = $this->reglaVencimiento();
        $actual = $this->calendario->quincenaActual();

        $cuotas = Cuota::query()
            ->with('prestamo')
            ->where('estado', EstadoCuota::Pendiente)
            ->whereDate('fecha_vencimiento', '<=', $actual['corte']->addDays(15)->toDateString())
            ->whereHas('prestamo', fn ($q) => $q->whereIn('estado', self::ESTADOS_ELEGIBLES)->enCobro())
            ->get();

        if ($cuotas->isEmpty()) {
            return [];
        }

        $confirmadas = ProcesoQuincena::query()
            ->where('tipo', TipoProcesoQuincena::Real)
            ->where('estado', EstadoProcesoQuincena::Completado)
            ->get(['anio', 'numero_quincena'])
            ->mapWithKeys(fn (ProcesoQuincena $proceso) => ["{$proceso->anio}-{$proceso->numero_quincena}" => true]);

        $periodo = $this->calendario->quincenaAnterior($this->calendario->quincenaDeFecha($cuotas->min(fn (Cuota $cuota) => $cuota->fecha_vencimiento->toDateString())));
        $pendientes = [];

        while ($periodo['inicio']->lessThanOrEqualTo($actual['inicio'])) {
            $delPeriodo = $cuotas->filter(fn (Cuota $cuota) => $this->cuotaEnQuincena($cuota, $periodo, $regla));

            if ($delPeriodo->isNotEmpty() && ! $confirmadas->has("{$periodo['anio']}-{$periodo['numero']}")) {
                $pendientes[] = [
                    'anio' => $periodo['anio'],
                    'numero' => $periodo['numero'],
                    'inicio' => $periodo['inicio']->toDateString(),
                    'corte' => $periodo['corte']->toDateString(),
                    'cuotas' => $delPeriodo->count(),
                    'total' => round((float) $delPeriodo->sum(fn (Cuota $cuota) => (float) $cuota->valor_total), 2),
                ];
            }

            $periodo = $this->calendario->quincenaSiguiente($periodo);
        }

        return $pendientes;
    }

    public function procesoConfirmado(int $anio, int $quincena): ?ProcesoQuincena
    {
        return ProcesoQuincena::query()
            ->with('usuario')
            ->where('anio', $anio)
            ->where('numero_quincena', $quincena)
            ->where('tipo', TipoProcesoQuincena::Real)
            ->where('estado', EstadoProcesoQuincena::Completado)
            ->first();
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
        $fechaPago = $this->fechaDePago($periodo);

        foreach ($resultado['cuotas'] as $fila) {
            $cuota = Cuota::with('prestamo')->findOrFail($fila['id_cuota']);

            $this->pagoService->registrarPagoCuota($cuota->prestamo, $cuota, $fechaPago, $sistema, OrigenPago::Automatico, $proceso->id_proceso);
            $resultado['pagos_creados']++;
        }

        $resultado['ya_procesado'] = false;

        $proceso->update([
            'estado' => EstadoProcesoQuincena::Completado,
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
    private function calcular(array $periodo, ReglaVencimiento $regla, ModoMarcado $modo, bool $simulacion, bool $bloquear = false): array
    {
        $incluidas = collect();
        $excluidos = [];

        foreach ($this->candidatas($periodo, $regla, $bloquear) as $cuota) {
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
                'nombre_empleado' => (string) $cuota->prestamo->empleado?->nombre_completo,
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
            'ya_procesado' => $this->procesoConfirmado($periodo['anio'], $periodo['numero']) !== null,
            'proceso' => null,
        ];
    }

    /**
     * Cuotas cuya fecha de vencimiento cae en la quincena según la regla. Con
     * «calendario» es la quincena calendario de la fecha de vencimiento; con
     * «cada_15_dias» es el ciclo de 15 días, contado desde la autorización del
     * préstamo, que contiene el corte de la quincena. Con $bloquear las filas
     * se leen con bloqueo de escritura (solo dentro de una transacción).
     *
     * @param  array{anio: int, numero: int, inicio: CarbonImmutable, corte: CarbonImmutable}  $periodo
     * @return Collection<int, Cuota>
     */
    private function candidatas(array $periodo, ReglaVencimiento $regla, bool $bloquear = false): Collection
    {
        // Las cuotas de un préstamo que aún no se desembolsa no se cobran.
        $consulta = Cuota::query()->with('prestamo.empleado')->orderBy('id_prestamo')->orderBy('numero_cuota')
            ->whereHas('prestamo', fn ($q) => $q->enCobro());

        if ($bloquear) {
            $consulta->lockForUpdate();
        }

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
            ->filter(fn (Cuota $cuota) => $this->cuotaEnQuincena($cuota, $periodo, $regla))
            ->values();
    }

    /**
     * Si el vencimiento de la cuota cae en la quincena según la regla. Requiere
     * la relación prestamo cargada.
     *
     * @param  array{anio: int, numero: int, inicio: CarbonImmutable, corte: CarbonImmutable}  $periodo
     */
    private function cuotaEnQuincena(Cuota $cuota, array $periodo, ReglaVencimiento $regla): bool
    {
        $vencimiento = $cuota->fecha_vencimiento->toDateString();

        if ($regla === ReglaVencimiento::Calendario) {
            return $vencimiento >= $periodo['inicio']->toDateString() && $vencimiento <= $periodo['corte']->toDateString();
        }

        $autorizacion = CarbonImmutable::parse($cuota->prestamo->fecha_autorizacion->toDateString());

        return $this->cicloDe($autorizacion, CarbonImmutable::parse($vencimiento)) === $this->cicloDe($autorizacion, $periodo['corte']);
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

    /**
     * @param  array{anio: int, numero: int, inicio: CarbonImmutable, corte: CarbonImmutable}  $periodo
     * @param  array<int, float>  $vistas
     * @param  array<int, string>  $exclusiones
     */
    private function confirmarEnTransaccion(array $periodo, ReglaVencimiento $regla, Usuario $actor, array $vistas, array $exclusiones): ProcesoQuincena
    {
        $proceso = $this->abrirProceso($periodo, $actor);

        $resultado = $this->calcular($periodo, $regla, ModoMarcado::Confirmar, false, true);

        $diferencias = $this->diferencias($vistas, $resultado['cuotas']);

        if ($diferencias !== []) {
            throw new QuincenaCambioException($diferencias);
        }

        if ($resultado['cuotas'] === []) {
            throw new RuntimeException('La quincena no tiene cuotas para confirmar.');
        }

        $fechaPago = $this->fechaDePago($periodo);
        $pagadas = [];
        $excluidas = [];

        foreach ($resultado['cuotas'] as $fila) {
            if (array_key_exists($fila['id_cuota'], $exclusiones)) {
                $excluidas[] = $fila + ['motivo' => $exclusiones[$fila['id_cuota']]];

                continue;
            }

            $cuota = Cuota::with('prestamo')->findOrFail($fila['id_cuota']);
            $pago = $this->pagoService->registrarPagoCuota($cuota->prestamo, $cuota, $fechaPago, $actor, OrigenPago::Automatico, $proceso->id_proceso);

            $pagadas[] = $fila + ['id_pago' => $pago->id_pago];
        }

        $total = round((float) array_sum(array_column($pagadas, 'valor_total')), 2);

        $resumen = array_merge($resultado, [
            'simulacion' => false,
            'cuotas' => $pagadas,
            'total' => $total,
            'prestamos_incluidos' => array_values(array_unique(array_column($pagadas, 'id_prestamo'))),
            'excluidas_manuales' => $excluidas,
            'pagos_creados' => count($pagadas),
            'fecha_pago' => $fechaPago,
            'confirmado_por' => ['cedula' => $actor->cedula, 'nombre' => $actor->nombre_completo],
        ]);
        unset($resumen['proceso']);

        $proceso->update(['finalizado_en' => now(), 'resumen' => $resumen]);

        $this->auditoriaService->registrar(
            $actor,
            'Confirmación de quincena',
            "Quincena {$periodo['numero']} de {$periodo['anio']}: ".count($pagadas).' cuotas pagadas por '.number_format($total, 2, ',', '.')
                .'; excluidas: '.count($excluidas)
                .($excluidas === [] ? '' : ' ('.implode('; ', array_map(fn (array $fila) => "préstamo #{$fila['id_prestamo']} cuota {$fila['numero_cuota']}: {$fila['motivo']}", $excluidas)).')'),
        );

        return $proceso->refresh();
    }

    /**
     * Inserta la fila del proceso ya como Completado. Si quedó una fila
     * «Pendiente de confirmación» de la versión anterior del proceso, la toma
     * (con una actualización condicionada, sin bloqueos de hueco); si otra
     * confirmación llega primero, la restricción única o la actualización
     * condicionada lo detectan.
     *
     * @param  array{anio: int, numero: int, inicio: CarbonImmutable, corte: CarbonImmutable}  $periodo
     */
    private function abrirProceso(array $periodo, Usuario $actor): ProcesoQuincena
    {
        $atributos = [
            'estado' => EstadoProcesoQuincena::Completado,
            'modo_marcado' => ModoMarcado::Confirmar,
            'cedula_usu' => $actor->cedula,
            'iniciado_en' => now(),
            'finalizado_en' => now(),
        ];

        $heredado = ProcesoQuincena::query()
            ->where('anio', $periodo['anio'])
            ->where('numero_quincena', $periodo['numero'])
            ->where('tipo', TipoProcesoQuincena::Real)
            ->where('estado', EstadoProcesoQuincena::PendienteConfirmacion)
            ->first();

        if ($heredado === null) {
            return ProcesoQuincena::create($atributos + [
                'anio' => $periodo['anio'],
                'numero_quincena' => $periodo['numero'],
                'tipo' => TipoProcesoQuincena::Real,
            ]);
        }

        $tomadas = ProcesoQuincena::query()
            ->where('id_proceso', $heredado->id_proceso)
            ->where('estado', EstadoProcesoQuincena::PendienteConfirmacion)
            ->update(array_map(fn ($valor) => $valor instanceof BackedEnum ? $valor->value : $valor, $atributos));

        if ($tomadas === 0) {
            throw new QuincenaYaConfirmadaException($heredado->refresh()->load('usuario'));
        }

        return $heredado->refresh();
    }

    /**
     * Compara lo que la persona vio con lo que hay ahora.
     *
     * @param  array<int, float>  $vistas
     * @param  list<array<string, mixed>>  $filasVivas
     * @return list<string>
     */
    private function diferencias(array $vistas, array $filasVivas): array
    {
        $vivas = collect($filasVivas)->keyBy('id_cuota');
        $diferencias = [];

        foreach ($vistas as $idCuota => $valor) {
            if (! $vivas->has($idCuota)) {
                $diferencias[] = $this->motivoDeSalida($idCuota);

                continue;
            }

            $actual = round((float) $vivas[$idCuota]['valor_total'], 2);

            if (abs($actual - $valor) > 0.004) {
                $diferencias[] = "El valor de la cuota {$vivas[$idCuota]['numero_cuota']} del préstamo #{$vivas[$idCuota]['id_prestamo']} cambió de ".number_format($valor, 2, ',', '.').' a '.number_format($actual, 2, ',', '.').'.';
            }
        }

        foreach ($vivas as $idCuota => $fila) {
            if (! array_key_exists($idCuota, $vistas)) {
                $diferencias[] = "Apareció la cuota {$fila['numero_cuota']} del préstamo #{$fila['id_prestamo']} ({$fila['nombre_empleado']}), que no estaba en la vista previa.";
            }
        }

        return $diferencias;
    }

    private function motivoDeSalida(int $idCuota): string
    {
        $cuota = Cuota::with('prestamo')->find($idCuota);

        if ($cuota === null) {
            return 'Una cuota de la vista previa ya no existe: su préstamo se reliquidó y las cuotas pendientes se regeneraron.';
        }

        $descripcion = "La cuota {$cuota->numero_cuota} del préstamo #{$cuota->id_prestamo}";

        if ($cuota->estado !== EstadoCuota::Pendiente) {
            return "{$descripcion} ya está {$cuota->estado->value}.";
        }

        if (! in_array($cuota->prestamo->estado, self::ESTADOS_ELEGIBLES, true)) {
            return "{$descripcion} ya no entra: el préstamo pasó a estado {$cuota->prestamo->estado->value}.";
        }

        return "{$descripcion} ya no corresponde a esta quincena.";
    }

    /**
     * El corte de la quincena, o hoy si el corte aún no llega.
     *
     * @param  array{anio: int, numero: int, inicio: CarbonImmutable, corte: CarbonImmutable}  $periodo
     */
    private function fechaDePago(array $periodo): string
    {
        return $periodo['corte']->min($this->calendario->hoy())->toDateString();
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
     * Texto del aviso para una cuota excluida del descuento por nómina, con el
     * lugar de pago tomado de la configuración.
     */
    public function avisoCuotaExcluida(): string
    {
        return str_replace('{lugar}', (string) config('quincena.aviso_cuota_excluida_lugar'), (string) config('quincena.aviso_cuota_excluida'));
    }

    /**
     * Cuotas del préstamo que una quincena confirmada excluyó a mano y siguen
     * Pendiente, con el motivo de la exclusión más reciente. Solo informativo.
     *
     * @return array<int, string> id_cuota => motivo
     */
    public function cuotasExcluidasDelPrestamo(Prestamo $prestamo): array
    {
        $pendientes = $prestamo->cuotas
            ->filter(fn (Cuota $cuota) => $cuota->estado === EstadoCuota::Pendiente)
            ->pluck('id_cuota')
            ->all();

        if ($pendientes === []) {
            return [];
        }

        $excluidas = [];

        foreach (ProcesoQuincena::query()->confirmados()->get() as $proceso) {
            foreach ($proceso->resumen['excluidas_manuales'] ?? [] as $fila) {
                if (in_array($fila['id_cuota'], $pendientes, true) && ! isset($excluidas[$fila['id_cuota']])) {
                    $excluidas[$fila['id_cuota']] = (string) $fila['motivo'];
                }
            }
        }

        return $excluidas;
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
            ->whereHas('prestamo', fn ($q) => $q->whereIn('estado', self::ESTADOS_ELEGIBLES)->enCobro());

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
