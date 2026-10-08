@extends('layouts.app')

@section('content')
    <h1 class="h3 mb-4">Nuevo usuario</h1>

    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $mensaje)
                    <li>{{ $mensaje }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('usuarios.store') }}">
        @csrf

        <div class="mb-3">
            <label for="cedula" class="form-label">Empleado</label>
            <select id="cedula" name="cedula" class="form-select" required>
                <option value="">Selecciona un empleado…</option>
                @foreach ($empleados as $opcion)
                    <option value="{{ $opcion->cedula }}" @selected(old('cedula') === $opcion->cedula)>
                        {{ $opcion->nombre_completo }} ({{ $opcion->cedula }})
                    </option>
                @endforeach
            </select>
        </div>

        <div class="mb-3">
            <label for="rol" class="form-label">Rol</label>
            <select id="rol" name="rol" class="form-select" required>
                @foreach (\App\Enums\RolUsuario::cases() as $rol)
                    <option value="{{ $rol->value }}" @selected(old('rol') === $rol->value)>
                        {{ $rol->name }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="mb-3">
            <label for="password" class="form-label">Contraseña</label>
            <input type="password" id="password" name="password" class="form-control" minlength="8" title="Mínimo 8 caracteres. La confirmación debe coincidir con la contraseña." aria-describedby="reglas-password" required>
            <x-reglas-password />
        </div>

        <div class="mb-3">
            <label for="password_confirmation" class="form-label">Confirmar contraseña</label>
            <input type="password" id="password_confirmation" name="password_confirmation" class="form-control" minlength="8" required>
        </div>

        <button type="submit" class="btn btn-primary">Crear usuario</button>
        <a href="{{ route('empleados.index') }}" class="btn btn-outline-secondary">Cancelar</a>
    </form>
@endsection
