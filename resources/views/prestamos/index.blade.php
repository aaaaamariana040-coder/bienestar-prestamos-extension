@extends('layouts.app')

@section('content')
    <h1 class="h3 mb-4">Préstamos</h1>

    <table class="table table-striped bg-white align-middle">
        <thead>
            <tr>
                <th>Empleado</th>
                <th>Modalidad</th>
                <th>Monto aprobado</th>
                <th>Cuotas</th>
                <th>Fecha de autorización</th>
                <th>Estado</th>
                <th class="text-end">Acciones</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($prestamos as $prestamo)
                <tr>
                    <td>{{ $prestamo->empleado->nombre_completo }}</td>
                    <td>{{ $prestamo->tipoPrestamo->nombre }}</td>
                    <td>{{ number_format($prestamo->monto_aprobado, 2) }}</td>
                    <td>{{ \App\Models\TipoPrestamo::plazoEnQuincenas($prestamo->num_cuotas) }}</td>
                    <td>{{ $prestamo->fecha_autorizacion->format('Y-m-d') }}</td>
                    <td>{{ $prestamo->estado->value }}</td>
                    <td class="text-end">
                        <a href="{{ route('prestamos.show', $prestamo) }}" class="btn btn-sm btn-outline-secondary">Ver</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="text-center text-muted">No hay préstamos registrados.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
@endsection
