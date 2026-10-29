<?php

namespace Tests\Concerns;

use App\Enums\EstadoCuota;
use App\Models\CuantiaSmmlv;
use App\Models\Cuota;
use App\Models\DocumentoSolicitud;
use App\Models\Empleado;
use App\Models\InformeGuardado;
use App\Models\Prestamo;
use App\Models\PresupuestoAnual;
use App\Models\PrimaSeguro;
use App\Models\ProcesoQuincena;
use App\Models\SolicitudCredito;
use App\Models\TasaDtf;
use App\Models\TasaInt;
use App\Models\TasaVehiculo;
use App\Models\TipoPrestamo;
use App\Models\Usuario;
use Illuminate\Routing\Route as RutaDeLaravel;
use Illuminate\Support\Facades\Route;

/**
 * Para las pruebas que recorren todas las rutas con nombre de la aplicación: arma
 * datos nuevos para cada parámetro (con los estados que las policies exigen para
 * dejar pasar: préstamo vigente, solicitud radicada…), un cuerpo válido donde el
 * Form Request valida antes de autorizar, y devuelve el estado HTTP.
 */
trait RecorreLasRutas
{
    /**
     * Cada proceso de quincena que crea la prueba usa un año propio, para no chocar con la llave única.
     */
    private static int $procesosCreados = 0;

    /**
     * Rutas que no son una pantalla de la aplicación para un usuario en sesión.
     */
    private const RUTAS_FUERA_DE_LA_PRUEBA = ['login', 'logout'];

    /**
     * @return list<RutaDeLaravel>
     */
    protected function rutasDeLaAplicacion(): array
    {
        return array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            fn (RutaDeLaravel $ruta): bool => $ruta->getName() !== null
                && ! in_array($ruta->getName(), self::RUTAS_FUERA_DE_LA_PRUEBA, true)
                && ! str_starts_with($ruta->getName(), 'storage.')
                && ! str_starts_with($ruta->getName(), 'ignition.'),
        ));
    }

    /**
     * El permiso del middleware «can:» de la ruta, o null si no lo tiene.
     */
    protected function permisoDeLaRuta(RutaDeLaravel $ruta): ?string
    {
        foreach ($ruta->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'can:')) {
                return substr($middleware, 4);
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function parametrosDeLaRuta(RutaDeLaravel $ruta): array
    {
        $prestamo = null;
        $parametros = [];

        foreach ($ruta->parameterNames() as $nombre) {
            $parametros[$nombre] = match ($nombre) {
                'empleado' => Empleado::factory()->create(),
                'usuario' => Usuario::factory()->create(),
                'tipo_prestamo' => TipoPrestamo::factory()->create(),
                'cuantia' => CuantiaSmmlv::factory()->create(),
                'tasa' => TasaInt::factory()->create(),
                'prima' => PrimaSeguro::factory()->create(),
                'dtf' => TasaDtf::factory()->create(),
                'tasa_vehiculo' => TasaVehiculo::factory()->create(),
                'solicitud' => SolicitudCredito::factory()->create(),
                'documento' => DocumentoSolicitud::factory()->create(),
                'tipoPrestamo' => TipoPrestamo::factory()->create(),
                'prestamo' => $prestamo ??= Prestamo::factory()->create(),
                'cuota' => Cuota::factory()->create([
                    'id_prestamo' => ($prestamo ??= Prestamo::factory()->create())->id_prestamo,
                    'estado' => EstadoCuota::Pendiente,
                ]),
                'presupuesto' => PresupuestoAnual::factory()->create(),
                'proceso' => ProcesoQuincena::factory()->create(['anio' => 3000 + self::$procesosCreados++, 'numero_quincena' => 1]),
                'informe' => InformeGuardado::factory()->create(),
                'cedula' => '1234567890',
                'lote' => 'CARGA-20260101-000000-ABCD',
                'anio' => 2026,
                'quincena' => 1,
                default => $this->fail("La prueba no sabe crear el parámetro «{$nombre}» de la ruta {$ruta->getName()}."),
            };
        }

        return $parametros;
    }

    /**
     * Cuerpo válido para las rutas cuyo Form Request valida antes de autorizar:
     * con un cuerpo vacío responderían 302 (validación) y no mostrarían quién
     * puede o no puede usarlas.
     *
     * @return array<string, string>
     */
    protected function cuerpoDeLaRuta(RutaDeLaravel $ruta): array
    {
        return match ($ruta->getName()) {
            'usuarios.store' => ['cedula' => '5550001', 'nombre' => 'Persona', 'apellido' => 'Ficticia', 'password' => 'Clave12345', 'password_confirmation' => 'Clave12345', 'rol' => 'FUNCIONARIO_BIENESTAR'],
            'tasas-int.update' => ['tasa_anual' => '4,5'],
            'seguros.store' => ['concepto' => 'vida_deudores', 'anio' => '2150', 'tasa' => '0,0003231'],
            'seguros.update' => ['tasa' => '0,0004'],
            'usuarios.update' => ['nombre' => 'Persona', 'apellido' => 'Ficticia', 'rol' => 'FUNCIONARIO_BIENESTAR'],
            default => [],
        };
    }

    protected function estadoDeLaRuta(Usuario $usuario, RutaDeLaravel $ruta): int
    {
        return $this->actingAs($usuario)
            ->call($ruta->methods()[0], route($ruta->getName(), $this->parametrosDeLaRuta($ruta)), $this->cuerpoDeLaRuta($ruta))
            ->getStatusCode();
    }
}
