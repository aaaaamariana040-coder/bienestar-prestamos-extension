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
use RuntimeException;

class PrestamoService
{
    public const QUINCENAS_POR_ANIO = 24;

    public function __construct(
        private readonly TasaVehiculoService $tasaVehiculoService,
        private readonly CalculoFinancieroService $calculoFinanciero,
        private readonly CalendarioQuincenalService $calendario,
    ) {
        //
    }

    public function crearDesdeSolicitud(SolicitudCredito $solicitud): Prestamo
    {
        return DB::transaction(function () use ($solicitud) {
            $tipoPrestamo = $solicitud->tipoPrestamo;
            $anio = $solicitud->fecha_solicitud->year;

            if ($tipoPrestamo->esVehiculo() && $solicitud->plazo_solicitado > TasaVehiculoService::PLAZO_MAXIMO_QUINCENAS) {
                throw new RuntimeException($this->tasaVehiculoService->mensajePlazoExcedido());
            }

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
                'pendiente_desembolso' => $this->primeraCuotaDesdeElDesembolso(),
            ]);

            $this->generarTablaAmortizacion($prestamo, $tasaPeriodica);

            return $prestamo;
        });
    }

    /**
     * Resuelve la tasa aplicable a la solicitud: una fila de tasa_int para las
     * modalidades normales, o la tasa efectiva calculada (IPC base + puntos del
     * plazo/tipo de solicitud) para vehículo, que no tiene id_tasa asociado. En
     * vivienda la tasa se limita al tope del Acuerdo 11 de 2023; ver tasaDetallada().
     *
     * @return array{0: ?int, 1: ?float} [id_tasa, tasa_anual_porcentaje]
     */
    public function resolverTasa(SolicitudCredito $solicitud, TipoPrestamo $tipoPrestamo, int $anio): array
    {
        $detalle = $this->resolverTasaDetallada($solicitud, $tipoPrestamo, $anio);

        return [$detalle['id_tasa'], $detalle['tasa']];
    }

    /**
     * Igual que resolverTasa(), con el detalle del tope: la tasa registrada (IPC
     * tal cual está en tasas_int) y si se limitó.
     *
     * @return array{id_tasa: ?int, tasa: ?float, registrada: ?float, limitada: bool, tope: ?float}
     */
    public function resolverTasaDetallada(SolicitudCredito $solicitud, TipoPrestamo $tipoPrestamo, int $anio): array
    {
        $esPrimeraSolicitud = ! Prestamo::where('cedula_empleado', $solicitud->cedula_empleado)
            ->where('id_tipo', $solicitud->id_tipo)
            ->exists();

        return $this->tasaDetallada($tipoPrestamo, $anio, $solicitud->plazo_solicitado, $esPrimeraSolicitud, $solicitud->fecha_solicitud);
    }

    /**
     * Misma resolución de tasa que resolverTasa(), sin necesitar una solicitud
     * guardada: la usa también el simulador. $fechaSolicitud decide si rige el
     * tope de vivienda; sin ella se toma hoy.
     *
     * @return array{0: ?int, 1: ?float} [id_tasa, tasa_anual_porcentaje]
     */
    public function resolverTasaPara(TipoPrestamo $tipoPrestamo, int $anio, int $plazoQuincenas, bool $esPrimeraSolicitud, DateTimeInterface|string|null $fechaSolicitud = null): array
    {
        $detalle = $this->tasaDetallada($tipoPrestamo, $anio, $plazoQuincenas, $esPrimeraSolicitud, $fechaSolicitud);

        return [$detalle['id_tasa'], $detalle['tasa']];
    }

    /**
     * Tasa aplicable con el tope de vivienda. La tasa registrada en tasas_int
     * (el IPC) nunca se modifica: el tope se aplica al leerla, tasa = min(IPC,
     * tope). Vehículo no tiene tope: su tasa es el IPC más los puntos del plazo.
     *
     * @return array{id_tasa: ?int, tasa: ?float, registrada: ?float, limitada: bool, tope: ?float}
     */
    public function tasaDetallada(TipoPrestamo $tipoPrestamo, int $anio, int $plazoQuincenas, bool $esPrimeraSolicitud, DateTimeInterface|string|null $fechaSolicitud = null): array
    {
        if ($tipoPrestamo->esVehiculo()) {
            $tasa = $this->tasaVehiculoService->tasaEfectiva($anio, $plazoQuincenas, $esPrimeraSolicitud);

            return ['id_tasa' => null, 'tasa' => $tasa, 'registrada' => $tasa, 'limitada' => false, 'tope' => null];
        }

        $tasaInt = TasaInt::where('id_tipo', $tipoPrestamo->id_tipo)->where('anio', $anio)->first();

        if ($tasaInt === null) {
            return ['id_tasa' => null, 'tasa' => null, 'registrada' => null, 'limitada' => false, 'tope' => null];
        }

        $registrada = (float) $tasaInt->tasa_anual;
        $tope = $this->topeTasaVivienda($tipoPrestamo, $fechaSolicitud);
        $limitada = $tope !== null && $registrada > $tope;

        return [
            'id_tasa' => $tasaInt->id_tasa,
            'tasa' => $limitada ? $tope : $registrada,
            'registrada' => $registrada,
            'limitada' => $limitada,
            'tope' => $tope,
        ];
    }

    /**
     * Tope de la tasa de vivienda en puntos porcentuales (5,0), o null si no
     * rige: la modalidad no es de vivienda o la solicitud es anterior a la
     * vigencia del Acuerdo Directivo 11 de 2023 (15 de noviembre de 2023). El
     * tope y la fecha salen de config('prestamos.tasa_vivienda').
     */
    public function topeTasaVivienda(TipoPrestamo $tipoPrestamo, DateTimeInterface|string|null $fechaSolicitud = null): ?float
    {
        if (! $tipoPrestamo->esVivienda()) {
            return null;
        }

        $fecha = Carbon::parse($fechaSolicitud ?? now())->startOfDay();
        $vigenteDesde = Carbon::parse((string) config('prestamos.tasa_vivienda.vigente_desde'))->startOfDay();

        if ($fecha->lt($vigenteDesde)) {
            return null;
        }

        return round((float) config('prestamos.tasa_vivienda.tope') * 100, 4);
    }

    /**
     * Nota que acompaña una tasa limitada, para el simulador, el detalle de la
     * solicitud y del préstamo y la hoja impresa.
     */
    public function notaTopeVivienda(float $tope, float $registrada): string
    {
        return 'Tasa limitada al '.$this->porcentajeLegible($tope).' % según el Acuerdo 11 de 2023; IPC registrado: '.$this->porcentajeLegible($registrada).' %.';
    }

    /**
     * La tasa de una solicitud tal como la aplicaría hoy: la de su aprobación si
     * ya es préstamo, o la resuelta con el tope si sigue radicada.
     *
     * @return array{tasa: ?float, registrada: ?float, limitada: bool, nota: ?string}
     */
    public function tasaDeSolicitud(SolicitudCredito $solicitud): array
    {
        if ($solicitud->prestamo !== null) {
            return $this->tasaDePrestamo($solicitud->prestamo);
        }

        $detalle = $this->resolverTasaDetallada($solicitud, $solicitud->tipoPrestamo, $solicitud->fecha_solicitud->year);

        return $this->conNota($detalle['tasa'], $detalle['registrada'], $detalle['limitada'], $detalle['tope']);
    }

    /**
     * La tasa guardada en el préstamo y, si en su aprobación se limitó, el IPC
     * que estaba registrado.
     *
     * @return array{tasa: ?float, registrada: ?float, limitada: bool, nota: ?string}
     */
    public function tasaDePrestamo(Prestamo $prestamo): array
    {
        if ($prestamo->tasa_efectiva_anual === null) {
            return ['tasa' => null, 'registrada' => null, 'limitada' => false, 'nota' => null];
        }

        $tasa = (float) $prestamo->tasa_efectiva_anual;
        $registrada = $prestamo->tasaInt !== null ? (float) $prestamo->tasaInt->tasa_anual : null;
        $limitada = $registrada !== null && $registrada > $tasa + 1e-9 && $prestamo->tipoPrestamo->esVivienda();

        return $this->conNota($tasa, $registrada, $limitada, $tasa);
    }

    /**
     * @return array{tasa: ?float, registrada: ?float, limitada: bool, nota: ?string}
     */
    private function conNota(?float $tasa, ?float $registrada, bool $limitada, ?float $tope): array
    {
        return [
            'tasa' => $tasa,
            'registrada' => $registrada,
            'limitada' => $limitada,
            'nota' => $limitada && $tope !== null && $registrada !== null ? $this->notaTopeVivienda($tope, $registrada) : null,
        ];
    }

    /**
     * 5 → «5»; 5,2 → «5,20»; 4,85 → «4,85»; 4,8512 → «4,8512». Siempre al menos
     * dos decimales cuando los hay.
     */
    private function porcentajeLegible(float $valor): string
    {
        $texto = rtrim(number_format($valor, 4, ',', '.'), '0');
        $texto = rtrim($texto, ',');

        if (str_contains($texto, ',') && strlen(explode(',', $texto)[1]) < 2) {
            $texto .= '0';
        }

        return $texto;
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
     * ¿La primera cuota corre desde el desembolso? Es la decisión de Bienestar y el
     * valor por defecto de config('quincena.inicio_primera_cuota'); con «desde_aprobacion»
     * se conserva la regla anterior (cuotas cobrables desde la aprobación).
     */
    public function primeraCuotaDesdeElDesembolso(): bool
    {
        return config('quincena.inicio_primera_cuota') !== 'desde_aprobacion';
    }

    /**
     * Fecha base PROVISIONAL de la primera cuota: 15 días después de la
     * autorización. Es la estimación con la que se arma la tabla al aprobar; la
     * fecha real se fija al desembolsar (DesembolsoService). Con «desde_aprobacion»
     * es la fecha definitiva. Ver fechasDeVencimiento().
     */
    public function fechaPrimeraCuota(DateTimeInterface|string $fechaAutorizacion): string
    {
        return Carbon::parse($fechaAutorizacion)->addDays(15)->toDateString();
    }

    /**
     * Fechas de vencimiento de las cuotas, por calendario: el día 15 y el último
     * día real de cada mes. La primera es el primer vencimiento igual o posterior
     * a la fecha base dada y las demás siguen la secuencia sin saltos. Es el
     * generador de fechas de las cuotas reales, y el simulador lo usa para
     * rotular sus fechas estimadas.
     *
     * @return list<string>
     */
    public function fechasDeVencimiento(string $fechaBase, int $numCuotas): array
    {
        return $this->calendario->vencimientosDesde($fechaBase, $numCuotas);
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
