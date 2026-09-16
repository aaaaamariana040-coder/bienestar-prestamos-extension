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
            <label class="form-label">Nombre completo</label>
            <input type="text" class="form-control" value="{{ $usuario->nombre_completo }}" disabled>
        </div>

        <div class="mb-3">
            <label for="rol" class="form-label">Rol</label>
            <select id="rol" name="rol" class="form-select" required>
                @foreach (\App\Enums\RolUsuario::cases() as $rol)
                    <option value="{{ $rol->value }}" @selected(old('rol', $usuario->rol->value) === $rol->value)>
                        {{ $rol->name }}
                    </option>
                @endforeach
            </select>
        </div>

        <button type="submit" class="btn btn-primary">Guardar cambios</button>
        <a href="{{ route('usuarios.index') }}" class="btn btn-outline-secondary">Cancelar</a>
    </form>
@endsection
