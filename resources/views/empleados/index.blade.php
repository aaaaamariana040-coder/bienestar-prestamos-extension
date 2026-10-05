@extends('layouts.app')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">Empleados</h1>
        <a href="{{ route('empleados.create') }}" class="btn btn-primary">Nuevo empleado</a>
    </div>

    <form method="GET" action="{{ route('empleados.index') }}" class="row g-2 align-items-end mb-3">
        <div class="col-md-6">
            <label for="q" class="form-label">Buscar por cédula o nombre</label>
            <input type="search" id="q" name="q" class="form-control" value="{{ $busqueda }}" placeholder="Ej. 1000000009 o Solicitud">
        </div>

        <div class="col-md-3">
            <label for="estado" class="form-label">Estado</label>
            <select id="estado" name="estado" class="form-select">
                <option value="" @selected($estado === '')>Todos</option>
                <option value="Activo" @selected($estado === 'Activo')>Activo</option>
                <option value="Retirado" @selected($estado === 'Retirado')>Retirado</option>
            </select>
        </div>

        <div class="col-md-3 d-flex gap-2">
            <button type="submit" class="btn btn-primary">Buscar</button>
            <a href="{{ route('empleados.index') }}" class="btn btn-outline-secondary">Limpiar</a>
        </div>
    </form>

    <table class="table table-striped bg-white">
        <thead>
            <tr>
                <th>Cédula</th>
                <th>Nombre</th>
                <th>Tipo</th>
                <th>Estado</th>
                <th>Salario</th>
                <th class="text-end">Acciones</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($empleados as $empleado)
                <tr>
                    <td>{{ $empleado->cedula }}</td>
                    <td>{{ $empleado->nombre_completo }}</td>
                    <td>{{ $empleado->tipo_vinculacion }}</td>
                    <td>{{ $empleado->estado }}</td>
                    <td>{{ number_format($empleado->salario, 2) }}</td>
                    <td class="text-end">
                        <a href="{{ route('empleados.show', $empleado) }}" class="btn btn-sm btn-outline-secondary">Ver</a>
                        <a href="{{ route('empleados.edit', $empleado) }}" class="btn btn-sm btn-outline-secondary">Editar</a>
                        <a href="{{ route('empleados.confirmar-eliminacion', $empleado) }}" class="btn btn-sm btn-outline-danger">Eliminar</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="text-center text-muted">{{ $busqueda !== '' || $estado !== '' ? 'Ningún empleado coincide con la búsqueda.' : 'No hay empleados registrados.' }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    {{ $empleados->links() }}
@endsection
