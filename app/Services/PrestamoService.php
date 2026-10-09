<?php

namespace App\Services;

use App\Enums\EstadoCuota;
use App\Enums\EstadoPrestamo;
use App\Models\Prestamo;
use App\Models\SolicitudCredito;
use App\Models\TasaInt;
use App\Models\TipoPrestamo;
use Carbon\Carbon;
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
        if ($tipoPrestamo->nombre === 'Vehículo') {
            $esPrimeraSolicitud = ! Prestamo::where('cedula_empleado', $solicitud->cedula_empleado)
                ->where('id_tipo', $solicitud->id_tipo)
                ->exists();

            $plazoAnios = intdiv($solicitud->plazo_solicitado, self::QUINCENAS_POR_ANIO);
            $tasaEfectiva = $this->tasaVehiculoService->tasaEfectiva($anio, $plazoAnios, $esPrimeraSolicitud);

            return [null, $tasaEfectiva];
        }

        $tasaInt = TasaInt::where('id_tipo', $solicitud->id_tipo)->where('anio', $anio)->first();

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

    private function generarTablaAmortizacion(Prestamo $prestamo, float $tasaPeriodica): void
    {
        $primeraFecha = Carbon::parse($prestamo->fecha_autorizacion)->addDays(15)->toDateString();

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
        $fecha = Carbon::parse($fechaPrimeraCuota);

        foreach ($this->calculoFinanciero->tablaAmortizacion($saldo, $tasaPeriodica, $numCuotas, $cuotaPeriodica) as $i => $fila) {
            if ($i > 0) {
                $fecha = $fecha->copy()->addDays(15);
            }

            $prestamo->cuotas()->create([
                'numero_cuota' => $numeroInicial + $i,
                'fecha_vencimiento' => $fecha->toDateString(),
                'valor_capital' => $fila['capital'],
                'valor_interes' => $fila['interes'],
                'valor_total' => $fila['cuota'],
                'estado' => EstadoCuota::Pendiente,
            ]);
        }
    }
}
