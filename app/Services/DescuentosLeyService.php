<?php

namespace App\Services;

/**
 * Descuentos de ley sobre el salario y capacidad de endeudamiento estimada,
 * sin base de datos. Los porcentajes salen de config('prestamos.simulador.descuentos_ley')
 * y deben verificarse con la norma vigente. La retención en la fuente no se
 * calcula: llega como dato manual del operador.
 */
class DescuentosLeyService
{
    public function __construct(private readonly EmpleadoService $empleadoService)
    {
        //
    }

    /**
     * Porcentaje de solidaridad pensional para un salario expresado en SMMLV.
     * Cada tramo incluye su límite inferior y excluye el superior.
     */
    public function porcentajeSolidaridad(float $salarioEnSmmlv): float
    {
        foreach (config('prestamos.simulador.descuentos_ley.solidaridad_pensional') as $tramo) {
            if ($salarioEnSmmlv >= $tramo['desde_smmlv'] && ($tramo['hasta_smmlv'] === null || $salarioEnSmmlv < $tramo['hasta_smmlv'])) {
                return (float) $tramo['porcentaje'];
            }
        }

        return 0.0;
    }

    /**
     * Desglose de la base y de la capacidad estimada: 50 % de (salario menos
     * salud, pensión, solidaridad y retención). La base no baja de cero.
     *
     * @return array{
     *     salario: float,
     *     salud: array{porcentaje: float, valor: float},
     *     pension: array{porcentaje: float, valor: float},
     *     solidaridad: array{porcentaje: float, valor: float},
     *     retencion: float,
     *     total_descuentos: float,
     *     base: float,
     *     capacidad: float,
     * }
     */
    public function calcular(float $salario, float $smmlv, float $retencion = 0.0): array
    {
        $porcentajeSalud = (float) config('prestamos.simulador.descuentos_ley.salud');
        $porcentajePension = (float) config('prestamos.simulador.descuentos_ley.pension');
        $porcentajeSolidaridad = $this->porcentajeSolidaridad($salario / $smmlv);

        $salud = round($salario * $porcentajeSalud / 100, 2);
        $pension = round($salario * $porcentajePension / 100, 2);
        $solidaridad = round($salario * $porcentajeSolidaridad / 100, 2);
        $retencion = round(max($retencion, 0.0), 2);

        $totalDescuentos = round($salud + $pension + $solidaridad + $retencion, 2);
        $base = round(max($salario - $totalDescuentos, 0.0), 2);

        return [
            'salario' => $salario,
            'salud' => ['porcentaje' => $porcentajeSalud, 'valor' => $salud],
            'pension' => ['porcentaje' => $porcentajePension, 'valor' => $pension],
            'solidaridad' => ['porcentaje' => $porcentajeSolidaridad, 'valor' => $solidaridad],
            'retencion' => $retencion,
            'total_descuentos' => $totalDescuentos,
            'base' => $base,
            'capacidad' => $this->empleadoService->capacidadEndeudamientoSugerida($base),
        ];
    }
}
