<?php

use App\Http\Controllers\AbonoCesantiasController;
use App\Http\Controllers\AuditoriaController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\CuantiaSmmlvController;
use App\Http\Controllers\DesembolsoController;
use App\Http\Controllers\DocumentoSolicitudController;
use App\Http\Controllers\EmpleadoController;
use App\Http\Controllers\EnvioComiteController;
use App\Http\Controllers\InformeGuardadoController;
use App\Http\Controllers\PagoController;
use App\Http\Controllers\PrestamoController;
use App\Http\Controllers\PresupuestoAnualController;
use App\Http\Controllers\PrimaSeguroController;
use App\Http\Controllers\ProveedorController;
use App\Http\Controllers\QuincenaController;
use App\Http\Controllers\ReporteController;
use App\Http\Controllers\RetiroEmpleadoController;
use App\Http\Controllers\SimuladorController;
use App\Http\Controllers\SmmlvAnualController;
use App\Http\Controllers\SolicitudCreditoController;
use App\Http\Controllers\TasaIntController;
use App\Http\Controllers\TasaVehiculoController;
use App\Http\Controllers\TipoPrestamoController;
use App\Http\Controllers\UsuarioController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return Auth::check() ? redirect()->route('inicio') : view('welcome');
});

Route::middleware('guest')->group(function () {
    Route::get('login', [SessionController::class, 'create'])->name('login');
    Route::post('login', [SessionController::class, 'store']);
});

Route::middleware('auth')->post('logout', [SessionController::class, 'destroy'])->name('logout');

/*
 * Todas las rutas de la aplicación exigen sesión y cuenta habilitada; además cada una
 * lleva el middleware «can:permiso» que corresponde (config/permisos.php). Las únicas
 * sin permiso propio son el inicio y el cambio de la propia contraseña
 * (ver tests/Feature/CoberturaDeRutasTest.php).
 */
Route::middleware(['auth', 'habilitado'])->group(function () {
    Route::view('inicio', 'inicio')->name('inicio');

    Route::get('perfil/contrasena', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('perfil/contrasena', [PasswordController::class, 'update'])->name('password.update');

    // Administración
    Route::middleware('can:usuarios.gestionar')->group(function () {
        Route::get('usuarios', [UsuarioController::class, 'index'])->name('usuarios.index');
        Route::get('usuarios/crear', [UsuarioController::class, 'create'])->name('usuarios.create');
        Route::post('usuarios', [UsuarioController::class, 'store'])->name('usuarios.store');
        Route::get('usuarios/{usuario}/editar', [UsuarioController::class, 'edit'])->name('usuarios.edit');
        Route::put('usuarios/{usuario}', [UsuarioController::class, 'update'])->name('usuarios.update');
        Route::patch('usuarios/{usuario}/habilitar', [UsuarioController::class, 'habilitar'])->name('usuarios.habilitar');
        Route::patch('usuarios/{usuario}/deshabilitar', [UsuarioController::class, 'deshabilitar'])->name('usuarios.deshabilitar');
    });

    Route::get('auditoria', [AuditoriaController::class, 'index'])->middleware('can:auditoria.ver')->name('auditoria.index');

    // Empleados (las rutas de crear/editar van antes que empleados/{empleado})
    Route::resource('empleados', EmpleadoController::class)->only(['create', 'store', 'edit', 'update'])->middleware('can:empleados.gestionar');
    Route::resource('empleados', EmpleadoController::class)->only(['index', 'show'])->middleware('can:empleados.ver');
    Route::resource('empleados', EmpleadoController::class)->only(['destroy'])->middleware('can:empleados.eliminar');
    Route::post('empleados/{empleado}/retiro', [RetiroEmpleadoController::class, 'store'])->middleware('can:empleados.registrar-retiro')->name('empleados.retiro');

    // Modalidades, tasas y parámetros
    Route::middleware('can:modalidades.gestionar')->group(function () {
        Route::resource('tipos-prestamo', TipoPrestamoController::class)
            ->only(['create', 'store', 'edit', 'update'])
            ->parameters(['tipos-prestamo' => 'tipo_prestamo']);
        Route::patch('tipos-prestamo/{tipo_prestamo}/habilitar', [TipoPrestamoController::class, 'habilitar'])->name('tipos-prestamo.habilitar');
        Route::patch('tipos-prestamo/{tipo_prestamo}/deshabilitar', [TipoPrestamoController::class, 'deshabilitar'])->name('tipos-prestamo.deshabilitar');
        Route::post('tipos-prestamo/{tipo_prestamo}/tasas', [TasaIntController::class, 'store'])->name('tasas-int.store');
        Route::put('tasas/{tasa}', [TasaIntController::class, 'update'])->name('tasas-int.update');
        Route::post('tipos-prestamo/{tipo_prestamo}/cuantias', [CuantiaSmmlvController::class, 'store'])->name('cuantias-smmlv.store');
        Route::put('cuantias/{cuantia}', [CuantiaSmmlvController::class, 'update'])->name('cuantias-smmlv.update');
        Route::delete('cuantias/{cuantia}', [CuantiaSmmlvController::class, 'destroy'])->name('cuantias-smmlv.destroy');
        Route::put('tasa-vehiculo/{tasa_vehiculo}', [TasaVehiculoController::class, 'update'])->name('tasa-vehiculo.update');
    });
    Route::middleware('can:modalidades.ver')->group(function () {
        Route::resource('tipos-prestamo', TipoPrestamoController::class)
            ->only(['index', 'show'])
            ->parameters(['tipos-prestamo' => 'tipo_prestamo']);
        Route::get('tasa-vehiculo', [TasaVehiculoController::class, 'index'])->name('tasa-vehiculo.index');
    });

    Route::get('seguros', [PrimaSeguroController::class, 'index'])->middleware('can:seguros.ver')->name('seguros.index');
    Route::post('seguros', [PrimaSeguroController::class, 'store'])->middleware('can:seguros.gestionar')->name('seguros.store');
    Route::put('seguros/{prima}', [PrimaSeguroController::class, 'update'])->middleware('can:seguros.gestionar')->name('seguros.update');

    Route::get('smmlv', [SmmlvAnualController::class, 'index'])->middleware('can:smmlv.ver')->name('smmlv.index');
    Route::post('smmlv', [SmmlvAnualController::class, 'store'])->middleware('can:smmlv.gestionar')->name('smmlv.store');

    Route::get('presupuestos/sugerencia-vivienda', [PresupuestoAnualController::class, 'sugerenciaVivienda'])
        ->middleware('can:presupuesto.ver')
        ->name('presupuestos.sugerencia-vivienda');
    Route::resource('presupuestos', PresupuestoAnualController::class)
        ->only(['create', 'store', 'edit', 'update'])
        ->parameters(['presupuestos' => 'presupuesto'])
        ->middleware('can:presupuesto.gestionar');
    Route::resource('presupuestos', PresupuestoAnualController::class)
        ->only(['index', 'show'])
        ->parameters(['presupuestos' => 'presupuesto'])
        ->middleware('can:presupuesto.ver');

    // Solicitudes (crear y buscar empleado van antes que solicitudes/{solicitud})
    Route::middleware('can:solicitudes.registrar')->group(function () {
        Route::get('solicitudes/crear', [SolicitudCreditoController::class, 'create'])->name('solicitudes.create');
        Route::get('solicitudes/empleado/{cedula}', [SolicitudCreditoController::class, 'buscarEmpleado'])
            ->where('cedula', '[0-9]{1,10}')
            ->name('solicitudes.buscar-empleado');
        Route::post('solicitudes', [SolicitudCreditoController::class, 'store'])->name('solicitudes.store');
        Route::post('solicitudes/{solicitud}/documentos', [DocumentoSolicitudController::class, 'store'])->name('documentos.store');
    });
    Route::middleware('can:solicitudes.ver')->group(function () {
        Route::get('solicitudes', [SolicitudCreditoController::class, 'index'])->name('solicitudes.index');
        Route::get('solicitudes/{solicitud}', [SolicitudCreditoController::class, 'show'])->name('solicitudes.show');
        Route::get('documentos/{documento}/descargar', [DocumentoSolicitudController::class, 'download'])->name('documentos.download');
    });
    Route::delete('documentos/{documento}', [DocumentoSolicitudController::class, 'destroy'])->middleware('can:solicitudes.eliminar-documentos')->name('documentos.destroy');
    Route::patch('solicitudes/{solicitud}/aprobar', [SolicitudCreditoController::class, 'aprobar'])->middleware('can:solicitudes.aprobar')->name('solicitudes.aprobar');
    Route::patch('solicitudes/{solicitud}/rechazar', [SolicitudCreditoController::class, 'rechazar'])->middleware('can:solicitudes.rechazar')->name('solicitudes.rechazar');
    Route::middleware('can:solicitudes.enviar-comite')->group(function () {
        Route::get('solicitudes/{solicitud}/comite', [EnvioComiteController::class, 'previa'])->name('solicitudes.comite.previa');
        Route::post('solicitudes/{solicitud}/comite', [EnvioComiteController::class, 'enviar'])->name('solicitudes.comite.enviar');
    });

    Route::middleware('can:simulador.usar')->group(function () {
        Route::get('simulador', [SimuladorController::class, 'index'])->name('simulador.index');
        Route::get('simulador/empleado/{cedula}', [SimuladorController::class, 'empleado'])
            ->where('cedula', '[0-9]{1,10}')
            ->name('simulador.empleado');
        Route::get('simulador/empleado/{cedula}/modalidad/{tipo_prestamo}', [SimuladorController::class, 'modalidad'])
            ->where('cedula', '[0-9]{1,10}')
            ->name('simulador.modalidad');
        Route::get('simulador/tasa', [SimuladorController::class, 'tasa'])->name('simulador.tasa');
        Route::get('simulador/calcular', [SimuladorController::class, 'calcular'])->name('simulador.calcular');
    });

    Route::get('proveedores', [ProveedorController::class, 'index'])->middleware('can:proveedores.ver')->name('proveedores.index');
    Route::post('proveedores', [ProveedorController::class, 'store'])->middleware('can:proveedores.gestionar')->name('proveedores.store');

    // Préstamos y pagos
    Route::middleware('can:prestamos.ver')->group(function () {
        Route::get('prestamos', [PrestamoController::class, 'index'])->name('prestamos.index');
        Route::get('prestamos/{prestamo}', [PrestamoController::class, 'show'])->name('prestamos.show');
    });
    Route::post('prestamos/{prestamo}/desembolsos', [DesembolsoController::class, 'store'])->middleware('can:desembolsos.registrar')->name('desembolsos.store');
    Route::post('prestamos/{prestamo}/cuotas/{cuota}/pagos', [PagoController::class, 'storeCuota'])->middleware('can:pagos.registrar-cuota')->name('pagos.store-cuota');
    Route::middleware('can:pagos.registrar-abono')->group(function () {
        Route::get('prestamos/{prestamo}/abonos/vista-previa', [PagoController::class, 'vistaPreviaAbono'])->name('pagos.vista-previa-abono');
        Route::post('prestamos/{prestamo}/abonos', [PagoController::class, 'storeAbono'])->name('pagos.store-abono');
        Route::post('prestamos/{prestamo}/cesantias', [AbonoCesantiasController::class, 'store'])->name('cesantias.store');
    });
    Route::post('prestamos/{prestamo}/cancelacion', [PagoController::class, 'storeCancelacion'])->middleware('can:pagos.cancelar-prestamo')->name('pagos.store-cancelacion');

    Route::middleware('can:quincenas.ver')->group(function () {
        Route::get('quincenas', [QuincenaController::class, 'index'])->name('quincenas.index');
        Route::get('quincenas/proceso/{proceso}', [QuincenaController::class, 'proceso'])->name('quincenas.proceso');
    });
    Route::middleware('can:quincenas.confirmar')->group(function () {
        Route::get('quincenas/{anio}/{quincena}', [QuincenaController::class, 'vistaPrevia'])
            ->where(['anio' => '[0-9]{4}', 'quincena' => '[0-9]{1,2}'])
            ->name('quincenas.vista-previa');
        Route::post('quincenas/{anio}/{quincena}/confirmar', [QuincenaController::class, 'confirmar'])
            ->where(['anio' => '[0-9]{4}', 'quincena' => '[0-9]{1,2}'])
            ->name('quincenas.confirmar');
    });

    // Reportes e informes
    Route::middleware('can:reportes.ver')->group(function () {
        Route::get('reportes/prestamos', [ReporteController::class, 'prestamos'])->name('reportes.prestamos');
        Route::get('reportes/pagos', [ReporteController::class, 'pagos'])->name('reportes.pagos');
        Route::get('reportes/historial-pagos', [ReporteController::class, 'historialPagos'])->name('reportes.historial-pagos');
        Route::get('reportes/historial-pagos/imprimir', [ReporteController::class, 'imprimirHistorialPagos'])->name('reportes.historial-pagos.imprimir');
        Route::get('reportes/cartera', [ReporteController::class, 'cartera'])->name('reportes.cartera');
        Route::get('reportes/presupuesto', [ReporteController::class, 'presupuesto'])->name('reportes.presupuesto');
        Route::get('informes', [InformeGuardadoController::class, 'index'])->name('informes.index');
        Route::get('informes/{informe}', [InformeGuardadoController::class, 'show'])->name('informes.show');
    });
    Route::post('informes', [InformeGuardadoController::class, 'store'])->middleware('can:informes.guardar')->name('informes.store');
});
