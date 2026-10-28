@extends('layouts.app')

@section('content')
    <h1 class="h3 mb-4">Editar usuario: {{ $usuario->nombre_completo }}</h1>

    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $mensaje)
                    <li>{{ $mensaje }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('usuarios.update', $usuario) }}">
        @csrf
        @method('PUT')

        <div class="mb-3">
            <label class="form-label">Cédula</label>
            <input type="text" class="form-control" value="{{ $usuario->cedula }}" disabled>
        </div>

        <div class="mb-3">
            <label for="nombre" class="form-label">Nombre</label>
            <input type="text" id="nombre" name="nombre" class="form-control" maxlength="80"
                   value="{{ old('nombre', $usuario->nombre) }}" required>
        </div>

        <div class="mb-3">
            <label for="apellido" class="form-label">Apellido</label>
            <input type="text" id="apellido" name="apellido" class="form-control" maxlength="80"
                   value="{{ old('apellido', $usuario->apellido) }}" required>
        </div>

        <div class="mb-3">
            <label for="rol" class="form-label">Rol</label>
            @if ($usuario->esPrincipal())
                <input type="hidden" name="rol" value="{{ $usuario->rol->value }}">
            @endif
            <select id="rol" @unless ($usuario->esPrincipal()) name="rol" @endunless class="form-select" required @disabled($usuario->esPrincipal())>
                @foreach (\App\Enums\RolUsuario::cases() as $rol)
                    <option value="{{ $rol->value }}" @selected(old('rol', $usuario->rol->value) === $rol->value)>
                        {{ $rol->name }}
                    </option>
                @endforeach
            </select>
            @if ($usuario->esPrincipal())
                <div class="form-text">El administrador principal no puede dejar de ser Administrador.</div>
            @endif
        </div>

        @include('usuarios._permisos', ['modo' => 'editar'])

        <button type="submit" class="btn btn-primary">Guardar cambios</button>
        <a href="{{ route('usuarios.index') }}" class="btn btn-outline-secondary">Cancelar</a>
    </form>
@endsection
