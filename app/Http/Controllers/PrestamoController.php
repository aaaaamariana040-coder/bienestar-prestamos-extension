<?php

namespace App\Http\Controllers;

use App\Models\Prestamo;
use App\Models\Proveedor;
use App\Services\DesembolsoService;
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

    public function show(Prestamo $prestamo, DesembolsoService $desembolsoService): View
    {
        $this->authorize('view', $prestamo);

        $prestamo->loadMissing(['empleado', 'tipoPrestamo', 'cuotas', 'desembolsos.proveedor', 'pagos']);

        $tramosEsperados = $desembolsoService->tramosEsperados($prestamo);
        $proveedores = Proveedor::orderBy('nombre_empresa')->get();

        return view('prestamos.show', compact('prestamo', 'tramosEsperados', 'proveedores'));
    }
}
