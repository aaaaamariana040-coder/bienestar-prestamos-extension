<?php

namespace Database\Seeders;

use App\Enums\EstadoSolicitud;
use App\Enums\TipoVinculacion;
use App\Models\Empleado;
use App\Models\Prestamo;
use App\Models\PresupuestoAnual;
use App\Models\PrimaSeguro;
use App\Models\SmmlvAnual;
use App\Models\SolicitudCredito;
use App\Models\TasaDtf;
use App\Models\TasaInt;
use App\Models\TipoPrestamo;
use App\Models\Usuario;
use App\Services\CargaPrestamosVigentesService;
use App\Services\DtfService;
use App\Services\EmpleadoService;
use App\Services\PresupuestoService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Conjunto de datos de práctica, COMPLETAMENTE FICTICIOS, para instalar el aplicativo en otro
 * equipo y que una funcionaria de Bienestar pruebe el flujo completo (docs/guia-practica.md).
 *
 *     php artisan migrate:fresh
 *     php artisan db:seed --class=PracticaSeeder
 *
 * Solo corre con PRACTICA_HABILITADA=true y nunca en producción. No pisa lo que ya exista
 * (usuarios, parámetros): agrega solo lo que falte. Los préstamos vigentes salen de
 * docs/practica/prestamos-practica.csv, cargado con el mismo servicio que la pantalla
 * «Cargar préstamos vigentes».
 */
class PracticaSeeder extends Seeder
{
    public const ANIO = 2026;

    public const SMMLV = 1_750_905;

    /** IPC de práctica por año, en puntos porcentuales. Mayor al 5 % para ver el tope de vivienda. */
    public const IPC = [2025 => 5.20, 2026 => 5.10];

    public const PRESUPUESTO_TOTAL = 3_000_000_000;

    public const PORCENTAJE_VIVIENDA = 0.70;

    public const DTF_NOMINAL = 9.81;

    public const DTF_VIGENCIA = '2026-07-01';

    public const FUENTE = 'Dato de práctica (ficticio)';

    public const CEDULA_ADMINISTRADOR = '1000000000';

    public const CEDULA_FUNCIONARIO = '1000000001';

    public const CLAVE_ADMINISTRADOR = 'Practica#Admin2026';

    public const CLAVE_FUNCIONARIO = 'Practica#Func2026';

    public const CEDULA_PRIMERA = 1000000101;

    public const CEDULA_ULTIMA = 1000000120;

    public const CEDULA_APROBABLE = '1000000105';

    public const CEDULA_SIN_CAPACIDAD = '1000000108';

    public const CEDULA_DOCENTE_OCASIONAL = '1000000116';

    public const CEDULA_SALARIO_MINIMO = '1000000120';

    public const ARCHIVO_PRESTAMOS = 'docs/practica/prestamos-practica.csv';

    private Usuario $administrador;

    public static function verificarHabilitada(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Los datos de práctica no se cargan en producción (APP_ENV=production).');
        }

        if (config('practica.habilitada') !== true) {
            throw new RuntimeException('Los datos de práctica están deshabilitados: ponga PRACTICA_HABILITADA=true en el .env del equipo de práctica.');
        }
    }

    public function run(): void
    {
        self::verificarHabilitada();

        $this->call([
            TasaVehiculoSeeder::class,
            TipoPrestamoSeeder::class,
            TipoDocumentoSeeder::class,
            SistemaUsuarioSeeder::class,
        ]);

        $this->administrador = $this->crearUsuarios();

        $this->parametrosDeReferencia();
        $this->crearEmpleados();
        $this->cargarPrestamosVigentes();
        $this->radicarSolicitudes();
    }

    private function crearUsuarios(): Usuario
    {
        $administrador = Usuario::firstOrCreate(
            ['cedula' => self::CEDULA_ADMINISTRADOR],
            ['nombre' => 'Administrador', 'apellido' => 'Práctica', 'contrasena_hash' => bcrypt(self::CLAVE_ADMINISTRADOR), 'rol' => 'ADMINISTRADOR', 'es_principal' => true, 'habilitado' => true],
        );

        Usuario::firstOrCreate(
            ['cedula' => self::CEDULA_FUNCIONARIO],
            ['nombre' => 'Funcionaria', 'apellido' => 'Práctica', 'contrasena_hash' => bcrypt(self::CLAVE_FUNCIONARIO), 'rol' => 'FUNCIONARIO_BIENESTAR', 'habilitado' => true],
        );

        return $administrador;
    }

    private function parametrosDeReferencia(): void
    {
        SmmlvAnual::firstOrCreate(['anio' => self::ANIO], ['valor' => self::SMMLV, 'fuente_normativa' => self::FUENTE]);

        foreach (TipoPrestamo::all() as $modalidad) {
            foreach (self::IPC as $anio => $ipc) {
                $tasa = TasaInt::firstOrCreate(['id_tipo' => $modalidad->id_tipo, 'anio' => $anio], ['tasa_anual' => $ipc, 'fuente_normativa' => self::FUENTE]);

                if ($tasa->wasRecentlyCreated) {
                    // Registrada a comienzos del año, para que los préstamos del año no den aviso de «anterior al registro».
                    $tasa->forceFill(['created_at' => Carbon::create($anio, 1, 2, 8), 'updated_at' => Carbon::create($anio, 1, 2, 8)])->save();
                }
            }
        }

        $this->presupuesto();

        foreach ([now()->year - 1, now()->year] as $anio) {
            PrimaSeguro::firstOrCreate(['concepto' => 'vida_deudores', 'anio' => $anio], ['tasa' => 0.0003231]);
            PrimaSeguro::firstOrCreate(['concepto' => 'incendio_terremoto', 'anio' => $anio], ['tasa' => 0]);
        }

        if (TasaDtf::whereDate('fecha_vigencia', self::DTF_VIGENCIA)->doesntExist()) {
            app(DtfService::class)->crear(['fecha_vigencia' => self::DTF_VIGENCIA, 'dtf_nominal' => self::DTF_NOMINAL, 'fuente_normativa' => self::FUENTE], $this->administrador);
        }
    }

    /**
     * Vivienda 70 % (repartido con la sugerencia del aplicativo) y 30 % para las demás modalidades en partes iguales.
     */
    private function presupuesto(): void
    {
        $vivienda = self::PRESUPUESTO_TOTAL * self::PORCENTAJE_VIVIENDA;
        $reparto = app(PresupuestoService::class)->sugerenciaVivienda($vivienda);
        $otras = TipoPrestamo::where('nombre', 'not like', 'Vivienda%')->get();

        foreach (TipoPrestamo::all() as $modalidad) {
            $monto = $modalidad->esVivienda() ? $reparto[$modalidad->nombre] : round(self::PRESUPUESTO_TOTAL * (1 - self::PORCENTAJE_VIVIENDA) / $otras->count(), 2);

            PresupuestoAnual::firstOrCreate(['id_tipo' => $modalidad->id_tipo, 'anio' => self::ANIO], ['monto_asignado' => $monto, 'monto_comprometido' => 0]);
        }
    }

    /**
     * @return list<array{0: string, 1: string, 2: TipoVinculacion, 3: bool, 4: int, 5: string, 6: string}>
     */
    private function empleados(): array
    {
        $v = TipoVinculacion::Vinculado;
        $d = TipoVinculacion::Docente;
        $o = TipoVinculacion::DocenteOcasional;
        $j = TipoVinculacion::Jubilado;

        return [
            ['1000000101', 'Sandra Prueba Alfa', $v, true, 6_800_000, '2011-03-01', 'Profesional universitaria'],
            ['1000000102', 'Jorge Prueba Beta', $v, true, 5_200_000, '2014-08-18', 'Técnico administrativo'],
            ['1000000103', 'Marcela Prueba Gama', $v, false, 4_100_000, '2019-02-04', 'Auxiliar administrativa'],
            ['1000000104', 'Andrés Prueba Delta', $v, true, 7_900_000, '2009-06-15', 'Profesional especializado'],
            ['1000000105', 'Liliana Prueba Épsilon', $v, false, 3_600_000, '2021-01-11', 'Secretaria ejecutiva'],
            ['1000000106', 'Camilo Prueba Zeta', $v, true, 9_300_000, '2008-09-01', 'Jefe de oficina'],
            ['1000000107', 'Paola Prueba Eta', $v, true, 4_700_000, '2016-05-23', 'Técnica operativa'],
            ['1000000108', 'Rodrigo Prueba Theta', $v, false, 2_950_000, '2022-07-04', 'Auxiliar de servicios'],
            ['1000000109', 'Natalia Prueba Iota', $v, false, 3_300_000, '2026-03-02', 'Auxiliar administrativa'],
            ['1000000110', 'Héctor Prueba Kappa', $d, true, 8_400_000, '2010-02-01', 'Docente de planta'],
            ['1000000111', 'Isabel Prueba Lambda', $d, true, 7_100_000, '2013-07-15', 'Docente de planta'],
            ['1000000112', 'Mauricio Prueba Mu', $d, false, 5_600_000, '2018-08-06', 'Docente'],
            ['1000000113', 'Viviana Prueba Nu', $d, true, 12_500_000, '2005-01-17', 'Docente titular'],
            ['1000000114', 'Esteban Prueba Xi', $d, false, 4_900_000, '2020-01-20', 'Docente'],
            ['1000000115', 'Daniela Prueba Ómicron', $d, true, 6_300_000, '2015-03-09', 'Docente de planta'],
            ['1000000116', 'Fabián Prueba Pi', $o, false, 3_800_000, '2023-02-06', 'Docente ocasional'],
            ['1000000117', 'Gloria Prueba Rho', $o, false, 3_200_000, '2024-02-05', 'Docente ocasional'],
            ['1000000118', 'Álvaro Prueba Sigma', $j, true, 3_900_000, '2002-04-01', 'Jubilado'],
            ['1000000119', 'Teresa Prueba Tau', $j, true, 4_500_000, '1999-09-01', 'Jubilada'],
            ['1000000120', 'Julián Prueba Ípsilon', $v, false, self::SMMLV, '2023-05-02', 'Auxiliar de servicios'],
        ];
    }

    private function crearEmpleados(): void
    {
        $servicio = app(EmpleadoService::class);

        foreach ($this->empleados() as [$cedula, $nombre, $tipo, $deCarrera, $salario, $vinculacion, $cargo]) {
            Empleado::firstOrCreate(['cedula' => $cedula], [
                'nombre_completo' => $nombre,
                'tipo_vinculacion' => $tipo,
                'de_carrera' => $deCarrera,
                'cargo' => $cargo,
                'dependencia' => 'Dependencia de práctica',
                'salario' => $salario,
                'fecha_vinculacion' => $vinculacion,
                'estado' => 'Activo',
                'capacidad_endeudamiento' => $servicio->capacidadEndeudamientoSugerida($salario),
            ]);
        }
    }

    private function cargarPrestamosVigentes(): void
    {
        if (Prestamo::where('origen', Prestamo::ORIGEN_CARGA_INICIAL)->exists()) {
            return;
        }

        $contenido = file_get_contents(base_path(self::ARCHIVO_PRESTAMOS));

        if ($contenido === false) {
            throw new RuntimeException('No se encontró '.self::ARCHIVO_PRESTAMOS.'.');
        }

        app(CargaPrestamosVigentesService::class)->cargar($contenido, basename(self::ARCHIVO_PRESTAMOS), $this->administrador);
    }

    /**
     * Cuatro solicitudes radicadas. La del salario mínimo y la del docente ocasional se guardan
     * directamente: el formulario las rechazaría al registrarlas, pero así se ve cómo las
     * trata el aplicativo cuando ya existen (no se pueden aprobar).
     */
    private function radicarSolicitudes(): void
    {
        $educacion = TipoPrestamo::where('nombre', 'Educación')->firstOrFail()->id_tipo;
        $compra = TipoPrestamo::where('nombre', 'Vivienda — compra de inmueble')->firstOrFail()->id_tipo;
        $fecha = min(max(now()->toDateString(), self::ANIO.'-01-15'), self::ANIO.'-12-15');

        $solicitudes = [
            [self::CEDULA_APROBABLE, $educacion, 3_000_000, 24],
            [self::CEDULA_SIN_CAPACIDAD, $compra, 150_000_000, 120],
            [self::CEDULA_SALARIO_MINIMO, $educacion, 2_000_000, 24],
            [self::CEDULA_DOCENTE_OCASIONAL, $educacion, 3_000_000, 24],
        ];

        foreach ($solicitudes as [$cedula, $idTipo, $monto, $plazo]) {
            SolicitudCredito::firstOrCreate(
                ['cedula_empleado' => $cedula, 'id_tipo' => $idTipo, 'estado' => EstadoSolicitud::Radicada],
                ['monto_solicitado' => $monto, 'plazo_solicitado' => $plazo, 'fecha_solicitud' => $fecha],
            );
        }
    }
}
