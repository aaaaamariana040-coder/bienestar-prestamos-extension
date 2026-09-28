<?php

namespace App\Providers;

use App\Enums\RolUsuario;
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
        Gate::define('reportes.ver', fn (Usuario $usuario) => $usuario->habilitado
            && in_array($usuario->rol, [RolUsuario::Administrador, RolUsuario::FuncionarioBienestar], true));
    }
}
