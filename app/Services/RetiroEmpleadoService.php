<?php

namespace App\Services;

use App\Enums\EstadoCuota;
use App\Enums\EstadoPrestamo;
use App\Enums\MotivoReliquidacion;
use App\Models\Empleado;
use App\Models\Prestamo;
use App\Models\Reliquidacion;
use App\Models\Usuario;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RetiroEmpleadoService
{
    public function __construct(private readonly AuditoriaService $auditoriaService)
    {
        //
    }

    /**
     * Marca al empleado como retirado y deja una reliquidación con motivo
     * Retiro por cada préstamo con saldo pendiente (Art. 40). Solo es el
     * esqueleto: la reliquidación queda con saldo_nuevo nulo (cálculo
     * pendiente) y no se toca la tabla de amortización.
     *
     * @return Collection<int, Reliquidacion>
     */
    public function registrarRetiro(Empleado $empleado, string $fecha, Usuario $actor): Collection
    {
        if ($empleado->estado === 'Retirado') {
            throw new RuntimeException('El empleado ya se encuentra retirado.');
        }

        return DB::transaction(function () use ($empleado, $fecha, $actor) {
            $empleado->update(['estado' => 'Retirado']);

            $prestamos = Prestamo::where('cedula_empleado', $empleado->cedula)
                ->whereIn('estado', [EstadoPrestamo::Vigente, EstadoPrestamo::EnMora])
                ->get();

            $reliquidaciones = $prestamos->map(function (Prestamo $prestamo) use ($fecha, $actor) {
                $saldoPendiente = (float) $prestamo->cuotas()->where('estado', '!=', EstadoCuota::Pagada)->sum('valor_capital');

                // TODO: pendiente de reglas de negocio confirmadas con Bienestar Social
                // Aquí se invocará liquidarSaldoPorRetiro() para llenar saldo_nuevo y
                // tasa_aplicada. No se llama todavía porque lanza excepción y haría
                // revertir el retiro completo.
                return $prestamo->reliquidaciones()->create([
                    'motivo' => MotivoReliquidacion::Retiro,
                    'saldo_anterior' => $saldoPendiente,
                    'saldo_nuevo' => null,
                    'tasa_aplicada' => null,
                    'fecha' => $fecha,
                    'cedula_usu' => $actor->cedula,
                ]);
            });

            $this->auditoriaService->registrar(
                $actor,
                'Retiro de empleado',
                "Empleado {$empleado->cedula} retirado el {$fecha} — {$reliquidaciones->count()} préstamo(s) con reliquidación por retiro pendiente de cálculo",
            );

            return $reliquidaciones;
        });
    }

    /**
     * Imputación del saldo contra la liquidación de prestaciones sociales y
     * reliquidación a tasa DTF en cuotas mensuales (Art. 40).
     */
    public function liquidarSaldoPorRetiro(Prestamo $prestamo): never
    {
        // TODO: pendiente de reglas de negocio confirmadas con Bienestar Social
        throw new RuntimeException('Cálculo de liquidación por retiro aún no implementado: pendiente del orden de prelación y de la fuente de la tasa DTF.');
    }
}
