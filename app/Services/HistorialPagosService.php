<?php

namespace App\Services;

use App\Enums\EstadoPrestamo;
use App\Models\Cuota;
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
     * Créditos vigentes en la quincena por empleado (Vigente, En mora y los Cancelados cuyo último
     * pago es posterior al cierre: ese día aún estaban vigentes), con las cuotas pagadas hasta el
     * cierre. Dos consultas para todos los empleados recibidos, sin importar cuántos sean.
     *
     * @param  iterable<Empleado>  $empleados
     * @return array<string, list<object{modalidad: string, pagadas: int, total: int, cuota: string, estado: string}>>
     */
    public function lineasDeCredito(iterable $empleados, Carbon $cierre): array
    {
        $empleados = collect($empleados);
        $prestamos = $empleados->flatMap(fn (Empleado $empleado) => $empleado->prestamos);
        $ultimosPagos = $this->ultimosPagosDeCancelados($prestamos);
        $pagadas = $this->cuotasPagadasHasta($prestamos, $cierre, $ultimosPagos);

        return $empleados->mapWithKeys(fn (Empleado $empleado): array => [
            $empleado->cedula => $empleado->prestamos
                ->reject(fn (Prestamo $prestamo): bool => $this->canceladoAl($prestamo, $ultimosPagos, $cierre))
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

        $pagadas = $this->cuotasPagadasHasta($prestamos, $cierre);

        return $prestamos->map(fn (Prestamo $prestamo): object => $this->linea($prestamo, $pagadas))->all();
    }

    /**
     * Variante «hasta una fecha» de Prestamo::cuotasPagadas(): mismo criterio de cuota pagada
     * (Cuota::pagadas) y mismo tope al total (Prestamo::toparAlTotal), pero solo las que ya
     * tenían un pago con fecha hasta el cierre. Una sola consulta agrupada para todos los préstamos.
     *
     * Un préstamo Cancelado cuyo último pago es igual o anterior al cierre cuenta como pagado
     * por completo (total de total), igual que progreso_cuotas hoy. Si el cierre es anterior a
     * su último pago, se cuentan solo las cuotas pagadas hasta el cierre. No hay un campo de fecha
     * de cancelación: se usa la fecha del último pago registrado.
     *
     * @param  iterable<Prestamo>  $prestamos
     * @param  array<int, string|null>|null  $ultimosPagos  resultado de ultimosPagosDeCancelados(), si ya se calculó
     * @return array<int, int> cuotas pagadas por id de préstamo
     */
    public function cuotasPagadasHasta(iterable $prestamos, Carbon $cierre, ?array $ultimosPagos = null): array
    {
        $prestamos = collect($prestamos);

        if ($prestamos->isEmpty()) {
            return [];
        }

        $ultimosPagos ??= $this->ultimosPagosDeCancelados($prestamos);

        $conteo = Cuota::query()
            ->pagadasHasta($cierre)
            ->whereIn('id_prestamo', $prestamos->pluck('id_prestamo')->all())
            ->selectRaw('id_prestamo, count(*) as pagadas')
            ->groupBy('id_prestamo')
            ->pluck('pagadas', 'id_prestamo');

        return $prestamos->mapWithKeys(function (Prestamo $prestamo) use ($conteo, $ultimosPagos, $cierre): array {
            $total = (int) $prestamo->num_cuotas;

            return [$prestamo->id_prestamo => $this->canceladoAl($prestamo, $ultimosPagos, $cierre)
                ? $total
                : Prestamo::toparAlTotal((int) ($conteo[$prestamo->id_prestamo] ?? 0), $total)];
        })->all();
    }

    /**
     * Fecha del último pago de cada préstamo Cancelado (null si no tiene pagos). Una consulta;
     * ninguna si no hay préstamos cancelados.
     *
     * @param  iterable<Prestamo>  $prestamos
     * @return array<int, string|null>
     */
    public function ultimosPagosDeCancelados(iterable $prestamos): array
    {
        $ids = collect($prestamos)
            ->filter(fn (Prestamo $prestamo): bool => $prestamo->estado === EstadoPrestamo::Cancelado)
            ->pluck('id_prestamo')
            ->all();

        if ($ids === []) {
            return [];
        }

        $ultimos = Pago::query()
            ->whereIn('id_prestamo', $ids)
            ->selectRaw('id_prestamo, max(fecha_pago) as ultimo')
            ->groupBy('id_prestamo')
            ->pluck('ultimo', 'id_prestamo')
            ->all();

        return collect($ids)->mapWithKeys(fn (int $id): array => [$id => $ultimos[$id] ?? null])->all();
    }

    /**
     * ¿Ya estaba cancelado el préstamo al cierre? Cancelado y sin pagos posteriores al cierre.
     *
     * @param  array<int, string|null>  $ultimosPagos
     */
    private function canceladoAl(Prestamo $prestamo, array $ultimosPagos, Carbon $cierre): bool
    {
        if ($prestamo->estado !== EstadoPrestamo::Cancelado) {
            return false;
        }

        $ultimo = $ultimosPagos[$prestamo->id_prestamo] ?? null;

        return $ultimo === null || $cierre->greaterThanOrEqualTo(Carbon::parse($ultimo)->startOfDay());
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
        return Pago::with(['cuota', 'usuario', 'prestamo'])
            ->whereIn('id_prestamo', Prestamo::where('cedula_empleado', $empleado->cedula)->where('id_tipo', $idTipo)->select('id_prestamo'))
            ->whereDate('fecha_pago', '>=', $desde)
            ->whereDate('fecha_pago', '<=', $hasta)
            ->orderBy('fecha_pago')
            ->orderBy('id_pago')
            ->get();
    }

    /**
     * Columnas de la Q que se muestran en pantalla: se ocultan las que no tienen dato en ningún
     * empleado (las que no existen en la base y las vacías para todos). Se mira toda la Q, no la
     * página, para que las columnas no cambien al paginar ni al buscar. Una sola consulta.
     * Cédula y nombre siempre se muestran. La impresión sigue usando COLUMNAS completas.
     *
     * @return array{visibles: list<array{titulo: string, campo: string|null}>, ocultas: list<string>}
     */
    public function columnasConDatos(): array
    {
        $campos = collect(self::COLUMNAS)
            ->pluck('campo')
            ->filter(fn (?string $campo): bool => $campo !== null && ! in_array($campo, ['cedula', 'nombre_completo'], true))
            ->values();

        $resumen = Empleado::query()
            ->toBase()
            ->selectRaw('count(*) as total')
            ->selectRaw($campos->map(fn (string $campo): string => "max(case when {$campo} is not null and {$campo} <> '' then 1 else 0 end) as {$campo}")->implode(', '))
            ->first();

        $visibles = [];
        $ocultas = [];

        foreach (self::COLUMNAS as $columna) {
            $campo = $columna['campo'];
            $siempre = $campo !== null && in_array($campo, ['cedula', 'nombre_completo'], true);
            $conDatos = $campo !== null && (int) ($resumen->{$campo} ?? 0) === 1;

            if ($siempre || $conDatos || (int) $resumen->total === 0) {
                $visibles[] = $columna;
            } else {
                $ocultas[] = $columna['titulo'];
            }
        }

        return ['visibles' => $visibles, 'ocultas' => $ocultas];
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
            ->with('prestamos.tipoPrestamo')
            ->when($cedula !== '', fn ($consulta) => $consulta->where('cedula', 'like', $cedula.'%'))
            ->orderBy('cedula');
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
