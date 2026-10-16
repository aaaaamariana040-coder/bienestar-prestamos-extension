<?php

namespace App\Services;

use App\Enums\EstadoPrestamo;
use App\Enums\TipoPago;
use App\Models\Empleado;
use App\Models\Pago;
use App\Models\Prestamo;
use App\Models\TipoPrestamo;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class HistorialPagosService
{
    public const QUINCENAS_POR_ANIO = 24;

    public const POR_PAGINA = 50;

    /**
     * Columnas de la Q. «campo» es la columna de la tabla empleados; null = aún no existe
     * en la base de datos y se muestra «—».
     *
     * @var list<array{titulo: string, campo: string|null}>
     */
    public const COLUMNAS = [
        ['titulo' => 'Cédula', 'campo' => 'cedula'],
        ['titulo' => 'Nombre', 'campo' => 'nombre_completo'],
        ['titulo' => 'Programa', 'campo' => 'dependencia'],
        ['titulo' => 'Cargo', 'campo' => 'cargo'],
        ['titulo' => 'Ingreso', 'campo' => 'fecha_vinculacion'],
        ['titulo' => 'Nacimiento', 'campo' => null],
        ['titulo' => 'Nivel', 'campo' => null],
        ['titulo' => 'Grado', 'campo' => null],
        ['titulo' => 'Asignación', 'campo' => 'salario'],
        ['titulo' => 'EPS', 'campo' => null],
        ['titulo' => 'Pensión', 'campo' => null],
        ['titulo' => 'Centro de Costos', 'campo' => null],
        ['titulo' => 'Sede', 'campo' => null],
        ['titulo' => 'Jornada', 'campo' => null],
        ['titulo' => 'Tipo Contrato', 'campo' => 'tipo_vinculacion'],
    ];

    private const MESES = [
        1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril', 5 => 'mayo', 6 => 'junio',
        7 => 'julio', 8 => 'agosto', 9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
    ];

    /**
     * Quincena 1 = 1 al 15 de enero, 2 = 16 al 31 de enero, … 24 = 16 al 31 de diciembre.
     *
     * @return array{anio: int, quincena: int}
     */
    public function quincenaActual(?Carbon $hoy = null): array
    {
        $hoy ??= Carbon::today();

        return ['anio' => $hoy->year, 'quincena' => $hoy->month * 2 - ($hoy->day <= 15 ? 1 : 0)];
    }

    public function cierreDeQuincena(int $anio, int $quincena): Carbon
    {
        $mes = intdiv($quincena + 1, 2);
        $fecha = Carbon::create($anio, $mes, 1);

        return ($quincena % 2 === 1 ? $fecha->day(15) : $fecha->endOfMonth())->startOfDay();
    }

    public function etiquetaQuincena(int $quincena): string
    {
        $mes = self::MESES[intdiv($quincena + 1, 2)];

        return $quincena % 2 === 1 ? "1 al 15 de {$mes}" : "16 al fin de {$mes}";
    }

    public function cedulaDigitos(?string $texto): string
    {
        return substr((string) preg_replace('/\D+/', '', (string) $texto), 0, 10);
    }

    /**
     * @return LengthAwarePaginator<int, Empleado>
     */
    public function paginaQ(string $cedula): LengthAwarePaginator
    {
        return $this->consultaQ($cedula)->paginate(self::POR_PAGINA)->withQueryString();
    }

    /**
     * @return Collection<int, Empleado>
     */
    public function todosQ(string $cedula): Collection
    {
        return $this->consultaQ($cedula)->get();
    }

    /**
     * Créditos vigentes (Vigente y En mora) por empleado, con las cuotas pagadas hasta el cierre
     * de la quincena. Una sola consulta de pagos para todos los empleados recibidos.
     *
     * @param  iterable<Empleado>  $empleados
     * @return array<string, list<object{modalidad: string, pagadas: int, total: int, cuota: string, estado: string}>>
     */
    public function lineasDeCredito(iterable $empleados, Carbon $cierre): array
    {
        $empleados = collect($empleados);
        $prestamos = $empleados->flatMap(fn (Empleado $empleado) => $empleado->prestamosActivos);
        $pagadas = $this->cuotasPagadasHasta($prestamos->pluck('id_prestamo')->all(), $cierre);

        return $empleados->mapWithKeys(fn (Empleado $empleado): array => [
            $empleado->cedula => $empleado->prestamosActivos
                ->sortBy('id_prestamo')
                ->map(fn (Prestamo $prestamo): object => $this->linea($prestamo, $pagadas))
                ->values()
                ->all(),
        ])->all();
    }

    /**
     * Todos los créditos del empleado (cualquier estado), para la consulta individual.
     *
     * @return list<object{modalidad: string, pagadas: int, total: int, cuota: string, estado: string}>
     */
    public function creditosDe(Empleado $empleado, Carbon $cierre): array
    {
        $prestamos = Prestamo::with('tipoPrestamo')
            ->where('cedula_empleado', $empleado->cedula)
            ->orderBy('id_prestamo')
            ->get();

        $pagadas = $this->cuotasPagadasHasta($prestamos->pluck('id_prestamo')->all(), $cierre);

        return $prestamos->map(fn (Prestamo $prestamo): object => $this->linea($prestamo, $pagadas))->all();
    }

    /**
     * @return Collection<int, TipoPrestamo>
     */
    public function modalidadesDe(Empleado $empleado): Collection
    {
        return TipoPrestamo::whereIn('id_tipo', Prestamo::where('cedula_empleado', $empleado->cedula)->select('id_tipo'))
            ->orderBy('nombre')
            ->get();
    }

    /**
     * Pagos del empleado en una modalidad, con fecha dentro del rango (inclusive).
     *
     * @return Collection<int, Pago>
     */
    public function pagosEnRango(Empleado $empleado, int $idTipo, string $desde, string $hasta): Collection
    {
        return Pago::with(['cuota', 'usuario'])
            ->whereIn('id_prestamo', Prestamo::where('cedula_empleado', $empleado->cedula)->where('id_tipo', $idTipo)->select('id_prestamo'))
            ->whereDate('fecha_pago', '>=', $desde)
            ->whereDate('fecha_pago', '<=', $hasta)
            ->orderBy('fecha_pago')
            ->orderBy('id_pago')
            ->get();
    }

    public function valorColumna(Empleado $empleado, ?string $campo): string
    {
        if ($campo === null) {
            return '—';
        }

        $valor = $empleado->{$campo};

        return match (true) {
            $valor === null || $valor === '' => '—',
            $campo === 'fecha_vinculacion' => $valor->format('Y-m-d'),
            $campo === 'salario' => number_format((float) $valor, 2),
            default => (string) $valor,
        };
    }

    private function consultaQ(string $cedula)
    {
        return Empleado::query()
            ->with('prestamosActivos.tipoPrestamo')
            ->when($cedula !== '', fn ($consulta) => $consulta->where('cedula', 'like', $cedula.'%'))
            ->orderBy('cedula');
    }

    /**
     * @param  list<int>  $idsPrestamo
     * @return array<int, int>
     */
    private function cuotasPagadasHasta(array $idsPrestamo, Carbon $cierre): array
    {
        if ($idsPrestamo === []) {
            return [];
        }

        return Pago::query()
            ->whereIn('id_prestamo', $idsPrestamo)
            ->where('tipo', TipoPago::Regular)
            ->whereNotNull('id_cuota')
            ->whereDate('fecha_pago', '<=', $cierre->toDateString())
            ->selectRaw('id_prestamo, count(*) as pagadas')
            ->groupBy('id_prestamo')
            ->pluck('pagadas', 'id_prestamo')
            ->map(fn ($pagadas): int => (int) $pagadas)
            ->all();
    }

    /**
     * @param  array<int, int>  $pagadas
     */
    private function linea(Prestamo $prestamo, array $pagadas): object
    {
        return (object) [
            'modalidad' => $prestamo->tipoPrestamo->nombre,
            'pagadas' => $pagadas[$prestamo->id_prestamo] ?? 0,
            'total' => (int) $prestamo->num_cuotas,
            'cuota' => number_format((float) $prestamo->cuota_periodica, 2),
            'estado' => $prestamo->estado instanceof EstadoPrestamo ? $prestamo->estado->value : (string) $prestamo->estado,
        ];
    }
}
