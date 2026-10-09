<?php

namespace App\Services;

use App\Models\SmmlvAnual;
use App\Models\TipoPrestamo;

/**
 * Simulador de crédito: calcula sin guardar nada. Reutiliza la resolución de
 * tasa de PrestamoService (incluida la de vehículo) y las fórmulas de
 * CalculoFinancieroService, de modo que la cuota simulada es la misma que
 * tendría el préstamo real.
 */
class SimuladorCreditoService
{
    public function __construct(
        private readonly PrestamoService $prestamoService,
        private readonly CalculoFinancieroService $calculoFinanciero,
        private readonly EmpleadoService $empleadoService,
    ) {
        //
    }

    /**
     * Si hay errores de validación, solo viene `errores` con datos; el resto
     * de las claves quedan en null (o vacías, en la tabla).
     *
     * @return array{
     *     errores: list<string>,
     *     tasa_efectiva_anual: ?float,
     *     tasa_nominal_anual: ?float,
     *     cuota_quincenal: ?float,
     *     total_intereses: ?float,
     *     total_a_pagar: ?float,
     *     tabla_amortizacion: list<array{numero: int, interes: float, capital: float, cuota: float, saldo: float}>,
     *     capacidad_endeudamiento: ?float,
     *     margen_capacidad: ?float,
     * }
     */
    public function simular(
        TipoPrestamo $modalidad,
        float $monto,
        int $plazoQuincenas,
        float $salario,
        int $anioSolicitud,
        bool $esPrimeraSolicitud = true,
    ): array {
        $errores = $this->validarEntradas($modalidad, $monto, $plazoQuincenas, $salario, $anioSolicitud);

        [, $tasaEfectivaAnual] = $this->prestamoService->resolverTasaPara($modalidad, $anioSolicitud, $plazoQuincenas, $esPrimeraSolicitud);

        if ($tasaEfectivaAnual === null) {
            $errores[] = "No hay una tasa de interés registrada para {$modalidad->nombre} en {$anioSolicitud}.";
        }

        if ($errores !== []) {
            return $this->resultadoConErrores($errores);
        }

        $tasaPeriodica = $this->calculoFinanciero->tasaPeriodicaQuincenal($tasaEfectivaAnual);
        $tabla = $this->calculoFinanciero->tablaAmortizacion($monto, $tasaPeriodica, $plazoQuincenas);
        $cuota = $this->calculoFinanciero->cuotaFija($monto, $tasaPeriodica, $plazoQuincenas);
        $capacidad = $this->empleadoService->capacidadEndeudamientoSugerida($salario);

        return [
            'errores' => [],
            'tasa_efectiva_anual' => $tasaEfectivaAnual,
            'tasa_nominal_anual' => $this->calculoFinanciero->tasaNominalDesdePeriodica($tasaPeriodica),
            'cuota_quincenal' => $cuota,
            'total_intereses' => round(array_sum(array_column($tabla, 'interes')), 2),
            'total_a_pagar' => round(array_sum(array_column($tabla, 'cuota')), 2),
            'tabla_amortizacion' => $tabla,
            'capacidad_endeudamiento' => $capacidad,
            'margen_capacidad' => round($capacidad - $cuota * 2, 2),
        ];
    }

    /**
     * @return list<string>
     */
    private function validarEntradas(TipoPrestamo $modalidad, float $monto, int $plazoQuincenas, float $salario, int $anioSolicitud): array
    {
        $errores = [];

        if ($monto <= 0) {
            $errores[] = 'El monto debe ser mayor que cero.';
        }

        if ($salario <= 0) {
            $errores[] = 'El salario debe ser mayor que cero.';
        }

        if ($plazoQuincenas < 1) {
            $errores[] = 'El plazo debe ser de al menos 1 quincena.';
        } elseif ($plazoQuincenas > $modalidad->plazo_maximo) {
            $errores[] = "El plazo ({$plazoQuincenas} quincenas) supera el máximo de la modalidad {$modalidad->nombre}: {$modalidad->plazo_maximo} quincenas.";
        }

        if ($monto > 0 && $salario > 0) {
            $errores = [...$errores, ...$this->validarCuantia($modalidad, $monto, $salario, $anioSolicitud)];
        }

        return $errores;
    }

    /**
     * @return list<string>
     */
    private function validarCuantia(TipoPrestamo $modalidad, float $monto, float $salario, int $anioSolicitud): array
    {
        $smmlv = SmmlvAnual::valorParaAnio($anioSolicitud);

        if ($smmlv === null) {
            return ["No se ha registrado el valor del SMMLV para el año {$anioSolicitud}."];
        }

        $tramo = $modalidad->tramoCuantiaPara($salario / $smmlv);

        if ($tramo === null) {
            return ['No hay una cuantía máxima configurada para el tramo salarial del empleado en esta modalidad.'];
        }

        $cuantiaMaxima = (float) $tramo->cuantia_maxima_smmlv * $smmlv;

        if ($monto > $cuantiaMaxima) {
            return ['El monto solicitado ('.number_format($monto, 0, ',', '.').') supera la cuantía máxima permitida para el salario indicado ('.number_format($cuantiaMaxima, 0, ',', '.').').'];
        }

        return [];
    }

    /**
     * @param  list<string>  $errores
     * @return array{errores: list<string>, tasa_efectiva_anual: null, tasa_nominal_anual: null, cuota_quincenal: null, total_intereses: null, total_a_pagar: null, tabla_amortizacion: array{}, capacidad_endeudamiento: null, margen_capacidad: null}
     */
    private function resultadoConErrores(array $errores): array
    {
        return [
            'errores' => $errores,
            'tasa_efectiva_anual' => null,
            'tasa_nominal_anual' => null,
            'cuota_quincenal' => null,
            'total_intereses' => null,
            'total_a_pagar' => null,
            'tabla_amortizacion' => [],
            'capacidad_endeudamiento' => null,
            'margen_capacidad' => null,
        ];
    }
}
