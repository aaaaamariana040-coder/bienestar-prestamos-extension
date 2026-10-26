<?php

namespace Database\Seeders;

use App\Models\Empleado;
use App\Models\Prestamo;
use App\Models\PresupuestoAnual;
use App\Models\Proveedor;
use App\Models\SmmlvAnual;
use App\Models\SolicitudCredito;
use App\Models\TasaInt;
use App\Models\TipoPrestamo;
use App\Models\Usuario;
use App\Services\CalendarioQuincenalService;
use App\Services\DesembolsoService;
use App\Services\EmpleadoService;
use App\Services\PagoService;
use App\Services\SolicitudCreditoService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Prepara los estados de datos que necesita la ejecución manual de
 * docs/plan-pruebas.md (PP-001 a PP-058). No se llama desde DatabaseSeeder:
 *
 *     php artisan migrate:fresh --seed
 *     php artisan db:seed --class=PlanPruebasSeeder
 *
 * Los préstamos se crean por el flujo real (SolicitudCreditoService::aprobar,
 * DesembolsoService, PagoService) para que la tabla de amortización, el
 * presupuesto comprometido y la auditoría sean los que generaría la aplicación.
 * Las fechas de autorización se desplazan con Carbon::setTestNow() relativas a
 * hoy, de modo que las cuotas vencidas siguen vencidas en cualquier fecha de siembra.
 */
class PlanPruebasSeeder extends Seeder
{
    public const ANIO = 2026;

    /**
     * SMMLV 2026 sin auxilio de transporte (Decreto 1469 de 2025, ratificado por el
     * Decreto 0159 de 2026); es la base de las cuantías máximas por modalidad.
     */
    public const SMMLV = 1_750_905;

    /**
     * Tasa base (IPC) de todas las modalidades, en puntos porcentuales como tasa_int.tasa_anual
     * (vivienda ≤ 5% según Acuerdo 11/2023; vehículo le suma los puntos de tasa_vehiculo).
     */
    public const IPC = 5.0;

    public const CEDULA_ADMINISTRADOR = '1000000000';

    public const CEDULA_FUNCIONARIO = '1000000001';

    public const CEDULA_VEHICULO_SEGUNDA = '1000000002';

    public const CEDULA_VEHICULO_PRIMERA = '1000000003';

    public const CEDULA_CAPACIDAD_BAJA = '1000000004';

    public const CEDULA_ESTADO_CUENTA = '1000000006';

    public const CEDULA_CUOTA_VENCIDA = '1000000007';

    public const CEDULA_DESHIPOTECA = '1000000008';

    public const CEDULA_SOLICITUD_LISTA = '1000000009';

    public const CEDULA_PRESUPUESTO_AGOTADO = '1000000010';

    public const ID_PROVEEDOR_DESHIPOTECA = 'PROV-PP036';

    public const CANTIDAD_EMPLEADOS_VOLUMEN = 40;

    private const MODALIDAD_EDUCACION = 'Educación';

    private const MODALIDAD_CALAMIDAD = 'Calamidad doméstica y necesidades especiales';

    private const MODALIDAD_VEHICULO = 'Vehículo';

    private const MODALIDAD_VIVIENDA_COMPRA = 'Vivienda — compra de inmueble';

    private const MODALIDAD_DESHIPOTECA = 'Vivienda — cancelación total de hipoteca (deshipoteca)';

    /**
     * Días hacia atrás, como máximo, en que se autoriza el préstamo más antiguo (estado de cuenta):
     * tres vencimientos de calendario ya pasados (hasta 46 días) más los 15 de la primera cuota.
     */
    private const DIAS_MAXIMOS_ATRAS = 62;

    private Usuario $administrador;

    /**
     * Reloj simulado que había antes de sembrar (null fuera de los tests), para restaurarlo.
     */
    private ?Carbon $relojOriginal = null;

    private Usuario $funcionario;

    /**
     * @var array<int, array{0: string, 1: string, 2: string}>
     */
    private array $resumen = [];

    public function __construct(
        private readonly SolicitudCreditoService $solicitudCreditoService,
        private readonly DesembolsoService $desembolsoService,
        private readonly PagoService $pagoService,
        private readonly EmpleadoService $empleadoService,
    ) {}

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('PlanPruebasSeeder no se ejecuta en producción.');

            return;
        }

        if (! $this->precondicionesCumplidas()) {
            return;
        }

        $relojOriginal = Carbon::getTestNow();
        $this->relojOriginal = $relojOriginal !== null ? Carbon::instance($relojOriginal) : null;

        try {
            $this->sembrarParametros();
            $this->sembrarFuncionario();
            $this->sembrarPresupuestoAgotado();
            $this->sembrarVehiculo();
            $this->sembrarCapacidadBaja();
            $this->sembrarEstadoDeCuenta();
            $this->sembrarCuotaVencida();
            $this->sembrarDeshipoteca();
            $this->sembrarSolicitudLista();
            $this->sembrarVolumen();
        } finally {
            $this->restaurarReloj();
        }

        $this->command?->table(['Cédula', 'Escenario', 'Casos'], $this->resumen);
        $this->command?->info('Todos los usuarios de prueba usan la contraseña "password".');
    }

    private function precondicionesCumplidas(): bool
    {
        if (Empleado::whereKey(self::CEDULA_FUNCIONARIO)->exists()) {
            $this->command?->warn('PlanPruebasSeeder ya se ejecutó sobre esta base de datos. Corre migrate:fresh --seed antes de volver a sembrar.');

            return false;
        }

        $administrador = Usuario::find(self::CEDULA_ADMINISTRADOR);

        if ($administrador === null || ! TipoPrestamo::where('nombre', self::MODALIDAD_VEHICULO)->exists()) {
            $this->command?->error('Falta el seeder base: corre primero php artisan migrate:fresh --seed.');

            return false;
        }

        if (now()->subDays(self::DIAS_MAXIMOS_ATRAS)->year !== self::ANIO || now()->year !== self::ANIO) {
            $this->command?->error('Los escenarios con fechas pasadas deben caer dentro de '.self::ANIO.'; ajusta PlanPruebasSeeder::ANIO.');

            return false;
        }

        $this->administrador = $administrador;

        return true;
    }

    /**
     * SMMLV, tasa (IPC) y presupuesto del año para todas las modalidades.
     * Calamidad doméstica se deja fuera: su presupuesto lo crea sembrarPresupuestoAgotado().
     */
    private function sembrarParametros(): void
    {
        SmmlvAnual::create([
            'anio' => self::ANIO,
            'valor' => self::SMMLV,
            'fuente_normativa' => 'Decreto 1469 de 2025 (ratificado por el Decreto 0159 de 2026)',
        ]);

        foreach (TipoPrestamo::all() as $tipoPrestamo) {
            TasaInt::create([
                'id_tipo' => $tipoPrestamo->id_tipo,
                'anio' => self::ANIO,
                'tasa_anual' => self::IPC,
                'fuente_normativa' => 'Acuerdo Directivo No. 11/2023',
            ]);

            if ($tipoPrestamo->nombre === self::MODALIDAD_CALAMIDAD) {
                continue;
            }

            PresupuestoAnual::create([
                'anio' => self::ANIO,
                'id_tipo' => $tipoPrestamo->id_tipo,
                'monto_asignado' => str_starts_with($tipoPrestamo->nombre, 'Vivienda') ? 300_000_000 : 150_000_000,
                'monto_comprometido' => 0,
            ]);
        }

        $this->resumen[] = ['—', 'SMMLV, tasas y presupuestos '.self::ANIO, 'PP-025, PP-027 a PP-032, PP-037'];
    }

    /**
     * Funcionario Bienestar con usuario y un préstamo vigente (el usuario y el empleado comparten cédula, pero no tienen vínculo).
     */
    private function sembrarFuncionario(): void
    {
        $empleado = $this->crearEmpleado(self::CEDULA_FUNCIONARIO, 'Prueba — Funcionario Bienestar', 6_000_000);

        $this->funcionario = Usuario::factory()->create([
            'cedula' => self::CEDULA_FUNCIONARIO,
            'nombre' => 'Funcionario',
            'apellido' => 'Prueba',
        ]);

        $this->aprobarPrestamo($empleado, self::MODALIDAD_EDUCACION, 5_000_000, 24, now());

        $this->resumen[] = [$empleado->cedula, 'Funcionario Bienestar con usuario y préstamo', 'PP-006, PP-017, PP-053, PP-055'];
    }

    private function sembrarPresupuestoAgotado(): void
    {
        PresupuestoAnual::create([
            'anio' => self::ANIO,
            'id_tipo' => $this->modalidad(self::MODALIDAD_CALAMIDAD)->id_tipo,
            'monto_asignado' => 20_000_000,
            'monto_comprometido' => 20_000_000,
        ]);

        $empleado = $this->crearEmpleado(self::CEDULA_PRESUPUESTO_AGOTADO, 'Prueba — Presupuesto Agotado', 6_000_000);

        $this->radicarSolicitud($empleado, self::MODALIDAD_CALAMIDAD, 3_000_000, 24, now());

        $this->resumen[] = [$empleado->cedula, 'Calamidad doméstica sin disponible + solicitud radicada', 'PP-023, PP-030'];
    }

    /**
     * Un empleado con un préstamo de vehículo previo y una segunda solicitud,
     * y otro con su primera solicitud al mismo plazo (3 años = 72 quincenas).
     */
    private function sembrarVehiculo(): void
    {
        $segunda = $this->crearEmpleado(self::CEDULA_VEHICULO_SEGUNDA, 'Prueba — Vehículo Segunda Solicitud', 6_000_000);
        $this->aprobarPrestamo($segunda, self::MODALIDAD_VEHICULO, 30_000_000, 72, now());
        $this->radicarSolicitud($segunda, self::MODALIDAD_VEHICULO, 20_000_000, 72, now());

        $primera = $this->crearEmpleado(self::CEDULA_VEHICULO_PRIMERA, 'Prueba — Vehículo Primera Solicitud', 6_000_000);
        $this->radicarSolicitud($primera, self::MODALIDAD_VEHICULO, 20_000_000, 72, now());

        $this->resumen[] = [$segunda->cedula, 'Vehículo: préstamo previo + 2.ª solicitud radicada (IPC+2)', 'PP-022'];
        $this->resumen[] = [$primera->cedula, 'Vehículo: 1.ª solicitud radicada (IPC+0)', 'PP-022'];
    }

    private function sembrarCapacidadBaja(): void
    {
        $empleado = $this->crearEmpleado(self::CEDULA_CAPACIDAD_BAJA, 'Prueba — Capacidad Baja', 3_000_000, capacidadEndeudamiento: 100_000);

        $this->radicarSolicitud($empleado, self::MODALIDAD_EDUCACION, 10_000_000, 24, now());

        $this->resumen[] = [$empleado->cedula, 'Capacidad de endeudamiento 100.000 + solicitud radicada', 'PP-029'];
    }

    /**
     * Préstamo desembolsado cuyas cuotas 1 a 3 son los tres últimos vencimientos de calendario
     * (día 15 y fin de mes) ya pasados, pagadas en su fecha. No queda en mora: la cuota 4 vence
     * en el próximo vencimiento. La autorización es 15 días antes de la primera cuota.
     */
    private function sembrarEstadoDeCuenta(): void
    {
        $empleado = $this->crearEmpleado(self::CEDULA_ESTADO_CUENTA, 'Prueba — Estado de Cuenta', 6_000_000);
        $fechaAutorizacion = $this->ultimosVencimientosPasados(3)[0]->copy()->subDays(15);

        $prestamo = $this->aprobarPrestamo($empleado, self::MODALIDAD_EDUCACION, 10_000_000, 48, $fechaAutorizacion);

        Carbon::setTestNow($fechaAutorizacion->copy()->addDay());
        $this->desembolsoService->registrar($prestamo, [
            'numero_tramo' => 1,
            'fecha' => now()->toDateString(),
        ], $this->funcionario);

        foreach ($prestamo->cuotas()->orderBy('numero_cuota')->limit(3)->get() as $cuota) {
            Carbon::setTestNow($cuota->fecha_vencimiento);
            $this->pagoService->registrarPagoCuota($prestamo, $cuota, $cuota->fecha_vencimiento->toDateString(), $this->funcionario);
        }

        $this->restaurarReloj();

        $this->resumen[] = [$empleado->cedula, 'Desembolsado, cuotas 1–3 pagadas, sin mora', 'PP-040, PP-046, PP-052'];
    }

    /**
     * Las cuotas 1 y 2 son los dos últimos vencimientos de calendario ya pasados y siguen sin
     * pagarse (la 3 vence en el próximo). La autorización es 15 días antes de la primera cuota.
     */
    private function sembrarCuotaVencida(): void
    {
        $empleado = $this->crearEmpleado(self::CEDULA_CUOTA_VENCIDA, 'Prueba — Cuota Vencida', 6_000_000);

        $this->aprobarPrestamo($empleado, self::MODALIDAD_VIVIENDA_COMPRA, 60_000_000, 240, $this->ultimosVencimientosPasados(2)[0]->copy()->subDays(15));

        $this->resumen[] = [$empleado->cedula, 'Vivienda con cuotas 1 y 2 vencidas sin pagar', 'PP-041, PP-046'];
    }

    private function sembrarDeshipoteca(): void
    {
        Proveedor::create([
            'id_proveedor' => self::ID_PROVEEDOR_DESHIPOTECA,
            'nit' => '900000036-1',
            'nombre_empresa' => 'Banco Hipotecario de Prueba',
            'direccion' => 'Calle 10 # 20-30, Medellín',
            'num_cuenta_bancaria' => '0000036036',
        ]);

        $empleado = $this->crearEmpleado(self::CEDULA_DESHIPOTECA, 'Prueba — Deshipoteca', 6_000_000);

        $this->aprobarPrestamo($empleado, self::MODALIDAD_DESHIPOTECA, 40_000_000, 240, now());

        $this->resumen[] = [$empleado->cedula, 'Deshipoteca aprobada sin desembolsar + proveedor '.self::ID_PROVEEDOR_DESHIPOTECA, 'PP-036'];
    }

    private function sembrarSolicitudLista(): void
    {
        $empleado = $this->crearEmpleado(self::CEDULA_SOLICITUD_LISTA, 'Prueba — Solicitud Lista', 6_000_000);

        $this->radicarSolicitud($empleado, self::MODALIDAD_EDUCACION, 8_000_000, 36, now());

        $this->resumen[] = [$empleado->cedula, 'Solicitud radicada que cumple todas las condiciones', 'PP-032'];
    }

    private function sembrarVolumen(): void
    {
        Empleado::factory()->count(self::CANTIDAD_EMPLEADOS_VOLUMEN)->create();

        $this->resumen[] = ['(aleatorias)', self::CANTIDAD_EMPLEADOS_VOLUMEN.' empleados de volumen', 'PP-016, PP-057'];
    }

    private function crearEmpleado(string $cedula, string $nombreCompleto, float $salario, ?float $capacidadEndeudamiento = null): Empleado
    {
        return Empleado::factory()->create([
            'cedula' => $cedula,
            'nombre_completo' => $nombreCompleto,
            'salario' => $salario,
            'estado' => 'Activo',
            'capacidad_endeudamiento' => $capacidadEndeudamiento ?? $this->empleadoService->capacidadEndeudamientoSugerida($salario),
        ]);
    }

    private function radicarSolicitud(Empleado $empleado, string $modalidad, float $monto, int $plazoQuincenas, Carbon $fecha): SolicitudCredito
    {
        Carbon::setTestNow($fecha);

        return $this->solicitudCreditoService->crear([
            'cedula_empleado' => $empleado->cedula,
            'id_tipo' => $this->modalidad($modalidad)->id_tipo,
            'monto_solicitado' => $monto,
            'plazo_solicitado' => $plazoQuincenas,
            'fecha_solicitud' => $fecha->toDateString(),
        ], $this->funcionario);
    }

    /**
     * Radica y aprueba la solicitud "en" $fechaAutorizacion, de modo que el
     * préstamo y su primera cuota (desde 15 días después) queden en esa línea de tiempo.
     */
    private function aprobarPrestamo(Empleado $empleado, string $modalidad, float $monto, int $plazoQuincenas, Carbon $fechaAutorizacion): Prestamo
    {
        $solicitud = $this->radicarSolicitud($empleado, $modalidad, $monto, $plazoQuincenas, $fechaAutorizacion);

        try {
            $prestamo = $this->solicitudCreditoService->aprobar($solicitud, $this->administrador);

            // Los escenarios simulan préstamos antiguos, con cuotas ya en cobro (regla anterior al desembolso).
            $prestamo->update(['pendiente_desembolso' => false]);

            return $prestamo;
        } catch (RuntimeException $excepcion) {
            throw new RuntimeException("PlanPruebasSeeder: no se pudo aprobar la solicitud de {$empleado->nombre_completo}. {$excepcion->getMessage()}", previous: $excepcion);
        } finally {
            $this->restaurarReloj();
        }
    }

    /**
     * Los últimos $cantidad vencimientos de calendario (día 15 o fin de mes) anteriores a hoy, del más
     * antiguo al más reciente.
     *
     * @return list<Carbon>
     */
    private function ultimosVencimientosPasados(int $cantidad): array
    {
        $hoy = now()->startOfDay();
        $vencimientos = array_map(
            fn (string $fecha): Carbon => Carbon::parse($fecha),
            app(CalendarioQuincenalService::class)->vencimientosDesde($hoy->copy()->subDays(80)->toDateString(), 8),
        );

        return array_values(array_slice(array_filter($vencimientos, fn (Carbon $fecha): bool => $fecha->lt($hoy)), -$cantidad));
    }

    private function restaurarReloj(): void
    {
        Carbon::setTestNow($this->relojOriginal);
    }

    private function modalidad(string $nombre): TipoPrestamo
    {
        return TipoPrestamo::where('nombre', $nombre)->firstOrFail();
    }
}
