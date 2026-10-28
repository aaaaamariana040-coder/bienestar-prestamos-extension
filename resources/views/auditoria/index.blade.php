@extends('layouts.app')

@section('content')
    <h1 class="h3 mb-4">Auditoría</h1>

    <form method="GET" action="{{ route('auditoria.index') }}" class="row g-2 align-items-end mb-4">
        <div class="col-auto">
            <label for="cedula_usu" class="form-label">Usuario</label>
            <select id="cedula_usu" name="cedula_usu" class="form-select">
                <option value="">Todos</option>
                @foreach ($usuarios as $usuario)
                    <option value="{{ $usuario->cedula }}" @selected($cedulaUsu === $usuario->cedula)>{{ $usuario->nombre_completo }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-auto">
            <label for="funcion_realizada" class="form-label">Acción</label>
            <select id="funcion_realizada" name="funcion_realizada" class="form-select">
                <option value="">Todas</option>
                @foreach ($funciones as $funcion)
                    <option value="{{ $funcion }}" @selected($funcionRealizada === $funcion)>{{ $funcion }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-auto">
            <label for="desde" class="form-label">Desde</label>
            <input type="date" id="desde" name="desde" class="form-control" value="{{ $desde }}">
        </div>
        <div class="col-auto">
            <label for="hasta" class="form-label">Hasta</label>
            <input type="date" id="hasta" name="hasta" class="form-control" value="{{ $hasta }}">
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-outline-secondary">Consultar</button>
        </div>
    </form>

    <table class="table table-striped bg-white">
        <thead>
            <tr>
                <th>Fecha</th>
                <th>Hora</th>
                <th>Usuario</th>
                <th>Acción</th>
                <th>Detalle</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($registros as $registro)
                <tr>
                    <td>{{ $registro->fecha->format('Y-m-d') }}</td>
                    <td>{{ $registro->hora }}</td>
                    <td>{{ $registro->usuario->nombre_completo }}</td>
                    <td>{{ $registro->funcion_realizada }}</td>
                    <td>{{ $registro->detalle }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="text-center text-muted">No hay registros de auditoría.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    {{ $registros->links() }}
@endsection
