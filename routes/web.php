<?php

use App\Http\Controllers\AbonoCesantiasController;
use App\Http\Controllers\AuditoriaController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\CuantiaSmmlvController;
use App\Http\Controllers\DesembolsoController;
use App\Http\Controllers\EmpleadoController;
use App\Http\Controllers\InformeGuardadoController;
use App\Http\Controllers\PagoController;
use App\Http\Controllers\PrestamoController;
use App\Http\Controllers\PresupuestoAnualController;
use App\Http\Controllers\ProveedorController;
use App\Http\Controllers\ReporteController;
use App\Http\Controllers\RetiroEmpleadoController;
use App\Http\Controllers\SmmlvAnualController;
use App\Http\Controllers\SolicitudCreditoController;
use App\Http\Controllers\TasaIntController;
use App\Http\Controllers\TasaVehiculoController;
use App\Http\Controllers\TipoPrestamoController;
use App\Http\Controllers\UsuarioController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('guest')->group(function () {
    Route::get('login', [SessionController::class, 'create'])->name('login');
    Route::post('login', [SessionController::class, 'store']);
});

Route::middleware('auth')->post('logout', [SessionController::class, 'destroy'])->name('logout');

Route::middleware(['auth', 'habilitado'])->group(function () {
    Route::resource('empleados', EmpleadoController::class);
    Route::get('empleados/{empleado}/eliminar', [EmpleadoController::class, 'confirmarEliminacion'])
        ->name('empleados.confirmar-eliminacion');
    Route::post('empleados/{empleado}/retiro', [RetiroEmpleadoController::class, 'store'])->name('empleados.retiro');

    Route::get('usuarios', [UsuarioController::class, 'index'])->name('usuarios.index');
    Route::get('usuarios/crear', [UsuarioController::class, 'create'])->name('usuarios.create');
    Route::post('usuarios', [UsuarioController::class, 'store'])->name('usuarios.store');
    Route::get('usuarios/{usuario}/editar', [UsuarioController::class, 'edit'])->name('usuarios.edit');
    Route::put('usuarios/{usuario}', [UsuarioController::class, 'update'])->name('usuarios.update');
    Route::patch('usuarios/{usuario}/habilitar', [UsuarioController::class, 'habilitar'])->name('usuarios.habilitar');
    Route::patch('usuarios/{usuario}/deshabilitar', [UsuarioController::class, 'deshabilitar'])->name('usuarios.deshabilitar');

    Route::get('perfil/contrasena', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('perfil/contrasena', [PasswordController::class, 'update'])->name('password.update');

    Route::resource('tipos-prestamo', TipoPrestamoController::class)
        ->except(['destroy'])
        ->parameters(['tipos-prestamo' => 'tipo_prestamo']);
    Route::patch('tipos-prestamo/{tipo_prestamo}/habilitar', [TipoPrestamoController::class, 'habilitar'])
        ->name('tipos-prestamo.habilitar');
    Route::patch('tipos-prestamo/{tipo_prestamo}/deshabilitar', [TipoPrestamoController::class, 'deshabilitar'])
        ->name('tipos-prestamo.deshabilitar');

    Route::post('tipos-prestamo/{tipo_prestamo}/tasas', [TasaIntController::class, 'store'])->name('tasas-int.store');

    Route::post('tipos-prestamo/{tipo_prestamo}/cuantias', [CuantiaSmmlvController::class, 'store'])->name('cuantias-smmlv.store');
    Route::put('cuantias/{cuantia}', [CuantiaSmmlvController::class, 'update'])->name('cuantias-smmlv.update');
    Route::delete('cuantias/{cuantia}', [CuantiaSmmlvController::class, 'destroy'])->name('cuantias-smmlv.destroy');

    Route::get('tasa-vehiculo', [TasaVehiculoController::class, 'index'])->name('tasa-vehiculo.index');
    Route::put('tasa-vehiculo/{tasa_vehiculo}', [TasaVehiculoController::class, 'update'])->name('tasa-vehiculo.update');

    Route::get('smmlv', [SmmlvAnualController::class, 'index'])->name('smmlv.index');
    Route::post('smmlv', [SmmlvAnualController::class, 'store'])->name('smmlv.store');

    Route::get('solicitudes', [SolicitudCreditoController::class, 'index'])->name('solicitudes.index');
    Route::get('solicitudes/crear', [SolicitudCreditoController::class, 'create'])->name('solicitudes.create');
    Route::post('solicitudes', [SolicitudCreditoController::class, 'store'])->name('solicitudes.store');
    Route::get('solicitudes/{solicitud}', [SolicitudCreditoController::class, 'show'])->name('solicitudes.show');
    Route::patch('solicitudes/{solicitud}/aprobar', [SolicitudCreditoController::class, 'aprobar'])->name('solicitudes.aprobar');
    Route::patch('solicitudes/{solicitud}/rechazar', [SolicitudCreditoController::class, 'rechazar'])->name('solicitudes.rechazar');

    Route::get('prestamos', [PrestamoController::class, 'index'])->name('prestamos.index');
    Route::get('prestamos/{prestamo}', [PrestamoController::class, 'show'])->name('prestamos.show');

    Route::post('prestamos/{prestamo}/desembolsos', [DesembolsoController::class, 'store'])->name('desembolsos.store');

    Route::post('prestamos/{prestamo}/cuotas/{cuota}/pagos', [PagoController::class, 'storeCuota'])->name('pagos.store-cuota');
    Route::post('prestamos/{prestamo}/abonos', [PagoController::class, 'storeAbono'])->name('pagos.store-abono');
    Route::post('prestamos/{prestamo}/cancelacion', [PagoController::class, 'storeCancelacion'])->name('pagos.store-cancelacion');
    Route::post('prestamos/{prestamo}/cesantias', [AbonoCesantiasController::class, 'store'])->name('cesantias.store');

    Route::get('proveedores', [ProveedorController::class, 'index'])->name('proveedores.index');
    Route::post('proveedores', [ProveedorController::class, 'store'])->name('proveedores.store');

    Route::get('presupuestos/sugerencia-vivienda', [PresupuestoAnualController::class, 'sugerenciaVivienda'])
        ->name('presupuestos.sugerencia-vivienda');
    Route::resource('presupuestos', PresupuestoAnualController::class)
        ->only(['index', 'create', 'store', 'show', 'edit', 'update'])
        ->parameters(['presupuestos' => 'presupuesto']);

    Route::get('auditoria', [AuditoriaController::class, 'index'])->name('auditoria.index');

    Route::get('reportes/prestamos', [ReporteController::class, 'prestamos'])->name('reportes.prestamos');
    Route::get('reportes/pagos', [ReporteController::class, 'pagos'])->name('reportes.pagos');
    Route::get('reportes/cartera', [ReporteController::class, 'cartera'])->name('reportes.cartera');
    Route::get('reportes/presupuesto', [ReporteController::class, 'presupuesto'])->name('reportes.presupuesto');

    Route::get('informes', [InformeGuardadoController::class, 'index'])->name('informes.index');
    Route::post('informes', [InformeGuardadoController::class, 'store'])->name('informes.store');
    Route::get('informes/{informe}', [InformeGuardadoController::class, 'show'])->name('informes.show');
});
