@extends('layouts.app')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">Presupuesto del Fondo — {{ $anio }}</h1>
        <div>
            <a href="{{ route('presupuestos.sugerencia-vivienda', ['anio' => $anio]) }}" class="btn btn-outline-secondary">
                Calcular reparto de vivienda
            </a>
            @can('create', \App\Models\PresupuestoAnual::class)
                <a href="{{ route('presupuestos.create') }}" class="btn btn-primary">Registrar presupuesto</a>
            @endcan
        </div>
    </div>

    <form method="GET" action="{{ route('presupuestos.index') }}" class="row g-2 align-items-end mb-4">
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
                <th class="text-end">Acciones</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($tiposPrestamo as $tipoPrestamo)
                @php
                    $presupuesto = $presupuestos->get($tipoPrestamo->id_tipo);
                @endphp
                <tr>
                    <td>{{ $tipoPrestamo->nombre }}</td>
                    @if ($presupuesto)
                        <td>{{ number_format($presupuesto->monto_asignado, 2) }}</td>
                        <td>{{ number_format($presupuesto->monto_comprometido, 2) }}</td>
                        <td>{{ number_format($presupuesto->monto_disponible, 2) }}</td>
                        <td class="text-end">
                            <a href="{{ route('presupuestos.show', $presupuesto) }}" class="btn btn-sm btn-outline-secondary">Ver historial</a>
                            @can('update', $presupuesto)
                                <a href="{{ route('presupuestos.edit', $presupuesto) }}" class="btn btn-sm btn-outline-secondary">Editar</a>
                            @endcan
                        </td>
                    @else
                        <td colspan="3" class="text-muted">Sin presupuesto registrado para este año.</td>
                        <td class="text-end">
                            @can('create', \App\Models\PresupuestoAnual::class)
                                <a href="{{ route('presupuestos.create', ['anio' => $anio, 'id_tipo' => $tipoPrestamo->id_tipo]) }}" class="btn btn-sm btn-primary">Registrar</a>
                            @endcan
                        </td>
                    @endif
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="text-center text-muted">No hay modalidades registradas.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
@endsection
