<?php

namespace App\Services;

use App\Enums\TipoInforme;
use App\Models\InformeGuardado;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;

class InformeGuardadoService
{
    public function __construct(
        private readonly ReporteService $reporteService,
        private readonly AuditoriaService $auditoriaService,
    ) {
        //
    }

    /**
     * RF-009 / RF-010: guarda una copia inmutable del resultado del reporte,
     * con los parámetros usados y la fecha de corte (hoy), junto con su
     * registro de auditoría en la misma transacción.
     *
     * @param  array<string, mixed>  $parametros
     */
    public function generar(TipoInforme $tipo, array $parametros, Usuario $actor): InformeGuardado
    {
        $parametros = $this->normalizarParametros($tipo, $parametros);

        return DB::transaction(function () use ($tipo, $parametros, $actor) {
            $informe = InformeGuardado::create([
                'tipo' => $tipo,
                'parametros' => $parametros,
                'datos' => $this->construirDatos($tipo, $parametros),
                'fecha_corte' => now()->toDateString(),
                'generado_por' => $actor->cedula,
            ]);

            $this->auditoriaService->registrar(
                $actor,
                'Generación de informe guardado',
                "Informe #{$informe->id_informe} — {$tipo->etiqueta()} — corte {$informe->fecha_corte->toDateString()}",
            );

            return $informe;
        });
    }

    /**
     * Deja solo los parámetros que usa cada reporte, con la misma forma que
     * reciben los métodos de ReporteService (vacío se guarda como null).
     *
     * @param  array<string, mixed>  $parametros
     * @return array<string, int|string|null>
     */
    private function normalizarParametros(TipoInforme $tipo, array $parametros): array
    {
        $texto = fn (string $clave): ?string => filled($parametros[$clave] ?? null) ? (string) $parametros[$clave] : null;

        return match ($tipo) {
            TipoInforme::Prestamos => [
                'id_tipo' => filled($parametros['id_tipo'] ?? null) ? (int) $parametros['id_tipo'] : null,
                'estado' => $texto('estado'),
                'desde' => $texto('desde'),
                'hasta' => $texto('hasta'),
            ],
            TipoInforme::Pagos => [
                'desde' => $texto('desde'),
                'hasta' => $texto('hasta'),
            ],
            TipoInforme::Cartera => [],
            TipoInforme::Presupuesto => [
                'anio' => filled($parametros['anio'] ?? null) ? (int) $parametros['anio'] : now()->year,
            ],
        };
    }

    /**
     * Reutiliza ReporteService y convierte su resultado a arrays planos con
     * montos numéricos redondeados a 2 decimales. Los totales se calculan
     * sumando las filas ya redondeadas, para que siempre coincidan con sus partes.
     *
     * @param  array<string, int|string|null>  $parametros
     * @return array{filas: array<int, array<string, mixed>>, totales: array<string, int|float>}
     */
    private function construirDatos(TipoInforme $tipo, array $parametros): array
    {
        return match ($tipo) {
            TipoInforme::Prestamos => $this->datosPrestamos($parametros),
            TipoInforme::Pagos => $this->datosPagos($parametros),
            TipoInforme::Cartera => $this->datosCartera(),
            TipoInforme::Presupuesto => $this->datosPresupuesto($parametros),
        };
    }

    /**
     * @param  array<string, int|string|null>  $parametros
     * @return array{filas: array<int, array<string, mixed>>, totales: array<string, int|float>}
     */
    private function datosPrestamos(array $parametros): array
    {
        $filas = $this->reporteService
            ->prestamosPorModalidad($parametros['id_tipo'], $parametros['estado'], $parametros['desde'], $parametros['hasta'])
            ->map(fn ($fila) => [
                'modalidad' => $fila->modalidad,
                'estado' => $fila->estado,
                'total_prestamos' => (int) $fila->total_prestamos,
                'monto_total' => $this->monto($fila->monto_total),
            ])
            ->all();

        return [
            'filas' => $filas,
            'totales' => [
                'total_prestamos' => (int) array_sum(array_column($filas, 'total_prestamos')),
                'monto_total' => $this->sumar($filas, 'monto_total'),
            ],
        ];
    }

    /**
     * @param  array<string, int|string|null>  $parametros
     * @return array{filas: array<int, array<string, mixed>>, totales: array<string, int|float>}
     */
    private function datosPagos(array $parametros): array
    {
        $filas = $this->reporteService
            ->pagosPorPeriodo($parametros['desde'], $parametros['hasta'])
            ->map(fn ($fila) => [
                'modalidad' => $fila->modalidad,
                'total_pagos' => (int) $fila->total_pagos,
                'monto_total' => $this->monto($fila->monto_total),
                'monto_interes' => $this->monto($fila->monto_interes),
                'monto_capital' => $this->monto($fila->monto_capital),
            ])
            ->all();

        return [
            'filas' => $filas,
            'totales' => [
                'total_pagos' => (int) array_sum(array_column($filas, 'total_pagos')),
                'monto_total' => $this->sumar($filas, 'monto_total'),
                'monto_interes' => $this->sumar($filas, 'monto_interes'),
                'monto_capital' => $this->sumar($filas, 'monto_capital'),
            ],
        ];
    }

    /**
     * @return array{filas: array<int, array<string, mixed>>, totales: array<string, int|float>}
     */
    private function datosCartera(): array
    {
        $filas = $this->reporteService
            ->carteraVencida()
            ->map(fn ($fila) => [
                'id_prestamo' => $fila->prestamo->id_prestamo,
                'cedula_empleado' => $fila->prestamo->cedula_empleado,
                'empleado' => $fila->prestamo->empleado->nombre_completo,
                'modalidad' => $fila->prestamo->tipoPrestamo->nombre,
                'numero_cuota' => (int) $fila->numero_cuota,
                'fecha_vencimiento' => $fila->fecha_vencimiento->toDateString(),
                'valor_total' => $this->monto($fila->valor_total),
                'dias_mora' => $fila->dias_mora,
            ])
            ->all();

        return [
            'filas' => $filas,
            'totales' => [
                'total_cuotas' => count($filas),
                'valor_total' => $this->sumar($filas, 'valor_total'),
            ],
        ];
    }

    /**
     * El porcentaje ejecutado usa la misma fórmula que la vista del reporte
     * (comprometido / asignado × 100, con 1 decimal); un test verifica que no diverjan.
     *
     * @param  array<string, int|string|null>  $parametros
     * @return array{filas: array<int, array<string, mixed>>, totales: array<string, int|float>}
     */
    private function datosPresupuesto(array $parametros): array
    {
        $filas = $this->reporteService
            ->ejecucionPresupuesto((int) $parametros['anio'])
            ->map(fn ($presupuesto) => [
                'modalidad' => $presupuesto->tipoPrestamo->nombre,
                'monto_asignado' => $this->monto($presupuesto->monto_asignado),
                'monto_comprometido' => $this->monto($presupuesto->monto_comprometido),
                'monto_disponible' => $this->monto($presupuesto->monto_disponible),
                'porcentaje_ejecutado' => $presupuesto->monto_asignado > 0
                    ? round((float) $presupuesto->monto_comprometido / (float) $presupuesto->monto_asignado * 100, 1)
                    : 0.0,
            ])
            ->all();

        return [
            'filas' => $filas,
            'totales' => [
                'monto_asignado' => $this->sumar($filas, 'monto_asignado'),
                'monto_comprometido' => $this->sumar($filas, 'monto_comprometido'),
                'monto_disponible' => $this->sumar($filas, 'monto_disponible'),
            ],
        ];
    }

    private function monto(mixed $valor): float
    {
        return round((float) $valor, 2);
    }

    /**
     * @param  array<int, array<string, mixed>>  $filas
     */
    private function sumar(array $filas, string $columna): float
    {
        return round((float) array_sum(array_column($filas, $columna)), 2);
    }
}
