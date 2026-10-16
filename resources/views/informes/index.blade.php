@extends('layouts.app')

@section('content')
    <h1 class="h3 mb-4">Informes</h1>

    <form method="GET" action="{{ route('informes.index') }}" class="row g-2 align-items-end mb-4">
        <div class="col-auto">
            <label for="tipo" class="form-label">Tipo</label>
            <select id="tipo" name="tipo" class="form-select">
                <option value="">Todos</option>
                @foreach ($tipos as $tipoInforme)
                    <option value="{{ $tipoInforme->value }}" @selected($tipo === $tipoInforme)>{{ $tipoInforme->etiqueta() }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-auto">
            <label for="desde" class="form-label">Corte desde</label>
            <input type="date" id="desde" name="desde" class="form-control" value="{{ $desde }}">
        </div>
        <div class="col-auto">
            <label for="hasta" class="form-label">Corte hasta</label>
            <input type="date" id="hasta" name="hasta" class="form-control" value="{{ $hasta }}">
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-outline-secondary">Consultar</button>
        </div>
    </form>

    <table class="table table-striped bg-white align-middle">
        <thead>
            <tr>
                <th>#</th>
                <th>Tipo</th>
                <th>Fecha de corte</th>
                <th>Generado el</th>
                <th>Generado por</th>
                <th class="text-end">Acciones</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($informes as $informe)
                <tr>
                    <td>{{ $informe->id_informe }}</td>
                    <td>{{ $informe->tipo->etiqueta() }}</td>
                    <td>{{ $informe->fecha_corte->format('Y-m-d') }}</td>
                    <td>{{ $informe->created_at->format('Y-m-d H:i') }}</td>
                    <td>{{ $informe->generadoPor->nombre_completo }}</td>
                    <td class="text-end">
                        <a href="{{ route('informes.show', $informe) }}" class="btn btn-sm btn-outline-primary">Ver</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="text-center text-muted">No hay informes guardados que coincidan con los filtros.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    {{ $informes->links() }}
@endsection
