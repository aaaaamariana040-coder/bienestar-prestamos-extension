<?php

namespace App\Services;

use App\Enums\EstadoCuota;
use App\Enums\MotivoReliquidacion;
use App\Models\Cuota;
use App\Models\Prestamo;
use App\Models\Reliquidacion;
use App\Models\Usuario;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ReliquidacionService
{
    public function __construct(
        private readonly CalculoFinancieroService $calculoFinanciero,
        private readonly AuditoriaService $auditoriaService,
    ) {
        //
    }

    /**
     * Umbral del Art. 12 parágrafos 4 y 5: un abono extraordinario MAYOR a N
     * cuotas vigentes (N = config prestamos.abono_extraordinario) permite elegir
     * entre bajar la cuota o bajar el plazo. Uno menor o igual no pide elección:
     * se reliquida siempre bajando el plazo y dejando la cuota igual.
     *
     * Se compara con la cuota real del préstamo (capital e intereses), sin
     * seguro: el seguro no forma parte de la cuota real.
     */
    public function superaUmbral(Prestamo $prestamo, float $monto): bool
    {
        $cuota = (float) $prestamo->cuota_periodica;

        return $cuota > 0
            && $this->aplicaALaModalidad($prestamo)
            && round($monto, 2) > round($this->umbralCuotas() * $cuota, 2);
    }

    /**
     * Saldo de capital de las cuotas pendientes.
     */
    public function saldoCapitalPendiente(Prestamo $prestamo): float
    {
        return round((float) $prestamo->cuotas()->where('estado', EstadoCuota::Pendiente)->sum('valor_capital'), 2);
    }

    /**
     * Un abono igual o mayor al saldo no es un abono: se paga con la
     * cancelación total.
     */
    public function mensajeAbonoIgualOMayorAlSaldo(float $saldoCapital): string
    {
        return 'El abono debe ser menor que el saldo de capital pendiente ($'.number_format($saldoCapital, 2, ',', '.').'); para pagar el saldo completo use «Cancelación total».';
    }

    public function umbralCuotas(): int
    {
        return (int) config('prestamos.abono_extraordinario.umbral_cuotas');
    }

    public function aplicaALaModalidad(Prestamo $prestamo): bool
    {
        $modalidades = (array) config('prestamos.abono_extraordinario.modalidades');
        $nombre = (string) $prestamo->tipoPrestamo?->nombre;

        foreach ($modalidades as $modalidad) {
            if ($modalidad === '*' || ($nombre !== '' && str_starts_with($nombre, $modalidad))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Calcula, sin guardar nada, cómo quedaría el préstamo tras el abono. Es la
     * fuente única de la vista previa y de reliquidar().
     *
     * Con $automatica (abono bajo el umbral) no hay elección: la cuota queda
     * exactamente igual, el saldo baja y el nuevo número de cuotas sale de la
     * fórmula financiera con esa misma cuota (la última cuota puede quedar
     * menor). $modo y $numCuotasElegido se ignoran.
     *
     * En 'cuota' se mantiene el número de cuotas restantes y se recalcula la
     * cuota con el nuevo saldo. En 'plazo' se sugiere n = ceil(−ln(1 − S·r/C) /
     * ln(1+r)) con C la cuota actual; el operador puede indicar otro n y la
     * cuota se recalcula siempre con el n elegido.
     *
     * @return array{saldo_anterior: float, saldo_nuevo: float, tasa_periodica: float, cuota_actual: float, cuota_nueva: float, cuotas_actuales: int, cuotas_nuevas: int, n_sugerido: ?int, automatica: bool}
     */
    public function calcular(Prestamo $prestamo, float $montoAbono, string $modo, ?int $numCuotasElegido = null, bool $automatica = false): array
    {
        if ($automatica) {
            $modo = 'plazo';
            $numCuotasElegido = null;
        }

        if (! in_array($modo, ['cuota', 'plazo'], true)) {
            throw new RuntimeException('Debe elegir si desea bajar la cuota o bajar el plazo.');
        }

        $cuotasPendientes = $prestamo->cuotas()->where('estado', EstadoCuota::Pendiente)->orderBy('numero_cuota')->get();

        if ($cuotasPendientes->isEmpty()) {
            throw new RuntimeException('Este préstamo no tiene cuotas pendientes que reliquidar.');
        }

        $saldoAnterior = round((float) $cuotasPendientes->sum('valor_capital'), 2);

        if ($montoAbono <= 0 || $montoAbono >= $saldoAnterior) {
            throw new RuntimeException('El monto del abono debe ser mayor que cero y menor que el saldo pendiente; para pagar el saldo completo use la cancelación total.');
        }

        $tasaPeriodica = $this->tasaPeriodicaDelPrestamo($prestamo, $cuotasPendientes->first(), $saldoAnterior);
        $saldoNuevo = round($saldoAnterior - $montoAbono, 2);
        $cuotaActual = (float) $prestamo->cuota_periodica;
        $cuotasActuales = $cuotasPendientes->count();
        $nSugerido = null;

        if ($modo === 'cuota') {
            $cuotasNuevas = $cuotasActuales;
        } else {
            $nSugerido = $this->numCuotasParaCuotaFija($saldoNuevo, $tasaPeriodica, $cuotaActual);
            $cuotasNuevas = $numCuotasElegido ?? min($nSugerido, $cuotasActuales);

            if ($numCuotasElegido !== null && ($cuotasNuevas < 1 || $cuotasNuevas > $cuotasActuales)) {
                throw new RuntimeException("El número de cuotas debe estar entre 1 y {$cuotasActuales} (las cuotas que faltan).");
            }
        }

        return [
            'saldo_anterior' => $saldoAnterior,
            'saldo_nuevo' => $saldoNuevo,
            'tasa_periodica' => $tasaPeriodica,
            'cuota_actual' => $cuotaActual,
            'cuota_nueva' => $automatica ? $cuotaActual : $this->calculoFinanciero->cuotaFija($saldoNuevo, $tasaPeriodica, $cuotasNuevas),
            'cuotas_actuales' => $cuotasActuales,
            'cuotas_nuevas' => $cuotasNuevas,
            'n_sugerido' => $nSugerido,
            'automatica' => $automatica,
        ];
    }

    /**
     * Recalcula las cuotas pendientes tras reducir el saldo en $montoAbono
     * (Art. 12 §3-5). Las cuotas pagadas no se tocan, y las pendientes que se
     * conservan mantienen su fecha de vencimiento: al bajar el plazo se eliminan
     * las últimas de la tabla, no se corren las fechas. La tasa periódica sale
     * de la tasa efectiva guardada en el préstamo (la del año de la solicitud,
     * no la vigente), o de la primera cuota pendiente en los préstamos
     * anteriores a esa columna.
     *
     * Con $automatica (abono bajo el umbral) baja el plazo con la misma cuota y
     * queda marcada como automática en el historial.
     */
    public function reliquidar(
        Prestamo $prestamo,
        float $montoAbono,
        MotivoReliquidacion $motivo,
        string $modo,
        string $fecha,
        Usuario $actor,
        ?int $numCuotasElegido = null,
        bool $automatica = false,
    ): Reliquidacion {
        return DB::transaction(function () use ($prestamo, $montoAbono, $motivo, $modo, $fecha, $actor, $numCuotasElegido, $automatica) {
            $plan = $this->calcular($prestamo, $montoAbono, $modo, $numCuotasElegido, $automatica);
            $pendientes = $prestamo->cuotas()->where('estado', EstadoCuota::Pendiente)->orderBy('numero_cuota')->get();
            $numeroInicial = $pendientes->first()->numero_cuota;

            $this->reescribirCuotasPendientes($pendientes, $plan);

            $prestamo->update([
                'cuota_periodica' => $plan['cuota_nueva'],
                'num_cuotas' => $numeroInicial - 1 + $plan['cuotas_nuevas'],
            ]);

            $reliquidacion = $prestamo->reliquidaciones()->create([
                'motivo' => $motivo,
                'opcion_elegida' => $automatica ? 'plazo' : $modo,
                'automatica' => $automatica,
                'saldo_anterior' => $plan['saldo_anterior'],
                'saldo_nuevo' => $plan['saldo_nuevo'],
                'tasa_aplicada' => round($this->calculoFinanciero->tasaNominalDesdePeriodica($plan['tasa_periodica']), 4),
                'fecha' => $fecha,
                'cedula_usu' => $actor->cedula,
            ]);

            $this->auditoriaService->registrar(
                $actor,
                'Reliquidación de préstamo',
                "Préstamo #{$prestamo->id_prestamo} — saldo {$plan['saldo_anterior']} a {$plan['saldo_nuevo']}, opción: ".($automatica ? 'plazo (automática por abono bajo el umbral)' : $modo).", cuota {$plan['cuota_actual']} a {$plan['cuota_nueva']}, cuotas restantes {$plan['cuotas_actuales']} a {$plan['cuotas_nuevas']}",
            );

            return $reliquidacion;
        });
    }

    /**
     * Pone la nueva tabla sobre las cuotas pendientes: las primeras
     * `cuotas_nuevas` se actualizan en el lugar (conservan número y fecha de
     * vencimiento) y las que sobran, las últimas, se eliminan.
     *
     * @param  Collection<int, Cuota>  $pendientes
     * @param  array{saldo_nuevo: float, tasa_periodica: float, cuota_nueva: float, cuotas_nuevas: int}  $plan
     */
    private function reescribirCuotasPendientes(Collection $pendientes, array $plan): void
    {
        $tabla = $this->calculoFinanciero->tablaAmortizacion($plan['saldo_nuevo'], $plan['tasa_periodica'], $plan['cuotas_nuevas'], $plan['cuota_nueva']);

        foreach ($tabla as $i => $fila) {
            $pendientes[$i]->update([
                'valor_capital' => $fila['capital'],
                'valor_interes' => $fila['interes'],
                'valor_total' => $fila['cuota'],
            ]);
        }

        $pendientes->slice($plan['cuotas_nuevas'])->each->delete();
    }

    /**
     * Usa la tasa efectiva guardada en el préstamo. Los préstamos anteriores a
     * esa columna no la tienen: para ellos se deduce de la primera cuota
     * pendiente (interés ÷ saldo), que es aproximada por el redondeo a centavos.
     */
    private function tasaPeriodicaDelPrestamo(Prestamo $prestamo, Cuota $primeraPendiente, float $saldoAnterior): float
    {
        if ($prestamo->tasa_efectiva_anual !== null) {
            return $this->calculoFinanciero->tasaPeriodicaQuincenal((float) $prestamo->tasa_efectiva_anual);
        }

        return $saldoAnterior > 0 ? (float) $primeraPendiente->valor_interes / $saldoAnterior : 0.0;
    }

    private function numCuotasParaCuotaFija(float $saldo, float $tasaPeriodica, float $cuotaFija): int
    {
        if ($tasaPeriodica > 0 && $cuotaFija <= $saldo * $tasaPeriodica) {
            throw new RuntimeException('La cuota actual no alcanza a cubrir el interés del nuevo saldo; reliquide disminuyendo la cuota en vez del plazo.');
        }

        return $this->calculoFinanciero->numPeriodosParaCuota($saldo, $tasaPeriodica, $cuotaFija);
    }
}
