@extends('layouts.app')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">Solicitud #{{ $solicitud->id_solicitud }}</h1>
        <a href="{{ route('solicitudes.index') }}" class="btn btn-outline-secondary">Volver a solicitudes</a>
    </div>

    <table class="table bg-white">
        <tbody>
            <tr>
                <th style="width: 220px;">Empleado</th>
                <td>{{ $solicitud->empleado->nombre_completo }} ({{ $solicitud->cedula_empleado }})</td>
            </tr>
            <tr>
                <th>Modalidad</th>
                <td>{{ $solicitud->tipoPrestamo->nombre }}</td>
            </tr>
            <tr>
                <th>Monto solicitado</th>
                <td>{{ number_format($solicitud->monto_solicitado, 2) }}</td>
            </tr>
            <tr>
                <th>Plazo</th>
                <td>{{ $solicitud->plazo_solicitado_legible }}</td>
            </tr>
            <tr>
                <th>Fecha de solicitud</th>
                <td>{{ $solicitud->fecha_solicitud->format('Y-m-d') }}</td>
            </tr>
            <tr>
                <th>Estado</th>
                <td>{{ $solicitud->estado->value }}</td>
            </tr>
            @if ($solicitud->motivo_rechazo)
                <tr>
                    <th>Motivo de rechazo</th>
                    <td>{{ $solicitud->motivo_rechazo }}</td>
                </tr>
            @endif
            @if ($solicitud->prestamo)
                <tr>
                    <th>Préstamo generado</th>
                    <td><a href="{{ route('prestamos.show', $solicitud->prestamo) }}">Ver préstamo #{{ $solicitud->prestamo->id_prestamo }}</a></td>
                </tr>
            @endif
        </tbody>
    </table>

    @can('decidir', $solicitud)
        @if ($problemas !== [])
            <div class="alert alert-warning">
                <p class="mb-1">La solicitud no cumple las condiciones para ser aprobada:</p>
                <ul class="mb-0">
                    @foreach ($problemas as $problema)
                        <li>{{ $problema }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="d-flex gap-2">
            <form method="POST" action="{{ route('solicitudes.aprobar', $solicitud) }}">
                @csrf
                @method('PATCH')
                <button type="submit" class="btn btn-success">Aprobar</button>
            </form>

            <button type="button" class="btn btn-danger" data-bs-toggle="collapse" data-bs-target="#rechazar-form">
                Rechazar
            </button>
        </div>

        <div id="rechazar-form" class="collapse mt-3">
            <form method="POST" action="{{ route('solicitudes.rechazar', $solicitud) }}" class="card card-body">
                @csrf
                @method('PATCH')

                <label for="motivo_rechazo" class="form-label">Motivo del rechazo</label>
                <textarea id="motivo_rechazo" name="motivo_rechazo" class="form-control mb-2" required>{{ old('motivo_rechazo') }}</textarea>

                <button type="submit" class="btn btn-danger align-self-start">Confirmar rechazo</button>
            </form>
        </div>
    @endcan
@endsection
