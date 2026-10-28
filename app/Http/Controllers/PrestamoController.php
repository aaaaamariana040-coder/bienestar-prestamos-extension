<?php

namespace App\Http\Controllers;

use App\Models\Prestamo;
use App\Models\Proveedor;
use App\Services\CalendarioQuincenalService;
use App\Services\DesembolsoService;
use App\Services\PrestamoService;
use App\Services\QuincenaService;
use App\Services\ReliquidacionService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PrestamoController extends Controller
{
    /**
     * Lista de préstamos. `q` filtra por el nombre del empleado (contiene el texto, sin
     * importar mayúsculas ni tildes) o por su cédula (empieza por lo digitado). El filtro se
     * aplica en PHP para que se comporte igual en MariaDB y en SQLite.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Prestamo::class);

        $busqueda = trim((string) $request->query('q', ''));

        $prestamos = Prestamo::with(['empleado', 'tipoPrestamo'])
            ->orderByDesc('fecha_autorizacion')
            ->get();

        if ($busqueda !== '') {
            $texto = $this->normalizar($busqueda);
            $prestamos = $prestamos->filter(fn (Prestamo $prestamo): bool => str_contains($this->normalizar($prestamo->empleado->nombre_completo), $texto)
                || str_starts_with((string) $prestamo->cedula_empleado, $busqueda))->values();
        }

        return view('prestamos.index', compact('prestamos', 'busqueda'));
    }

    private function normalizar(string $texto): string
    {
        return Str::lower(Str::ascii($texto));
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
