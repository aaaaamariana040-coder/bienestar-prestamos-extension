@extends('layouts.app')

@section('content')
    <h1 class="h3 mb-4">Reporte de préstamos</h1>

    <form method="GET" action="{{ route('reportes.prestamos') }}" class="row g-2 align-items-end mb-4">
        <div class="col-auto">
            <label for="id_tipo" class="form-label">Modalidad</label>
            <select id="id_tipo" name="id_tipo" class="form-select">
                <option value="">Todas</option>
                @foreach ($tiposPrestamo as $tipoPrestamo)
                    <option value="{{ $tipoPrestamo->id_tipo }}" @selected($idTipo === $tipoPrestamo->id_tipo)>{{ $tipoPrestamo->nombre }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-auto">
            <label for="estado" class="form-label">Estado</label>
            <select id="estado" name="estado" class="form-select">
                <option value="">Todos</option>
                @foreach ($estados as $estadoCase)
                    <option value="{{ $estadoCase->value }}" @selected($estado === $estadoCase->value)>{{ $estadoCase->value }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-auto">
            <label for="desde" class="form-label">Autorizado desde</label>
            <input type="date" id="desde" name="desde" class="form-control" value="{{ $desde }}">
        </div>
        <div class="col-auto">
            <label for="hasta" class="form-label">Autorizado hasta</label>
            <input type="date" id="hasta" name="hasta" class="form-control" value="{{ $hasta }}">
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-outline-secondary">Consultar</button>
        </div>
    </form>

    <table class="table table-striped bg-white">
        <thead>
            <tr>
                <th>Modalidad</th>
                <th>Estado</th>
                <th>Cantidad</th>
                <th>Monto total</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($filas as $fila)
                <tr>
                    <td>{{ $fila->modalidad }}</td>
                    <td>{{ $fila->estado }}</td>
                    <td>{{ $fila->total_prestamos }}</td>
                    <td>{{ number_format($fila->monto_total, 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="text-center text-muted">No hay préstamos que coincidan con los filtros.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
@endsection
