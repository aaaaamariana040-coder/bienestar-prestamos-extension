@extends('layouts.app')

@section('content')
    @php
        $calendario = app(\App\Services\CalendarioQuincenalService::class);
        $zona = \App\Services\CalendarioQuincenalService::ZONA;
    @endphp

    <h1 class="h3 mb-4">Quincenas</h1>

    <h2 class="h5 mb-3">Pendientes de confirmar</h2>

    <table class="table table-striped bg-white align-middle mb-4">
        <thead>
            <tr>
                <th>Quincena</th>
                <th>Periodo</th>
                <th class="text-end">Cuotas</th>
                <th class="text-end">Total</th>
                <th class="text-end">Acciones</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($pendientes as $quincena)
                <tr>
                    <td>Quincena {{ $quincena['numero'] }} de {{ $quincena['anio'] }}</td>
                    <td>{{ $quincena['inicio'] }} al {{ $quincena['corte'] }}</td>
                    <td class="text-end">{{ $quincena['cuotas'] }}</td>
                    <td class="text-end">{{ number_format($quincena['total'], 2) }}</td>
                    <td class="text-end">
                        <a href="{{ route('quincenas.vista-previa', [$quincena['anio'], $quincena['numero']]) }}" class="btn btn-sm btn-primary">Revisar y confirmar</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="text-center text-muted">No hay quincenas iniciadas pendientes de confirmar.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <h2 class="h5 mb-3">Quincenas confirmadas</h2>

    <table class="table table-striped bg-white align-middle">
        <thead>
            <tr>
                <th>Quincena</th>
                <th>Periodo</th>
                <th>Confirmada por</th>
                <th>Fecha y hora</th>
                <th class="text-end">Cuotas pagadas</th>
                <th class="text-end">Total</th>
                <th class="text-end">Excluidas</th>
                <th class="text-end">Acciones</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($procesos as $proceso)
                @php
                    $periodo = $calendario->quincena($proceso->anio, $proceso->numero_quincena);
                @endphp
                <tr>
                    <td>Quincena {{ $proceso->numero_quincena }} de {{ $proceso->anio }}</td>
                    <td>{{ $periodo['inicio']->toDateString() }} al {{ $periodo['corte']->toDateString() }}</td>
                    <td>
                        @if ($proceso->modo_marcado === \App\Enums\ModoMarcado::Automatico)
                            Proceso automático
                        @else
                            {{ $proceso->usuario?->nombre_completo }}
                        @endif
                    </td>
                    <td>{{ $proceso->finalizado_en?->setTimezone($zona)->format('d/m/Y H:i') }}</td>
                    <td class="text-end">{{ $proceso->resumen['pagos_creados'] ?? 0 }}</td>
                    <td class="text-end">{{ number_format($proceso->resumen['total'] ?? 0, 2) }}</td>
                    <td class="text-end">{{ count($proceso->resumen['excluidas_manuales'] ?? []) }}</td>
                    <td class="text-end">
                        <a href="{{ route('quincenas.proceso', $proceso) }}" class="btn btn-sm btn-outline-secondary">Ver</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="text-center text-muted">Todavía no se ha confirmado ninguna quincena.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    {{ $procesos->links() }}
@endsection
