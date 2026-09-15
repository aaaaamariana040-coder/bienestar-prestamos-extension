<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class SessionController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'cedula' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt([...$credentials, 'habilitado' => true])) {
            return back()
                ->withInput($request->only('cedula'))
                ->withErrors(['cedula' => 'La cedula o la contrasena no son correctas.']);
        }

        $request->session()->regenerate();

        return redirect()->route('empleados.index');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
