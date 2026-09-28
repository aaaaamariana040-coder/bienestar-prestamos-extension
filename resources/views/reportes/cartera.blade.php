@extends('layouts.app')

@section('content')
    <h1 class="h3 mb-4">Cartera en mora</h1>

    @include('informes._guardar', ['tipo' => 'cartera', 'parametros' => []])

    <table class="table table-striped bg-white">
        <thead>
            <tr>
                <th>Empleado</th>
                <th>Modalidad</th>
                <th>Préstamo</th>
                <th>Cuota</th>
                <th>Vencimiento</th>
                <th>Valor</th>
                <th>Días de mora</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($filas as $fila)
                <tr>
                    <td>{{ $fila->prestamo->empleado->nombre_completo }}</td>
                    <td>{{ $fila->prestamo->tipoPrestamo->nombre }}</td>
                    <td><a href="{{ route('prestamos.show', $fila->prestamo) }}">#{{ $fila->prestamo->id_prestamo }}</a></td>
                    <td>{{ $fila->numero_cuota }}</td>
                    <td>{{ $fila->fecha_vencimiento->format('Y-m-d') }}</td>
                    <td>{{ number_format($fila->valor_total, 2) }}</td>
                    <td>{{ $fila->dias_mora }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="text-center text-muted">No hay cuotas vencidas.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
@endsection
