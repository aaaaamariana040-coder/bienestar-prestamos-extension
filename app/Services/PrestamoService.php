<?php

namespace App\Services;

use App\Enums\EstadoCuota;
use App\Enums\EstadoPrestamo;
use App\Models\Prestamo;
use App\Models\SolicitudCredito;
use App\Models\TasaInt;
use App\Models\TipoPrestamo;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

class PrestamoService
{
    public const QUINCENAS_POR_ANIO = 24;

    public function __construct(
        private readonly TasaVehiculoService $tasaVehiculoService,
        private readonly CalculoFinancieroService $calculoFinanciero,
    ) {
        //
    }

    public function crearDesdeSolicitud(SolicitudCredito $solicitud): Prestamo
    {
        return DB::transaction(function () use ($solicitud) {
            $tipoPrestamo = $solicitud->tipoPrestamo;
            $anio = $solicitud->fecha_solicitud->year;

            [$idTasa, $tasaAnual] = $this->resolverTasa($solicitud, $tipoPrestamo, $anio);

            $tasaPeriodica = $this->tasaPeriodica((float) $tasaAnual);
            $numCuotas = $solicitud->plazo_solicitado;
            $cuotaPeriodica = $this->calcularCuotaFrancesa((float) $solicitud->monto_solicitado, $tasaPeriodica, $numCuotas);

            $prestamo = Prestamo::create([
                'id_solicitud' => $solicitud->id_solicitud,
                'cedula_empleado' => $solicitud->cedula_empleado,
                'id_tipo' => $solicitud->id_tipo,
                'id_tasa' => $idTasa,
                'tasa_efectiva_anual' => $tasaAnual,
                'monto_aprobado' => $solicitud->monto_solicitado,
                'num_cuotas' => $numCuotas,
                'cuota_periodica' => $cuotaPeriodica,
                'fecha_autorizacion' => now()->toDateString(),
                'estado' => EstadoPrestamo::Vigente,
            ]);

            $this->generarTablaAmortizacion($prestamo, $tasaPeriodica);

            return $prestamo;
        });
    }

    /**
     * Resuelve la tasa aplicable a la solicitud: una fila de tasa_int para las
     * modalidades normales, o la tasa efectiva calculada (IPC base + puntos del
     * plazo/tipo de solicitud) para vehículo, que no tiene id_tasa asociado.
     *
     * @return array{0: ?int, 1: ?float} [id_tasa, tasa_anual_porcentaje]
     */
    public function resolverTasa(SolicitudCredito $solicitud, TipoPrestamo $tipoPrestamo, int $anio): array
    {
        $esPrimeraSolicitud = ! Prestamo::where('cedula_empleado', $solicitud->cedula_empleado)
            ->where('id_tipo', $solicitud->id_tipo)
            ->exists();

        return $this->resolverTasaPara($tipoPrestamo, $anio, $solicitud->plazo_solicitado, $esPrimeraSolicitud);
    }

    /**
     * Misma resolución de tasa que resolverTasa(), sin necesitar una solicitud
     * guardada: la usa también el simulador.
     *
     * @return array{0: ?int, 1: ?float} [id_tasa, tasa_anual_porcentaje]
     */
    public function resolverTasaPara(TipoPrestamo $tipoPrestamo, int $anio, int $plazoQuincenas, bool $esPrimeraSolicitud): array
    {
        if ($tipoPrestamo->esVehiculo()) {
            $plazoAnios = intdiv($plazoQuincenas, self::QUINCENAS_POR_ANIO);

            return [null, $this->tasaVehiculoService->tasaEfectiva($anio, $plazoAnios, $esPrimeraSolicitud)];
        }

        $tasaInt = TasaInt::where('id_tipo', $tipoPrestamo->id_tipo)->where('anio', $anio)->first();

        return [$tasaInt?->id_tasa, $tasaInt !== null ? (float) $tasaInt->tasa_anual : null];
    }

    /**
     * Tasa periódica quincenal a partir de la tasa efectiva anual (porcentaje).
     */
    public function tasaPeriodica(float $tasaAnualPorcentaje): float
    {
        return $this->calculoFinanciero->tasaPeriodicaQuincenal($tasaAnualPorcentaje);
    }

    public function calcularCuotaFrancesa(float $monto, float $tasaPeriodica, int $numCuotas): float
    {
        return $this->calculoFinanciero->cuotaFija($monto, $tasaPeriodica, $numCuotas);
    }

    /**
     * La primera cuota vence 15 días después de la autorización.
     */
    public function fechaPrimeraCuota(DateTimeInterface|string $fechaAutorizacion): string
    {
        return Carbon::parse($fechaAutorizacion)->addDays(15)->toDateString();
    }

    /**
     * Fechas de vencimiento de las cuotas: la primera dada y cada una de las
     * siguientes 15 días después. Es el generador de fechas de las cuotas
     * reales, y el simulador lo usa para rotular sus fechas estimadas.
     *
     * @return list<string>
     */
    public function fechasDeVencimiento(string $fechaPrimeraCuota, int $numCuotas): array
    {
        $primera = Carbon::parse($fechaPrimeraCuota);
        $fechas = [];

        for ($i = 0; $i < $numCuotas; $i++) {
            $fechas[] = $primera->copy()->addDays(15 * $i)->toDateString();
        }

        return $fechas;
    }

    private function generarTablaAmortizacion(Prestamo $prestamo, float $tasaPeriodica): void
    {
        $primeraFecha = $this->fechaPrimeraCuota($prestamo->fecha_autorizacion);

        $this->generarCuotasDesde(
            $prestamo,
            (float) $prestamo->monto_aprobado,
            $tasaPeriodica,
            (float) $prestamo->cuota_periodica,
            $prestamo->num_cuotas,
            1,
            $primeraFecha,
        );
    }

    /**
     * Genera cuotas de amortización quincenal a partir de un saldo, tasa y
     * cuota dados. Lo usa tanto la generación inicial del préstamo como
     * ReliquidacionService al recalcular las cuotas pendientes.
     */
    public function generarCuotasDesde(
        Prestamo $prestamo,
        float $saldo,
        float $tasaPeriodica,
        float $cuotaPeriodica,
        int $numCuotas,
        int $numeroInicial,
        string $fechaPrimeraCuota,
    ): void {
        $fechas = $this->fechasDeVencimiento($fechaPrimeraCuota, $numCuotas);

        foreach ($this->calculoFinanciero->tablaAmortizacion($saldo, $tasaPeriodica, $numCuotas, $cuotaPeriodica) as $i => $fila) {
            $prestamo->cuotas()->create([
                'numero_cuota' => $numeroInicial + $i,
                'fecha_vencimiento' => $fechas[$i],
                'valor_capital' => $fila['capital'],
                'valor_interes' => $fila['interes'],
                'valor_total' => $fila['cuota'],
                'estado' => EstadoCuota::Pendiente,
            ]);
        }
    }
}
