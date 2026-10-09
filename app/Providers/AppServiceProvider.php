<?php

namespace App\Providers;

use App\Models\Usuario;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

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
    }
}
