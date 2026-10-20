<?php

namespace App\Console\Commands;

use App\Services\CalendarioQuincenalService;
use App\Services\QuincenaService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;

#[Signature('quincena:procesar {--anio= : Año de la quincena} {--quincena= : Número de quincena (1 a 24)} {--ejecutar : Ejecuta el proceso real; sin esta opción solo simula}')]
#[Description('Calcula (simulación) o ejecuta (--ejecutar) el proceso de cuotas de una quincena')]
class ProcesarQuincenaCommand extends Command
{
    public function handle(QuincenaService $servicio, CalendarioQuincenalService $calendario): int
    {
        $real = (bool) $this->option('ejecutar');
        $anio = $this->option('anio');
        $quincena = $this->option('quincena');

        if ($real && ($anio === null || $quincena === null)) {
            $this->error('El proceso real exige indicar --anio y --quincena de forma explícita.');

            return self::FAILURE;
        }

        if (($anio !== null && ! ctype_digit((string) $anio)) || ($quincena !== null && ! ctype_digit((string) $quincena))) {
            $this->error('--anio y --quincena deben ser números enteros.');

            return self::FAILURE;
        }

        if ($anio === null || $quincena === null) {
            $actual = $calendario->quincenaActual();
            $anio ??= $actual['anio'];
            $quincena ??= $actual['numero'];
        }

        try {
            $resultado = $servicio->procesar((int) $anio, (int) $quincena, ! $real);
        } catch (InvalidArgumentException|RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->imprimir($resultado, $real);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $resultado
     */
    private function imprimir(array $resultado, bool $real): void
    {
        $escribe = $real && ! $resultado['simulacion'];
        $titulo = match (true) {
            $escribe => 'PROCESO REAL',
            $real => 'VISTA PREVIA (modo confirmar: no se escribió nada)',
            default => 'SIMULACIÓN (no se escribió nada)',
        };

        $this->info($titulo." — quincena {$resultado['quincena']} de {$resultado['anio']} ({$resultado['inicio']} al {$resultado['corte']})");
        $this->line("Regla de vencimiento: {$resultado['regla_vencimiento']} · Modo de marcado: {$resultado['modo_marcado']}");

        if ($resultado['ya_procesado'] && $escribe) {
            $this->warn('Esta quincena ya tenía un proceso real: no se creó nada nuevo (resumen del proceso existente).');
        } elseif ($resultado['ya_procesado']) {
            $this->warn('Esta quincena ya tiene un proceso real registrado.');
        }

        $this->line('Cuotas: '.count($resultado['cuotas']).' · Total: '.number_format($resultado['total'], 2, ',', '.'));
        $this->line('Préstamos incluidos: '.count($resultado['prestamos_incluidos']).($resultado['prestamos_incluidos'] === [] ? '' : ' ('.implode(', ', array_map(fn ($id) => "#{$id}", $resultado['prestamos_incluidos'])).')'));

        if ($escribe) {
            $this->line("Pagos creados: {$resultado['pagos_creados']}");
        }

        if ($resultado['excluidos'] !== []) {
            $this->line('Excluidos:');
            $this->table(
                ['Préstamo', 'Cuota', 'Motivo'],
                array_map(fn ($fila) => ["#{$fila['id_prestamo']}", $fila['numero_cuota'], $fila['motivo']], $resultado['excluidos']),
            );
        }

        foreach ($resultado['advertencias'] as $advertencia) {
            $this->warn("ADVERTENCIA: {$advertencia}");
        }

        $vencidas = $resultado['vencidas_anteriores'];

        if ($vencidas['cuotas'] > 0) {
            $this->line("Informativo: {$vencidas['cuotas']} cuotas pendientes de {$vencidas['prestamos']} préstamos vencieron antes de esta quincena y no se procesan aquí.");
        }

        if ($real && ! $escribe) {
            $this->line('Modo confirmar: no se creó ningún proceso ni pagos. La quincena se confirma desde la aplicación (menú Pagos › Quincenas).');
        }
    }
}
