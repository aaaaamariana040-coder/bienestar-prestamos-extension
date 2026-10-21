@extends('layouts.app')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">Usuarios</h1>
        <a href="{{ route('usuarios.create') }}" class="btn btn-primary">Nuevo usuario</a>
    </div>

    <table class="table table-striped bg-white">
        <thead>
            <tr>
                <th>Cédula</th>
                <th>Nombre</th>
                <th>Rol</th>
                <th>Estado</th>
                <th class="text-end">Acciones</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($usuarios as $usuario)
                <tr>
                    <td>{{ $usuario->cedula }}</td>
                    <td>{{ $usuario->nombre_completo }}</td>
                    <td>
                        {{ $usuario->rol->name }}
                        @if ($personalizados[$usuario->cedula] ?? false)
                            <span class="badge text-bg-warning" title="Sus permisos difieren de los del perfil">Personalizado</span>
                        @endif
                    </td>
                    <td>{{ $usuario->habilitado ? 'Habilitado' : 'Deshabilitado' }}</td>
                    <td class="text-end">
                        <a href="{{ route('usuarios.edit', $usuario) }}" class="btn btn-sm btn-outline-secondary">Editar rol y permisos</a>

                        @if ($usuario->habilitado && $usuario->rol === \App\Enums\RolUsuario::Administrador)
                            <span class="d-inline-block" title="El administrador no se puede deshabilitar" tabindex="0">
                                <button type="button" class="btn btn-sm btn-outline-secondary" disabled>Deshabilitar</button>
                            </span>
                        @elseif ($usuario->habilitado)
                            <form method="POST" action="{{ route('usuarios.deshabilitar', $usuario) }}" class="d-inline">
                                @csrf
                                @method('PATCH')
                                <button type="submit" class="btn btn-sm btn-outline-danger">Deshabilitar</button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('usuarios.habilitar', $usuario) }}" class="d-inline">
                                @csrf
                                @method('PATCH')
                                <button type="submit" class="btn btn-sm btn-outline-success">Habilitar</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="text-center text-muted">No hay usuarios registrados.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    {{ $usuarios->links() }}
@endsection
