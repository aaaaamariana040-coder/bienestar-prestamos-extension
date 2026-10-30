<?php

namespace Database\Seeders;

use App\Enums\RolUsuario;
use App\Enums\TipoVinculacion;
use App\Models\Empleado;
use App\Models\Prestamo;
use App\Models\PresupuestoAnual;
use App\Models\SmmlvAnual;
use App\Models\SolicitudCredito;
use App\Models\TasaInt;
use App\Models\TasaVehiculo;
use App\Models\TipoDocumento;
use App\Models\TipoPrestamo;
use App\Models\Usuario;
use App\Services\DesembolsoService;
use App\Services\DocumentoSolicitudService;
use App\Services\EmpleadoService;
use App\Services\PagoService;
use App\Services\SolicitudCreditoService;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Datos de demostración con personas ficticias (cédulas 9000000001 a
 * 9000000020). No está en DatabaseSeeder: se corre a propósito con
 *
 *     php artisan db:seed --class=DemoSeeder
 *
 * y se quitan con `php artisan db:seed --class=DemoLimpiezaSeeder`.
 *
 * Es idempotente (un escenario cuyo empleado ya existe se salta) y no modifica
 * datos existentes: solo agrega los parámetros del año que falten (SMMLV,
 * tasas, presupuesto). Los préstamos se crean con el flujo real de los
 * servicios, así que sus tablas de cuotas son las que generaría la aplicación.
 * Guion de la demostración: docs/guion-demo.md.
 */
class DemoSeeder extends Seeder
{
    public const CEDULA_PRIMERA = 9000000001;

    public const CEDULA_ULTIMA = 9000000020;

    public const CEDULA_ANTIGUO_SIN_PRESTAMOS = '9000000001';

    public const CEDULA_CALAMIDAD_VIGENTE = '9000000002';

    public const CEDULA_CAPACIDAD_AGOTADA = '9000000003';

    public const CEDULA_MENOS_DE_UN_ANIO = '9000000004';

    public const CEDULA_RETIRADO = '9000000005';

    public const CEDULA_SALARIO_ALTO = '9000000006';

    public const CEDULA_VEHICULO_PAGADO = '9000000007';

    public const CEDULA_SOLICITUD_PENDIENTE = '9000000008';

    public const IPC = 5.0;

    private const SMMLV_POR_DEFECTO = 1_750_905;

    private const EDUCACION = 'Educación';

    private const CALAMIDAD = 'Calamidad doméstica y necesidades especiales';

    private const VEHICULO = 'Vehículo';

    private Usuario $administrador;

    private int $anio;

    private float $smmlv;

    /**
     * @var list<array{0: string, 1: string, 2: string}>
     */
    private array $resumen = [];

    public function __construct(
        private readonly SolicitudCreditoService $solicitudCreditoService,
        private readonly DesembolsoService $desembolsoService,
        private readonly PagoService $pagoService,
        private readonly EmpleadoService $empleadoService,
        private readonly DocumentoSolicitudService $documentoSolicitudService,
    ) {}

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('DemoSeeder no se ejecuta en producción.');

            return;
        }

        $administrador = Usuario::where('rol', RolUsuario::Administrador)->orderBy('cedula')->first();

        if ($administrador === null || TipoPrestamo::where('nombre', self::VEHICULO)->doesntExist()) {
            $this->command?->error('Falta el seeder base: corre primero php artisan app:preparar-instalacion (modalidades y administrador principal).');

            return;
        }

        $this->administrador = $administrador;
        $this->anio = now()->year;

        $this->asegurarParametros();

        $escenarios = [
            [self::CEDULA_ANTIGUO_SIN_PRESTAMOS, 'Antiguo, salario medio, sin préstamos', fn () => $this->antiguoSinPrestamos()],
            [self::CEDULA_CALAMIDAD_VIGENTE, 'Antiguo con préstamo vigente de calamidad', fn () => $this->calamidadVigente()],
            [self::CEDULA_CAPACIDAD_AGOTADA, 'Capacidad de endeudamiento agotada', fn () => $this->capacidadAgotada()],
            [self::CEDULA_MENOS_DE_UN_ANIO, 'Menos de un año de servicio', fn () => $this->menosDeUnAnio()],
            [self::CEDULA_RETIRADO, 'Empleado retirado', fn () => $this->retirado()],
            [self::CEDULA_SALARIO_ALTO, 'Salario alto (más de 8 SMMLV)', fn () => $this->salarioAlto()],
            [self::CEDULA_VEHICULO_PAGADO, 'Vehículo ya pagado (segunda solicitud)', fn () => $this->vehiculoPagado()],
            [self::CEDULA_SOLICITUD_PENDIENTE, 'Solicitud pendiente con un documento', fn () => $this->solicitudPendiente()],
        ];

        foreach ($escenarios as [$cedula, $descripcion, $crear]) {
            if (Empleado::whereKey($cedula)->exists()) {
                $this->resumen[] = [$cedula, $descripcion, 'ya existía: no se tocó'];

                continue;
            }

            DB::transaction($crear);
            $this->resumen[] = [$cedula, $descripcion, 'creado'];
        }

        $this->command?->table(['Cédula', 'Escenario', 'Resultado'], $this->resumen);
    }

    /**
     * Agrega solo lo que falte para el año actual; lo que ya existe no se modifica.
     */
    private function asegurarParametros(): void
    {
        $this->smmlv = (float) SmmlvAnual::firstOrCreate(
            ['anio' => $this->anio],
            ['valor' => self::SMMLV_POR_DEFECTO, 'fuente_normativa' => 'Datos de demostración'],
        )->valor;

        foreach (TipoPrestamo::all() as $modalidad) {
            TasaInt::firstOrCreate(
                ['id_tipo' => $modalidad->id_tipo, 'anio' => $this->anio],
                ['tasa_anual' => self::IPC, 'fuente_normativa' => 'Datos de demostración'],
            );

            PresupuestoAnual::firstOrCreate(
                ['id_tipo' => $modalidad->id_tipo, 'anio' => $this->anio],
                ['monto_asignado' => $modalidad->esVivienda() ? 600_000_000 : 300_000_000, 'monto_comprometido' => 0],
            );
        }

        if (TasaVehiculo::count() === 0) {
            $this->call(TasaVehiculoSeeder::class);
        }

        TipoDocumento::firstOrCreate(['nombre' => 'Cédula'], ['activo' => true]);
    }

    private function antiguoSinPrestamos(): void
    {
        $this->crearEmpleado(self::CEDULA_ANTIGUO_SIN_PRESTAMOS, 'Ana Demo Antigua', 3, anios: 8);
    }

    private function calamidadVigente(): void
    {
        $empleado = $this->crearEmpleado(self::CEDULA_CALAMIDAD_VIGENTE, 'Bruno Demo Calamidad', 3, anios: 7);

        // A 12 quincenas la cuota es alta: deja una capacidad disponible que limita las modalidades grandes.
        $this->aprobar($empleado, self::CALAMIDAD, $this->pesos(4.9), 12);
    }

    /**
     * Dos préstamos vigentes y la capacidad del empleado ajustada a lo que ya
     * tiene comprometido, de modo que no le queda disponible.
     */
    private function capacidadAgotada(): void
    {
        $empleado = $this->crearEmpleado(self::CEDULA_CAPACIDAD_AGOTADA, 'Carla Demo Agotada', 2, anios: 6);

        $this->aprobar($empleado, self::EDUCACION, $this->pesos(4), 24);
        $this->aprobar($empleado, self::CALAMIDAD, $this->pesos(3), 24);

        $empleado->update(['capacidad_endeudamiento' => $this->empleadoService->capacidadEndeudamiento($empleado)['comprometida']]);
    }

    private function menosDeUnAnio(): void
    {
        $this->crearEmpleado(self::CEDULA_MENOS_DE_UN_ANIO, 'Diego Demo Nuevo', 3, meses: 6);
    }

    private function retirado(): void
    {
        $this->crearEmpleado(self::CEDULA_RETIRADO, 'Elena Demo Retirada', 3, anios: 9, estado: 'Retirado');
    }

    private function salarioAlto(): void
    {
        $this->crearEmpleado(self::CEDULA_SALARIO_ALTO, 'Fabio Demo Salario Alto', 10, anios: 12);
    }

    /**
     * Un préstamo de vehículo desembolsado y cancelado: el siguiente que pida
     * será su segunda solicitud, con más puntos sobre el IPC.
     */
    private function vehiculoPagado(): void
    {
        $empleado = $this->crearEmpleado(self::CEDULA_VEHICULO_PAGADO, 'Gloria Demo Vehículo', 4, anios: 10);

        $prestamo = $this->aprobar($empleado, self::VEHICULO, $this->pesos(8), 72);

        $this->desembolsoService->registrar($prestamo, ['numero_tramo' => 1, 'fecha' => now()->toDateString()], $this->administrador);
        $this->pagoService->registrarCancelacion($prestamo, now()->toDateString(), $this->administrador);
    }

    private function solicitudPendiente(): void
    {
        $empleado = $this->crearEmpleado(self::CEDULA_SOLICITUD_PENDIENTE, 'Hugo Demo Solicitud', 3, anios: 5);

        $solicitud = $this->radicar($empleado, self::EDUCACION, $this->pesos(2), 24);

        $this->adjuntarDocumentoDeEjemplo($solicitud);
    }

    /**
     * @param  float  $salariosMinimos  salario como múltiplo del SMMLV, redondeado a miles
     */
    private function crearEmpleado(string $cedula, string $nombreCompleto, float $salariosMinimos, int $anios = 0, int $meses = 0, string $estado = 'Activo'): Empleado
    {
        $salario = $this->pesos($salariosMinimos);

        return Empleado::create([
            'cedula' => $cedula,
            'nombre_completo' => $nombreCompleto,
            'tipo_vinculacion' => TipoVinculacion::Vinculado,
            'cargo' => 'Cargo de demostración',
            'dependencia' => 'Dependencia de demostración',
            'salario' => $salario,
            'fecha_vinculacion' => now()->subYears($anios)->subMonths($meses)->toDateString(),
            'estado' => $estado,
            'capacidad_endeudamiento' => $this->empleadoService->capacidadEndeudamientoSugerida($salario),
        ]);
    }

    private function pesos(float $salariosMinimos): float
    {
        return round($salariosMinimos * $this->smmlv, -3);
    }

    private function radicar(Empleado $empleado, string $modalidad, float $monto, int $plazoQuincenas): SolicitudCredito
    {
        return $this->solicitudCreditoService->crear([
            'cedula_empleado' => $empleado->cedula,
            'id_tipo' => TipoPrestamo::where('nombre', $modalidad)->firstOrFail()->id_tipo,
            'monto_solicitado' => $monto,
            'plazo_solicitado' => $plazoQuincenas,
            'fecha_solicitud' => now()->toDateString(),
        ], $this->administrador);
    }

    private function aprobar(Empleado $empleado, string $modalidad, float $monto, int $plazoQuincenas): Prestamo
    {
        $solicitud = $this->radicar($empleado, $modalidad, $monto, $plazoQuincenas);

        try {
            return $this->solicitudCreditoService->aprobar($solicitud, $this->administrador);
        } catch (RuntimeException $excepcion) {
            throw new RuntimeException("DemoSeeder: no se pudo aprobar la solicitud de {$empleado->nombre_completo}. {$excepcion->getMessage()}", previous: $excepcion);
        }
    }

    private function adjuntarDocumentoDeEjemplo(SolicitudCredito $solicitud): void
    {
        $ruta = tempnam(sys_get_temp_dir(), 'demo');
        file_put_contents($ruta, "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 100]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");

        try {
            $this->documentoSolicitudService->subir(
                $solicitud,
                TipoDocumento::where('nombre', 'Cédula')->firstOrFail(),
                [new UploadedFile($ruta, 'cedula-demo.pdf', 'application/pdf', null, true)],
                $this->administrador,
            );
        } finally {
            @unlink($ruta);
        }
    }
}
