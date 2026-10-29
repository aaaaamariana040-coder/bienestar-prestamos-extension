@extends('layouts.app')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0">Lote {{ $lote }}</h1>
        <a href="{{ route('cargar-empleados.index') }}" class="btn btn-outline-secondary">Volver a cargar empleados</a>
    </div>

    <p class="text-muted">{{ $empleados->count() }} empleado(s) en este lote, marcados con origen «carga inicial».</p>

    <table class="table table-striped bg-white align-middle" id="empleados-del-lote">
        <thead>
            <tr>
                <th>Cédula</th>
                <th>Nombre</th>
                <th>Vinculación</th>
                <th class="text-end">Salario</th>
                <th class="text-end">Acciones</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($empleados as $empleado)
                <tr>
                    <td>{{ $empleado->cedula }}</td>
                    <td>{{ $empleado->nombre_completo }}</td>
                    <td>{{ $empleado->tipo_vinculacion->etiqueta() }}</td>
                    <td class="text-end">{{ number_format($empleado->salario, 2, ',', '.') }}</td>
                    <td class="text-end">
                        @can('empleados.ver')
                            <a href="{{ route('empleados.show', $empleado) }}" class="btn btn-sm btn-outline-secondary">Ver</a>
                        @endcan
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($motivos === [])
        <form method="POST" action="{{ route('cargar-empleados.revertir', $lote) }}"
              onsubmit="return confirm('¿Revertir el lote {{ $lote }}? Se eliminarán sus empleados.');">
            @csrf
            <button type="submit" class="btn btn-outline-danger">Revertir lote</button>
            <span class="text-muted ms-2">Elimina los empleados del lote; solo es posible mientras ninguno tenga solicitudes ni préstamos.</span>
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
