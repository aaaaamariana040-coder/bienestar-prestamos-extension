<?php

namespace App\Http\Controllers;

use App\Models\Prestamo;
use Illuminate\View\View;

class PrestamoController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', Prestamo::class);

        $prestamos = Prestamo::with(['empleado', 'tipoPrestamo'])
            ->orderByDesc('fecha_autorizacion')
            ->get();

        return view('prestamos.index', compact('prestamos'));
    }

    public function show(Prestamo $prestamo): View
    {
        $this->authorize('view', $prestamo);

        $prestamo->loadMissing(['empleado', 'tipoPrestamo', 'cuotas']);

        return view('prestamos.show', compact('prestamo'));
    }
}
