<?php

namespace App\Services;

use App\Models\Empleado;
use App\Models\SmmlvAnual;

/**
 * Única fuente de verdad de la capacidad de endeudamiento: la usan el simulador
 * y la aprobación de solicitudes.
 *
 * Con la fuente «calculada» (por defecto) la capacidad es el 50 % de (salario
 * devengado − salud, pensión, fondo de solidaridad y retención manual opcional).
 * Con «guardada» es empleados.capacidad_endeudamiento, como era antes. En ambos
 * casos se restan las cuotas de los préstamos vigentes.
 */
class CapacidadEndeudamientoService
{
    public const FUENTE_CALCULADA = 'calculada';

    public const FUENTE_GUARDADA = 'guardada';

    public const MENSAJE_NO_CALCULABLE = 'No se puede calcular la capacidad de endeudamiento: el empleado no tiene salario registrado.';

    public function __construct(
        private readonly EmpleadoService $empleadoService,
        private readonly DescuentosLeyService $descuentosLey,
        private readonly PrimaSeguroService $primaSeguroService,
    ) {
        //
    }

    /**
     * Fuente que decide: cualquier valor distinto de «guardada» cuenta como «calculada».
     */
    public function fuente(): string
    {
        return config('prestamos.capacidad.fuente') === self::FUENTE_GUARDADA
            ? self::FUENTE_GUARDADA
            : self::FUENTE_CALCULADA;
    }

    /**
     * Las dos capacidades del empleado y la que decide según la fuente.
     * `calculable` es false cuando la fuente es «calculada» y no hay salario (o
     * no hay SMMLV del año para ubicar el tramo del fondo de solidaridad); en ese
     * caso total y disponible quedan en cero y la aprobación no debe dejar pasar.
     *
     * @return array{
     *     fuente: string,
     *     calculable: bool,
     *     motivo: ?string,
     *     total: float,
     *     comprometida: float,
     *     disponible: float,
     *     guardada: array{capacidad: float, disponible: float},
     *     calculada: ?array<string, mixed>,
     * }
     */
    public function paraEmpleado(Empleado $empleado, ?int $anio = null, float $retencion = 0.0): array
    {
        $anio ??= now()->year;
        $comprometida = $this->empleadoService->capacidadEndeudamiento($empleado)['comprometida'];
        $guardada = round((float) $empleado->capacidad_endeudamiento, 2);
        $salario = (float) $empleado->salario;
        $smmlv = SmmlvAnual::valorParaAnio($anio);

        $calculada = null;

        if ($salario > 0 && $smmlv !== null && $smmlv > 0) {
            $calculada = $this->descuentosLey->calcular($salario, $smmlv, $retencion);
            $calculada['disponible'] = round($calculada['capacidad'] - $comprometida, 2);
        }

        $fuente = $this->fuente();
        $calculable = $fuente === self::FUENTE_GUARDADA || $calculada !== null;
        $total = $fuente === self::FUENTE_GUARDADA ? $guardada : (float) ($calculada['capacidad'] ?? 0.0);

        return [
            'fuente' => $fuente,
            'calculable' => $calculable,
            'motivo' => $calculable ? null : self::MENSAJE_NO_CALCULABLE,
            'total' => $total,
            'comprometida' => $comprometida,
            'disponible' => $calculable ? round($total - $comprometida, 2) : 0.0,
            'guardada' => ['capacidad' => $guardada, 'disponible' => round($guardada - $comprometida, 2)],
            'calculada' => $calculada,
        ];
    }

    /**
     * Cuota mensual que se compara con la capacidad: dos cuotas quincenales y,
     * solo en vivienda, los seguros estimados (vida e incendio y terremoto si es
     * mayor que 0). En las demás modalidades es solo la cuota.
     *
     * @return array{cuota_mensual: float, cuota_sin_seguro_mensual: float, seguro_mensual: float, seguros: array<string, mixed>}
     */
    public function cuotaAComparar(float $monto, float $cuotaQuincenal, int $anio, bool $esVivienda): array
    {
        $seguros = $this->primaSeguroService->estimar($monto, $anio, $esVivienda);
        $seguroMensual = $esVivienda ? round($seguros['total'] * 2, 2) : 0.0;
        $sinSeguro = round($cuotaQuincenal * 2, 2);

        return [
            'cuota_mensual' => round($sinSeguro + $seguroMensual, 2),
            'cuota_sin_seguro_mensual' => $sinSeguro,
            'seguro_mensual' => $seguroMensual,
            'seguros' => $seguros,
        ];
    }

    /**
     * ¿Cabe la cuota en la capacidad disponible?
     *
     * @param  array{disponible: float, calculable: bool}  $capacidad
     * @param  array{cuota_mensual: float}  $cuota
     */
    public function cabe(array $capacidad, array $cuota): bool
    {
        return $capacidad['calculable'] && $cuota['cuota_mensual'] <= $capacidad['disponible'];
    }
}
