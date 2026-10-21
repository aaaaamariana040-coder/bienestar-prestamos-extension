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
        // Un Gate por cada permiso del catálogo (config/permisos.php): las rutas usan can:nombre,
        // las vistas @can('nombre') y todos leen la lista de permisos de la cuenta.
        foreach (array_keys(config('permisos.catalogo')) as $permiso) {
            Gate::define($permiso, fn (Usuario $usuario) => $usuario->tienePermiso($permiso));
        }

        // «log» registra solo un resumen del correo (sin cuerpo ni adjuntos); ver config/mail.php.
        Mail::extend('log-resumen', fn (array $config) => new LogResumenTransport(Log::channel($config['channel'] ?? null)));

        // Aviso derivado de Inicio y del menú: quincenas ya iniciadas, sin proceso confirmado y con cuotas candidatas.
        View::composer(['inicio', 'layouts.partials.menu-lateral'], function (\Illuminate\View\View $vista): void {
            $usuario = auth()->user();
            $pendientes = [];

            if ($usuario?->can('quincenas.confirmar')) {
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
