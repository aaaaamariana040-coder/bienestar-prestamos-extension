@extends('layouts.app')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">Préstamo #{{ $prestamo->id_prestamo }}</h1>
        <a href="{{ route('prestamos.index') }}" class="btn btn-outline-secondary">Volver a préstamos</a>
    </div>

    <table class="table bg-white mb-4">
        <tbody>
            <tr>
                <th style="width: 220px;">Empleado</th>
                <td>{{ $prestamo->empleado->nombre_completo }} ({{ $prestamo->cedula_empleado }})</td>
            </tr>
            <tr>
                <th>Modalidad</th>
                <td>{{ $prestamo->tipoPrestamo->nombre }}</td>
            </tr>
            <tr>
                <th>Monto aprobado</th>
                <td>{{ number_format($prestamo->monto_aprobado, 2) }}</td>
            </tr>
            <tr>
                <th>Cuota periódica</th>
                <td>{{ number_format($prestamo->cuota_periodica, 2) }}</td>
            </tr>
            <tr>
                <th>Número de cuotas</th>
                <td>{{ $prestamo->num_cuotas }}</td>
            </tr>
            <tr>
                <th>Fecha de autorización</th>
                <td>{{ $prestamo->fecha_autorizacion->format('Y-m-d') }}</td>
            </tr>
            <tr>
                <th>Estado</th>
                <td>{{ $prestamo->estado->value }}</td>
            </tr>
        </tbody>
    </table>

    <h2 class="h5 mb-3">Tabla de amortización</h2>

    <table class="table table-sm table-striped bg-white align-middle">
        <thead>
            <tr>
                <th>#</th>
                <th>Fecha de vencimiento</th>
                <th>Capital</th>
                <th>Interés</th>
                <th>Total</th>
                <th>Estado</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($prestamo->cuotas as $cuota)
                <tr>
                    <td>{{ $cuota->numero_cuota }}</td>
                    <td>{{ $cuota->fecha_vencimiento->format('Y-m-d') }}</td>
                    <td>{{ number_format($cuota->valor_capital, 2) }}</td>
                    <td>{{ number_format($cuota->valor_interes, 2) }}</td>
                    <td>{{ number_format($cuota->valor_total, 2) }}</td>
                    <td>{{ $cuota->estado->value }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endsection
