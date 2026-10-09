<?php

namespace Database\Seeders;

use App\Models\Empleado;
use App\Models\Prestamo;
use App\Models\PresupuestoAnual;
use App\Models\SolicitudCredito;
use App\Models\Usuario;
use App\Services\PresupuestoService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Quita los datos de DemoSeeder: solo los empleados con cédula entre
 * 9000000001 y 9000000020 y lo que cuelga de ellos (solicitudes, documentos y
 * sus archivos, préstamos, cuotas, pagos, desembolsos, reliquidaciones y
 * abonos). Devuelve al presupuesto lo que esos préstamos habían comprometido.
 * No toca ningún otro dato; los registros de auditoría se conservan.
 *
 *     php artisan db:seed --class=DemoLimpiezaSeeder
 */
class DemoLimpiezaSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('DemoLimpiezaSeeder no se ejecuta en producción.');

            return;
        }

        $cedulas = Empleado::whereBetween('cedula', [(string) DemoSeeder::CEDULA_PRIMERA, (string) DemoSeeder::CEDULA_ULTIMA])->pluck('cedula');

        if ($cedulas->isEmpty()) {
            $this->command?->info('No hay datos de demostración que quitar.');

            return;
        }

        $actor = Usuario::orderBy('cedula')->firstOrFail();

        $rutas = DB::transaction(function () use ($cedulas, $actor): array {
            $prestamos = Prestamo::whereIn('cedula_empleado', $cedulas)->get();
            $idsPrestamo = $prestamos->pluck('id_prestamo');

            $this->devolverPresupuesto($prestamos, $actor);

            foreach (['cuotas', 'pagos', 'desembolsos', 'reliquidaciones', 'abonos_cesantias'] as $tabla) {
                DB::table($tabla)->whereIn('id_prestamo', $idsPrestamo)->delete();
            }

            Prestamo::whereIn('id_prestamo', $idsPrestamo)->delete();

            $solicitudes = SolicitudCredito::whereIn('cedula_empleado', $cedulas)->get();
            $rutas = $solicitudes->pluck('id_solicitud')->map(fn (int $id): string => "documentos-solicitud/{$id}")->all();

            SolicitudCredito::whereIn('id_solicitud', $solicitudes->pluck('id_solicitud'))->delete();
            Empleado::whereIn('cedula', $cedulas)->delete();

            return $rutas;
        });

        foreach ($rutas as $carpeta) {
            Storage::disk('local')->deleteDirectory($carpeta);
        }

        $this->command?->info("Datos de demostración quitados: {$cedulas->count()} empleado(s).");
    }

    /**
     * @param  Collection<int, Prestamo>  $prestamos
     */
    private function devolverPresupuesto($prestamos, Usuario $actor): void
    {
        $porPresupuesto = $prestamos->groupBy(fn (Prestamo $prestamo): string => $prestamo->id_tipo.'|'.$prestamo->fecha_autorizacion->year);

        foreach ($porPresupuesto as $clave => $grupo) {
            [$idTipo, $anio] = explode('|', $clave);
            $presupuesto = PresupuestoAnual::where('id_tipo', $idTipo)->where('anio', $anio)->first();

            if ($presupuesto === null) {
                continue;
            }

            app(PresupuestoService::class)->actualizar(
                $presupuesto,
                ['monto_comprometido' => max(0, (float) $presupuesto->monto_comprometido - (float) $grupo->sum('monto_aprobado'))],
                'Retiro de los datos de demostración',
                $actor,
            );
        }
    }
}
