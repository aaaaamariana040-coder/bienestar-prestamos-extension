<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDtfRequest;
use App\Http\Requests\UpdateDtfRequest;
use App\Models\TasaDtf;
use App\Services\DtfService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Captura y consulta del DTF. El acceso lo controlan las rutas con los permisos
 * modalidades.ver y modalidades.gestionar.
 */
class DtfController extends Controller
{
    public function index(): View
    {
        $tasas = TasaDtf::orderByDesc('fecha_vigencia')->get();

        return view('dtf.index', compact('tasas'));
    }

    public function store(StoreDtfRequest $request, DtfService $dtfService): RedirectResponse
    {
        $dtfService->crear($request->validated(), $request->user());

        return redirect()->route('dtf.index')->with('status', 'DTF registrado correctamente.');
    }

    public function update(UpdateDtfRequest $request, TasaDtf $dtf, DtfService $dtfService): RedirectResponse
    {
        $cambio = $dtfService->actualizar($dtf, $request->validated(), $request->user());

        return redirect()->route('dtf.index')
            ->with('status', $cambio ? 'DTF actualizado correctamente.' : 'El DTF ya tenía esos valores; no hubo cambios.');
    }
}
