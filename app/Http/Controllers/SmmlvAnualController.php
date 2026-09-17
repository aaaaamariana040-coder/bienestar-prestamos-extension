<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSmmlvAnualRequest;
use App\Models\SmmlvAnual;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class SmmlvAnualController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', SmmlvAnual::class);

        $valoresSmmlv = SmmlvAnual::orderByDesc('anio')->get();

        return view('smmlv.index', compact('valoresSmmlv'));
    }

    public function store(StoreSmmlvAnualRequest $request): RedirectResponse
    {
        $this->authorize('create', SmmlvAnual::class);

        SmmlvAnual::create($request->validated());

        return redirect()->route('smmlv.index')->with('status', 'Valor de SMMLV registrado correctamente.');
    }
}
