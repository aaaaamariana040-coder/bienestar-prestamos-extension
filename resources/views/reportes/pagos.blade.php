@extends('layouts.app')

@section('content')
    <h1 class="h3 mb-4">Resumen de pagos</h1>

    <form method="GET" action="{{ route('reportes.pagos') }}" class="row g-2 align-items-end mb-4">
        <div class="col-auto">
            <label for="desde" class="form-label">Pagado desde</label>
            <input type="date" id="desde" name="desde" class="form-control" value="{{ $desde }}">
        </div>
        <div class="col-auto">
            <label for="hasta" class="form-label">Pagado hasta</label>
            <input type="date" id="hasta" name="hasta" class="form-control" value="{{ $hasta }}">
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-outline-secondary">Consultar</button>
        </div>
    </form>

    <p class="text-muted" id="periodo-resumen">Periodo: {{ $periodo }}.</p>

    @include('informes._guardar', ['tipo' => 'pagos', 'parametros' => ['desde' => $desde, 'hasta' => $hasta]])

    <table class="table table-striped bg-white">
        <thead>
            <tr>
                <th>Modalidad</th>
                <th>Cantidad de pagos</th>
                <th>Monto total</th>
                <th>Interés aplicado</th>
                <th>Capital aplicado</th>
                <th class="text-end">Acciones</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($filas as $fila)
                <tr>
                    <td>{{ $fila->modalidad }}</td>
                    <td>{{ $fila->total_pagos }}</td>
                    <td>{{ number_format($fila->monto_total, 2) }}</td>
                    <td>{{ number_format($fila->monto_interes, 2) }}</td>
                    <td>{{ number_format($fila->monto_capital, 2) }}</td>
                    <td class="text-end">
                        @if (isset($fila->id_tipo))
                            <a href="{{ route('reportes.pagos.detalle', array_filter(['tipoPrestamo' => $fila->id_tipo, 'desde' => $desde, 'hasta' => $hasta])) }}" class="btn btn-sm btn-outline-secondary">Ver detalle</a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="text-center text-muted">No hay pagos que coincidan con los filtros.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
@endsection
