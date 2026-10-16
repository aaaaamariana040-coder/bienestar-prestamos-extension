@extends('layouts.app')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">Solicitudes de crédito</h1>
        @can('create', \App\Models\SolicitudCredito::class)
            <a href="{{ route('solicitudes.create') }}" class="btn btn-primary">Registrar solicitud</a>
        @endcan
    </div>

    <form method="GET" action="{{ route('solicitudes.index') }}" class="row g-2 align-items-end mb-3">
        <div class="col-md-4">
            <label for="cedula" class="form-label">Buscar por cédula</label>
            <x-buscador-cedula modo="filtro" id="cedula" name="cedula" :valor="$cedula" objetivo="resultados-solicitudes" />
        </div>

        <div class="col-md-2">
            <button type="submit" class="btn btn-primary">Buscar</button>
        </div>
    </form>

    <div id="resultados-solicitudes">
    @if ($cedula !== '' && $solicitudes->isEmpty())
        <div class="alert alert-info" role="status">No hay solicitudes con esa cédula.</div>
    @else
    <table class="table table-striped bg-white align-middle">
        <thead>
            <tr>
                <th>Empleado</th>
                <th>Modalidad</th>
                <th>Monto solicitado</th>
                <th>Plazo</th>
                <th>Fecha</th>
                <th>Estado</th>
                <th class="text-end">Acciones</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($solicitudes as $solicitud)
                <tr>
                    <td>{{ $solicitud->empleado->nombre_completo }}</td>
                    <td>{{ $solicitud->tipoPrestamo->nombre }}</td>
                    <td>{{ number_format($solicitud->monto_solicitado, 2) }}</td>
                    <td>{{ $solicitud->plazo_solicitado_legible }}</td>
                    <td>{{ $solicitud->fecha_solicitud->format('Y-m-d') }}</td>
                    <td>{{ $solicitud->estado->value }}</td>
                    <td class="text-end">
                        <a href="{{ route('solicitudes.show', $solicitud) }}" class="btn btn-sm btn-outline-secondary">Ver</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="text-center text-muted">No hay solicitudes registradas.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
    @endif
    </div>
@endsection
