<?php

namespace App\Http\Controllers;

use App\Models\Auditoria;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditoriaController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Auditoria::class);

        $cedulaUsu = $request->query('cedula_usu');
        $funcionRealizada = $request->query('funcion_realizada');
        $desde = $request->query('desde');
        $hasta = $request->query('hasta');

        $registros = Auditoria::with('usuario')
            ->when($cedulaUsu, fn ($query) => $query->where('cedula_usu', $cedulaUsu))
            ->when($funcionRealizada, fn ($query) => $query->where('funcion_realizada', $funcionRealizada))
            ->when($desde, fn ($query) => $query->whereDate('fecha', '>=', $desde))
            ->when($hasta, fn ($query) => $query->whereDate('fecha', '<=', $hasta))
            ->orderByDesc('fecha')
            ->orderByDesc('hora')
            ->paginate(20)
            ->withQueryString();

        $usuarios = Usuario::orderBy('nombre_completo')->get();
        $funciones = Auditoria::query()->distinct()->orderBy('funcion_realizada')->pluck('funcion_realizada');

        return view('auditoria.index', compact('registros', 'usuarios', 'funciones', 'cedulaUsu', 'funcionRealizada', 'desde', 'hasta'));
    }
}
