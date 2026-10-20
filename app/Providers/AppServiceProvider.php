<?php

namespace App\Providers;

use App\Models\Usuario;
use App\Services\QuincenaService;
use Illuminate\Support\Facades\Gate;
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
