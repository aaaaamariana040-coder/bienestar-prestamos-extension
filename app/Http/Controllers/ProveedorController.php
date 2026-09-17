<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProveedorRequest;
use App\Models\Proveedor;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ProveedorController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', Proveedor::class);

        $proveedores = Proveedor::orderBy('nombre_empresa')->get();

        return view('proveedores.index', compact('proveedores'));
    }

    public function store(StoreProveedorRequest $request): RedirectResponse
    {
        $this->authorize('create', Proveedor::class);

        Proveedor::create($request->validated());

        return redirect()->route('proveedores.index')->with('status', 'Proveedor registrado correctamente.');
    }
}
