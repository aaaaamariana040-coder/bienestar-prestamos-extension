<?php

namespace App\Http\Controllers;

use App\Models\Prestamo;
use App\Models\Proveedor;
use App\Services\CalendarioQuincenalService;
use App\Services\DesembolsoService;
use App\Services\PrestamoService;
use App\Services\QuincenaService;
use App\Services\ReliquidacionService;
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

    public function show(Prestamo $prestamo, DesembolsoService $desembolsoService, ReliquidacionService $reliquidacionService, PrestamoService $prestamoService, QuincenaService $quincenaService, CalendarioQuincenalService $calendario): View
    {
        $this->authorize('view', $prestamo);

        $prestamo->loadMissing(['empleado', 'tipoPrestamo', 'cuotas', 'desembolsos.proveedor', 'pagos', 'reliquidaciones', 'abonosCesantias']);

        $tramosEsperados = $desembolsoService->tramosEsperados($prestamo);
        $proveedores = Proveedor::orderBy('nombre_empresa')->get();

        $umbralCuotas = $reliquidacionService->umbralCuotas();
        $abonoAplicaAModalidad = $reliquidacionService->aplicaALaModalidad($prestamo);

        $tasa = $prestamoService->tasaDePrestamo($prestamo->loadMissing('tasaInt'));

        $cuotasExcluidas = $quincenaService->cuotasExcluidasDelPrestamo($prestamo);
        $avisoCuotaExcluida = $quincenaService->avisoCuotaExcluida();

        $fechaDesembolso = (string) old('fecha', now()->toDateString());
        $opcionesPrimeraCuota = $prestamo->pendiente_desembolso && strtotime($fechaDesembolso) !== false ? $calendario->opcionesPrimeraCuota($fechaDesembolso) : null;
        $cierrePrimeraCuota = ! $prestamo->pendiente_desembolso && $prestamo->inicio_primera_cuota !== null ? $calendario->cierreDeNovedades($prestamo->inicio_primera_cuota) : null;

        return view('prestamos.show', compact('prestamo', 'opcionesPrimeraCuota', 'cierrePrimeraCuota', 'tramosEsperados', 'proveedores', 'umbralCuotas', 'abonoAplicaAModalidad', 'tasa', 'cuotasExcluidas', 'avisoCuotaExcluida'));
    }
}
