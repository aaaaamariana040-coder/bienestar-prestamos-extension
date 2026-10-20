@extends('layouts.app')

@section('content')
    @php
        $resumen = $proceso->resumen ?? [];
        $periodo = app(\App\Services\CalendarioQuincenalService::class)->quincena($proceso->anio, $proceso->numero_quincena);
        $automatico = $proceso->modo_marcado === \App\Enums\ModoMarcado::Automatico;
    @endphp

    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <h1 class="h3 mb-1">Quincena {{ $proceso->numero_quincena }} de {{ $proceso->anio }}</h1>
            <p class="text-muted mb-0">Periodo {{ $periodo['inicio']->toDateString() }} al {{ $periodo['corte']->toDateString() }}</p>
        </div>
        <a href="{{ route('quincenas.index') }}" class="btn btn-outline-secondary btn-sm">Volver a Quincenas</a>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-3">{{ $automatico ? 'Ejecutada por' : 'Confirmada por' }}</dt>
                <dd class="col-sm-9">{{ $automatico ? 'Proceso automático (Sistema)' : $proceso->usuario?->nombre_completo }}</dd>

                <dt class="col-sm-3">Fecha y hora</dt>
                <dd class="col-sm-9">{{ $proceso->finalizado_en?->setTimezone(\App\Services\CalendarioQuincenalService::ZONA)->format('d/m/Y H:i') }}</dd>

                <dt class="col-sm-3">Fecha de los pagos</dt>
                <dd class="col-sm-9">{{ $resumen['fecha_pago'] ?? '—' }}</dd>

                <dt class="col-sm-3">Cuotas pagadas</dt>
                <dd class="col-sm-9">{{ $resumen['pagos_creados'] ?? 0 }} por {{ number_format($resumen['total'] ?? 0, 2) }}</dd>

                <dt class="col-sm-3">Cuotas excluidas</dt>
                <dd class="col-sm-9 mb-0">{{ count($resumen['excluidas_manuales'] ?? []) }}</dd>
            </dl>
        </div>
    </div>

    <h2 class="h5 mb-3">Cuotas pagadas</h2>

    <table class="table table-striped bg-white align-middle mb-4">
        <thead>
            <tr>
                <th>Préstamo</th>
                <th>Empleado</th>
                <th>Cuota</th>
                <th>Vence</th>
                <th class="text-end">Valor</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($resumen['cuotas'] ?? [] as $cuota)
                <tr>
                    <td><a href="{{ route('prestamos.show', $cuota['id_prestamo']) }}">#{{ $cuota['id_prestamo'] }}</a></td>
                    <td>{{ $cuota['cedula_empleado'] }}@if (! empty($cuota['nombre_empleado'])) · {{ $cuota['nombre_empleado'] }}@endif</td>
                    <td>{{ $cuota['numero_cuota'] }}</td>
                    <td>{{ $cuota['fecha_vencimiento'] }}</td>
                    <td class="text-end">{{ number_format($cuota['valor_total'], 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="text-center text-muted">No se pagó ninguna cuota.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @if (! empty($resumen['excluidas_manuales']))
        <h2 class="h5 mb-3">Cuotas excluidas</h2>

        <table class="table table-striped bg-white align-middle">
            <thead>
                <tr>
                    <th>Préstamo</th>
                    <th>Empleado</th>
                    <th>Cuota</th>
                    <th class="text-end">Valor</th>
                    <th>Motivo</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($resumen['excluidas_manuales'] as $cuota)
                    <tr>
                        <td><a href="{{ route('prestamos.show', $cuota['id_prestamo']) }}">#{{ $cuota['id_prestamo'] }}</a></td>
                        <td>{{ $cuota['cedula_empleado'] }}@if (! empty($cuota['nombre_empleado'])) · {{ $cuota['nombre_empleado'] }}@endif</td>
                        <td>{{ $cuota['numero_cuota'] }}</td>
                        <td class="text-end">{{ number_format($cuota['valor_total'], 2) }}</td>
                        <td>{{ $cuota['motivo'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endsection
