@extends('layouts.app')

@section('content')
    <h1 class="h3 mb-4">Ejecución del presupuesto — {{ $anio }}</h1>

    <form method="GET" action="{{ route('reportes.presupuesto') }}" class="row g-2 align-items-end mb-4">
        <div class="col-auto">
            <label for="anio" class="form-label">Año</label>
            <input type="number" id="anio" name="anio" class="form-control" value="{{ $anio }}">
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-outline-secondary">Consultar</button>
        </div>
    </form>

    <table class="table table-striped bg-white">
        <thead>
            <tr>
                <th>Modalidad</th>
                <th>Asignado</th>
                <th>Comprometido</th>
                <th>Disponible</th>
                <th>% ejecutado</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($presupuestos as $presupuesto)
                <tr>
                    <td>{{ $presupuesto->tipoPrestamo->nombre }}</td>
                    <td>{{ number_format($presupuesto->monto_asignado, 2) }}</td>
                    <td>{{ number_format($presupuesto->monto_comprometido, 2) }}</td>
                    <td>{{ number_format($presupuesto->monto_disponible, 2) }}</td>
                    <td>{{ $presupuesto->monto_asignado > 0 ? number_format($presupuesto->monto_comprometido / $presupuesto->monto_asignado * 100, 1) : '0.0' }}%</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="text-center text-muted">No hay presupuesto registrado para este año.</td>
                </tr>
            @endforelse
        </tbody>
        @if ($presupuestos->isNotEmpty())
            <tfoot>
                <tr class="fw-bold">
                    <td>Total</td>
                    <td>{{ number_format($presupuestos->sum('monto_asignado'), 2) }}</td>
                    <td>{{ number_format($presupuestos->sum('monto_comprometido'), 2) }}</td>
                    <td>{{ number_format($presupuestos->sum('monto_disponible'), 2) }}</td>
                    <td></td>
                </tr>
            </tfoot>
        @endif
    </table>
@endsection
