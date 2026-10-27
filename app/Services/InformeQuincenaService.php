<?php

namespace App\Services;

use App\Enums\EstadoCuota;
use App\Models\Cuota;
use App\Models\ProcesoQuincena;
use App\Models\Usuario;
use Illuminate\Support\Collection;

/**
 * Informe de quincena por cuota (la «Q» que hoy manda Informática), armado con
 * lo que calcula QuincenaService. Una fila por cuota incluida en el proceso.
 *
 * Se entrega como CSV UTF-8 con BOM separado por punto y coma, porque la
 * extensión zip de PHP (que exige phpoffice/phpspreadsheet) no está activa en
 * este entorno; ver docs/reglas-negocio.md. Solo lee: no cambia cuotas,
 * estados ni cierres.
 */
class InformeQuincenaService
{
    public const DESCONTADA = 'Descontada';

    public const EXCLUIDA = 'Excluida';

    public const PENDIENTE = 'Pendiente';

    public const COLUMNAS = [
        'Cédula', 'Nombre', 'Tipo de vinculación', 'Modalidad', 'N.º de préstamo', 'Monto inicial', 'Plazo total (quincenas)',
        'Cuota n.º', 'Quincenas pagadas', 'Saldo inicial', 'Valor de la cuota', 'Capital', 'Intereses', 'Nuevo saldo',
        'Estado', 'Motivo de exclusión',
    ];

    public function __construct(
        private readonly QuincenaService $quincenaService,
        private readonly CalendarioQuincenalService $calendario,
        private readonly AuditoriaService $auditoriaService,
    ) {
        //
    }

    /**
     * Informe de la vista previa: las cuotas que entrarían en la quincena; las
     * excluidas son las que se marcaron en ese momento y las demás quedan
     * Pendiente (todavía no se descuentan).
     *
     * @param  array<int, string|null>  $exclusiones  id_cuota => motivo
     * @return array<string, mixed>
     */
    public function dePrevia(int $anio, int $quincena, array $exclusiones, Usuario $actor): array
    {
        $vista = $this->quincenaService->procesar($anio, $quincena);
        $items = [];

        foreach ($vista['cuotas'] as $fila) {
            $excluida = array_key_exists($fila['id_cuota'], $exclusiones);
            $items[] = [
                'id_cuota' => $fila['id_cuota'],
                'estado' => $excluida ? self::EXCLUIDA : self::PENDIENTE,
                'motivo' => $excluida ? (string) $exclusiones[$fila['id_cuota']] : '',
            ];
        }

        return $this->construir($anio, $quincena, 'vista previa', $items, $actor);
    }

    /**
     * Informe de una quincena confirmada, con lo que quedó guardado: las
     * pagadas son Descontada y las excluidas a mano, con su motivo. En
     * quincenas confirmadas antes de guardar el motivo, queda vacío.
     *
     * @return array<string, mixed>
     */
    public function deProceso(ProcesoQuincena $proceso, Usuario $actor): array
    {
        $resumen = $proceso->resumen ?? [];
        $items = [];

        foreach ($resumen['cuotas'] ?? [] as $fila) {
            $items[] = ['id_cuota' => $fila['id_cuota'], 'estado' => self::DESCONTADA, 'motivo' => ''];
        }

        foreach ($resumen['excluidas_manuales'] ?? [] as $fila) {
            $items[] = ['id_cuota' => $fila['id_cuota'], 'estado' => self::EXCLUIDA, 'motivo' => (string) ($fila['motivo'] ?? '')];
        }

        return $this->construir($proceso->anio, $proceso->numero_quincena, 'confirmada', $items, $actor);
    }

    /**
     * Deja en Auditoría la descarga, con la quincena y el tipo.
     *
     * @param  array<string, mixed>  $informe
     */
    public function auditarDescarga(Usuario $actor, array $informe): void
    {
        $this->auditoriaService->registrar(
            $actor,
            'Descarga de informe de quincena',
            "Quincena {$informe['quincena']} de {$informe['anio']} ({$informe['tipo']}): ".count($informe['filas']).' cuotas, '
                .$informe['totales']['excluidas']['cuotas'].' excluidas',
        );
    }

    /**
     * @param  array<string, mixed>  $informe
     */
    public function nombreArchivo(array $informe): string
    {
        $sufijo = $informe['tipo'] === 'vista previa' ? '-vista-previa' : '';

        return "informe-quincena-{$informe['anio']}-Q{$informe['quincena']}-{$informe['vencimiento']}{$sufijo}.csv";
    }

    /**
     * CSV UTF-8 con BOM, separado por punto y coma, con coma decimal (Excel en
     * español) y fechas AAAA-MM-DD.
     *
     * @param  array<string, mixed>  $informe
     */
    public function csv(array $informe): string
    {
        $flujo = fopen('php://temp', 'r+');
        fwrite($flujo, "\xEF\xBB\xBF");

        $escribir = function (array $campos) use ($flujo): void {
            fputcsv($flujo, array_map(fn ($valor) => $this->celda($valor), $campos), ';', '"', '');
        };

        $escribir(['Informe de quincena']);
        $escribir(['Quincena', "Q{$informe['quincena']} de {$informe['anio']} ({$informe['tipo']})"]);
        $escribir(['Fecha de vencimiento de la quincena', $informe['vencimiento']]);
        $escribir(['Generado', $informe['generado']]);
        $escribir(['Usuario', $informe['usuario']]);
        $escribir([]);

        if ($informe['filas'] === []) {
            $escribir(['La quincena no tiene cuotas.']);
            $escribir([]);
        }

        $escribir(self::COLUMNAS);

        foreach ($informe['filas'] as $fila) {
            $escribir($fila);
        }

        $totales = $informe['totales'];
        $escribir([]);
        $escribir(['Totales', 'Cuotas', 'Valor de la cuota', 'Capital', 'Intereses']);
        $escribir(['A descontar (sin excluidas)', $totales['a_descontar']['cuotas'], $totales['a_descontar']['valor'], $totales['a_descontar']['capital'], $totales['a_descontar']['intereses']]);
        $escribir(['Excluidas', $totales['excluidas']['cuotas'], $totales['excluidas']['valor'], $totales['excluidas']['capital'], $totales['excluidas']['intereses']]);
        $escribir([]);

        $escribir(['Resumen por modalidad']);
        $escribir(['Modalidad', 'Cuotas a descontar', 'Valor de la cuota', 'Capital', 'Intereses', 'Cuotas excluidas', 'Valor excluido']);

        foreach ($informe['resumen'] as $modalidad) {
            $escribir([$modalidad['modalidad'], $modalidad['cuotas'], $modalidad['valor'], $modalidad['capital'], $modalidad['intereses'], $modalidad['excluidas'], $modalidad['valor_excluido']]);
        }

        $escribir(['Total', $totales['a_descontar']['cuotas'], $totales['a_descontar']['valor'], $totales['a_descontar']['capital'], $totales['a_descontar']['intereses'], $totales['excluidas']['cuotas'], $totales['excluidas']['valor']]);

        rewind($flujo);
        $contenido = (string) stream_get_contents($flujo);
        fclose($flujo);

        return $contenido;
    }

    /**
     * @param  list<array{id_cuota: int, estado: string, motivo: string}>  $items
     * @return array<string, mixed>
     */
    private function construir(int $anio, int $quincena, string $tipo, array $items, Usuario $actor): array
    {
        $periodo = $this->calendario->quincena($anio, $quincena);
        $porId = collect($items)->keyBy('id_cuota');

        $cuotas = Cuota::query()
            ->with(['prestamo.empleado', 'prestamo.tipoPrestamo'])
            ->whereIn('id_cuota', $porId->keys()->all())
            ->whereHas('prestamo', fn ($consulta) => $consulta->where('pendiente_desembolso', false))
            ->get()
            ->sortBy([['prestamo.cedula_empleado', 'asc'], ['id_prestamo', 'asc'], ['numero_cuota', 'asc']]);

        $hermanas = Cuota::query()
            ->whereIn('id_prestamo', $cuotas->pluck('id_prestamo')->unique()->all())
            ->get(['id_prestamo', 'numero_cuota', 'valor_capital', 'estado'])
            ->groupBy('id_prestamo');

        $filas = [];
        $detalle = [];

        foreach ($cuotas as $cuota) {
            $item = $porId[$cuota->id_cuota];
            $prestamo = $cuota->prestamo;
            $deLaCuota = $hermanas[$cuota->id_prestamo] ?? new Collection;

            $saldoInicial = round((float) $deLaCuota->where('numero_cuota', '>=', $cuota->numero_cuota)->sum('valor_capital'), 2);
            $capital = (float) $cuota->valor_capital;
            $pagadas = $deLaCuota->where('numero_cuota', '<', $cuota->numero_cuota)->where('estado', EstadoCuota::Pagada)->count();

            $filas[] = [
                (string) $prestamo->cedula_empleado,
                (string) $prestamo->empleado?->nombre_completo,
                $prestamo->empleado?->tipo_vinculacion?->etiqueta() ?? '',
                $prestamo->tipoPrestamo->nombre,
                $prestamo->id_prestamo,
                (float) $prestamo->monto_aprobado,
                (int) $prestamo->num_cuotas,
                $cuota->numero_cuota,
                $pagadas,
                $saldoInicial,
                (float) $cuota->valor_total,
                $capital,
                (float) $cuota->valor_interes,
                round($saldoInicial - $capital, 2),
                $item['estado'],
                $item['estado'] === self::EXCLUIDA ? $item['motivo'] : '',
            ];

            $detalle[] = ['modalidad' => $prestamo->tipoPrestamo->nombre, 'excluida' => $item['estado'] === self::EXCLUIDA, 'valor' => (float) $cuota->valor_total, 'capital' => $capital, 'intereses' => (float) $cuota->valor_interes];
        }

        $detalle = collect($detalle);

        return [
            'anio' => $anio,
            'quincena' => $quincena,
            'tipo' => $tipo,
            'vencimiento' => $periodo['corte']->toDateString(),
            'generado' => now(CalendarioQuincenalService::ZONA)->format('Y-m-d H:i'),
            'usuario' => "{$actor->nombre_completo} ({$actor->cedula})",
            'filas' => $filas,
            'totales' => [
                'a_descontar' => $this->sumar($detalle->where('excluida', false)),
                'excluidas' => $this->sumar($detalle->where('excluida', true)),
            ],
            'resumen' => $detalle->groupBy('modalidad')->sortKeys()->map(function (Collection $grupo, string $modalidad): array {
                $descontar = $this->sumar($grupo->where('excluida', false));
                $excluidas = $this->sumar($grupo->where('excluida', true));

                return ['modalidad' => $modalidad, 'cuotas' => $descontar['cuotas'], 'valor' => $descontar['valor'], 'capital' => $descontar['capital'], 'intereses' => $descontar['intereses'], 'excluidas' => $excluidas['cuotas'], 'valor_excluido' => $excluidas['valor']];
            })->values()->all(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $filas
     * @return array{cuotas: int, valor: float, capital: float, intereses: float}
     */
    private function sumar(Collection $filas): array
    {
        return [
            'cuotas' => $filas->count(),
            'valor' => round((float) $filas->sum('valor'), 2),
            'capital' => round((float) $filas->sum('capital'), 2),
            'intereses' => round((float) $filas->sum('intereses'), 2),
        ];
    }

    private function celda(mixed $valor): string
    {
        if (is_float($valor)) {
            return number_format($valor, 2, ',', '');
        }

        $texto = (string) $valor;

        // Un texto que empiece como fórmula se neutraliza para que Excel no lo ejecute.
        return $texto !== '' && str_contains('=+-@', $texto[0]) ? "'".$texto : $texto;
    }
}
