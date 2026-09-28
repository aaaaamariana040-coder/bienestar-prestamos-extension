<?php

namespace App\Http\Controllers;

use App\Enums\TipoInforme;
use App\Http\Requests\GuardarInformeRequest;
use App\Models\InformeGuardado;
use App\Services\InformeGuardadoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InformeGuardadoController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', InformeGuardado::class);

        $tipo = TipoInforme::tryFrom((string) $request->query('tipo'));
        $desde = $request->query('desde');
        $hasta = $request->query('hasta');

        $informes = InformeGuardado::with('generadoPor')
            ->when($tipo, fn ($query) => $query->where('tipo', $tipo->value))
            ->when($desde, fn ($query) => $query->whereDate('fecha_corte', '>=', $desde))
            ->when($hasta, fn ($query) => $query->whereDate('fecha_corte', '<=', $hasta))
            ->orderByDesc('fecha_corte')
            ->orderByDesc('id_informe')
            ->paginate(20)
            ->withQueryString();

        $tipos = TipoInforme::cases();

        return view('informes.index', compact('informes', 'tipos', 'tipo', 'desde', 'hasta'));
    }

    public function show(InformeGuardado $informe): View
    {
        $this->authorize('view', $informe);

        $informe->loadMissing('generadoPor');

        return view('informes.show', compact('informe'));
    }

    public function store(GuardarInformeRequest $request, InformeGuardadoService $informeGuardadoService): RedirectResponse
    {
        $informe = $informeGuardadoService->generar(
            TipoInforme::from($request->validated('tipo')),
            $request->validated(),
            $request->user(),
        );

        return redirect()->route('informes.show', $informe)->with('status', 'Informe generado y guardado correctamente.');
    }
}
