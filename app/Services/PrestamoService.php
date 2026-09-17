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
    private const QUINCENAS_POR_ANIO = 24;

    public function __construct(private readonly TasaVehiculoService $tasaVehiculoService)
    {
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

    public function tasaPeriodica(float $tasaAnualPorcentaje): float
    {
        return $tasaAnualPorcentaje / 100 / self::QUINCENAS_POR_ANIO;
    }

    public function calcularCuotaFrancesa(float $monto, float $tasaPeriodica, int $numCuotas): float
    {
        if ($tasaPeriodica <= 0) {
            return round($monto / $numCuotas, 2);
        }

        $factor = $tasaPeriodica / (1 - (1 + $tasaPeriodica) ** -$numCuotas);

        return round($monto * $factor, 2);
    }

    private function generarTablaAmortizacion(Prestamo $prestamo, float $tasaPeriodica): void
    {
        $saldo = (float) $prestamo->monto_aprobado;
        $fecha = Carbon::parse($prestamo->fecha_autorizacion);
        $cuotaPeriodica = (float) $prestamo->cuota_periodica;

        for ($numero = 1; $numero <= $prestamo->num_cuotas; $numero++) {
            $fecha = $fecha->copy()->addDays(15);
            $interes = round($saldo * $tasaPeriodica, 2);
            $esUltimaCuota = $numero === $prestamo->num_cuotas;
            $capital = $esUltimaCuota ? $saldo : round($cuotaPeriodica - $interes, 2);
            $total = round($capital + $interes, 2);
            $saldo = round($saldo - $capital, 2);

            $prestamo->cuotas()->create([
                'numero_cuota' => $numero,
                'fecha_vencimiento' => $fecha->toDateString(),
                'valor_capital' => $capital,
                'valor_interes' => $interes,
                'valor_total' => $total,
                'estado' => EstadoCuota::Pendiente,
            ]);
        }
    }
}
