@extends('layouts.app')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0">Lote {{ $lote }}</h1>
        <a href="{{ route('cargar-prestamos-vigentes.index') }}" class="btn btn-outline-secondary">Volver a cargar préstamos vigentes</a>
    </div>

    <p class="text-muted">
        {{ $prestamos->count() }} préstamo(s) en este lote, marcados con origen «carga inicial». Sus primeras cuotas pagadas
        no tienen pagos registrados en el aplicativo.
    </p>

    <table class="table table-striped bg-white align-middle" id="prestamos-del-lote">
        <thead>
            <tr>
                <th>Préstamo</th>
                <th>Empleado</th>
                <th>Modalidad</th>
                <th class="text-end">Monto inicial</th>
                <th>Desembolso</th>
                <th>Cuotas</th>
                <th class="text-end">Acciones</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($prestamos as $prestamo)
                <tr>
                    <td>#{{ $prestamo->id_prestamo }}</td>
                    <td>{{ $prestamo->empleado->nombre_completo }} ({{ $prestamo->cedula_empleado }})</td>
                    <td>{{ $prestamo->tipoPrestamo->nombre }}</td>
                    <td class="text-end">{{ number_format($prestamo->monto_aprobado, 2, ',', '.') }}</td>
                    <td>{{ $prestamo->fecha_autorizacion->format('Y-m-d') }}</td>
                    <td>{{ $prestamo->progreso_cuotas }}</td>
                    <td class="text-end">
                        <a href="{{ route('prestamos.show', $prestamo) }}" class="btn btn-sm btn-outline-secondary">Ver</a>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($motivos === [])
        <form method="POST" action="{{ route('cargar-prestamos-vigentes.revertir', $lote) }}"
              onsubmit="return confirm('¿Revertir el lote {{ $lote }}? Se eliminarán sus préstamos con sus cuotas y desembolsos.');">
            @csrf
            <button type="submit" class="btn btn-outline-danger">Revertir lote</button>
            <span class="text-muted ms-2">Elimina los préstamos del lote; solo es posible mientras ninguno tenga pagos, abonos o cancelaciones.</span>
        </form>
    @else
        <div class="alert alert-secondary" role="alert">
            <strong>Este lote ya no se puede revertir:</strong>
            <ul class="mb-0">
                @foreach ($motivos as $motivo)
                    <li>{{ $motivo }}</li>
                @endforeach
            </ul>
        </div>
    @endif
@endsection
