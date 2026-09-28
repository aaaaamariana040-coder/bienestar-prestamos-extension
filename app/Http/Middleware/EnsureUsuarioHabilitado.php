<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUsuarioHabilitado
{
    public const MENSAJE_CUENTA_DESHABILITADA = 'Tu cuenta está deshabilitada. Comunícate con el administrador.';

    /**
     * Cierra la sesión de un usuario autenticado cuya cuenta fue deshabilitada
     * después de iniciar sesión (RNF-001).
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if ($usuario === null || $usuario->habilitado) {
            return $next($request);
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')
            ->withErrors(['cedula' => self::MENSAJE_CUENTA_DESHABILITADA]);
    }
}
