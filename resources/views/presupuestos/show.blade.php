@extends('layouts.app')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">{{ $presupuesto->tipoPrestamo->nombre }} — {{ $presupuesto->anio }}</h1>
        <div>
            @can('update', $presupuesto)
                <a href="{{ route('presupuestos.edit', $presupuesto) }}" class="btn btn-outline-secondary">Editar</a>
            @endcan
            <a href="{{ route('presupuestos.index', ['anio' => $presupuesto->anio]) }}" class="btn btn-outline-secondary">Volver</a>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-3">Monto asignado</dt>
                <dd class="col-sm-9">{{ number_format($presupuesto->monto_asignado, 2) }}</dd>

                <dt class="col-sm-3">Monto comprometido</dt>
                <dd class="col-sm-9">{{ number_format($presupuesto->monto_comprometido, 2) }}</dd>

                <dt class="col-sm-3">Monto disponible</dt>
                <dd class="col-sm-9">{{ number_format($presupuesto->monto_disponible, 2) }}</dd>
            </dl>
        </div>
    </div>

    <div class="card">
        <div class="card-header">Historial de modificaciones</div>
        <div class="card-body">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Campo</th>
                        <th>Monto anterior</th>
                        <th>Monto nuevo</th>
                        <th>Motivo</th>
                        <th>Usuario</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($presupuesto->historial as $entrada)
                        <tr>
                            <td>{{ $entrada->fecha->format('Y-m-d H:i') }}</td>
                            <td>{{ $entrada->campo->value }}</td>
                            <td>{{ number_format($entrada->monto_anterior, 2) }}</td>
                            <td>{{ number_format($entrada->monto_nuevo, 2) }}</td>
                            <td>{{ $entrada->motivo }}</td>
                            <td>{{ $entrada->usuario->nombre_completo }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted">Sin modificaciones registradas todavía.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
