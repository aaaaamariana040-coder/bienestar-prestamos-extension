<?php

use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\EmpleadoController;
use App\Http\Controllers\UsuarioController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('guest')->group(function () {
    Route::get('login', [SessionController::class, 'create'])->name('login');
    Route::post('login', [SessionController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [SessionController::class, 'destroy'])->name('logout');

    Route::resource('empleados', EmpleadoController::class);
    Route::get('empleados/{empleado}/eliminar', [EmpleadoController::class, 'confirmarEliminacion'])
        ->name('empleados.confirmar-eliminacion');

    Route::get('usuarios/crear', [UsuarioController::class, 'create'])->name('usuarios.create');
    Route::post('usuarios', [UsuarioController::class, 'store'])->name('usuarios.store');
});
