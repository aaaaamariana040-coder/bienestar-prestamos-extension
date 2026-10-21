<?php

namespace App\Providers;

use App\Mail\Transport\LogResumenTransport;
use App\Models\Usuario;
use App\Services\QuincenaService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('reportes.ver', fn (Usuario $usuario) => $usuario->puedeOperar());
        Gate::define('simulador.usar', fn (Usuario $usuario) => $usuario->puedeOperar());

        // Un Gate por cada permiso de config/permisos.php: las vistas usan @can('nombre.del-permiso').
        foreach (array_unique(array_merge(...array_values(config('permisos.por_rol')))) as $permiso) {
            Gate::define($permiso, fn (Usuario $usuario) => $usuario->tienePermiso($permiso));
        }

        // «log» registra solo un resumen del correo (sin cuerpo ni adjuntos); ver config/mail.php.
        Mail::extend('log-resumen', fn (array $config) => new LogResumenTransport(Log::channel($config['channel'] ?? null)));

        // Aviso derivado de Inicio y del menú: quincenas ya iniciadas, sin proceso confirmado y con cuotas candidatas.
        View::composer(['inicio', 'layouts.partials.menu-lateral'], function (\Illuminate\View\View $vista): void {
            $usuario = auth()->user();
            $pendientes = [];

            if ($usuario?->puedeOperar()) {
                try {
                    $pendientes = app(QuincenaService::class)->quincenasPendientes();
                } catch (InvalidArgumentException) {
                    // Configuración de quincena inválida: sin aviso en vez de romper todas las pantallas.
                }
            }

            $vista->with('quincenasPendientes', $pendientes);
        });
    }
}
