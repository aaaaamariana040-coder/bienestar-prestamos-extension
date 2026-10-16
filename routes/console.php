<?php

use App\Services\CalendarioQuincenalService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Quincena automática: apagada por defecto (config/quincena.php). Cuando se
 * activa, corre cada día a las 6:00 (hora de Bogotá) y solo actúa los días 15 y
 * último del mes, que son el corte de la quincena. Siempre pasa --anio y
 * --quincena explícitos, calculados con el calendario quincenal.
 */
if (config('quincena.programador_activo')) {
    Schedule::call(function (): void {
        $hoy = app(CalendarioQuincenalService::class)->hoy();

        if ($hoy->day !== 15 && ! $hoy->isLastOfMonth()) {
            return;
        }

        $quincena = app(CalendarioQuincenalService::class)->quincenaDeFecha($hoy);

        $codigo = Artisan::call('quincena:procesar', [
            '--anio' => $quincena['anio'],
            '--quincena' => $quincena['numero'],
            '--ejecutar' => true,
        ]);

        if ($codigo !== 0) {
            throw new RuntimeException('quincena:procesar falló: '.trim(Artisan::output()));
        }
    })->name('quincena:procesar')->dailyAt('06:00')->timezone(CalendarioQuincenalService::ZONA)->withoutOverlapping();
}
