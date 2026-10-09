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
            <label for="cedula" class="form-label">Cédula</label>
            <input type="text" id="cedula" name="cedula" class="form-control" value="{{ old('cedula') }}"
                   inputmode="numeric" maxlength="10" pattern="[0-9]{1,10}"
                   title="Solo números, máximo 10 dígitos, sin puntos ni caracteres especiales" required>
        </div>

        <div class="row">
            <div class="col-md-6 mb-3">
                <label for="nombre" class="form-label">Nombre</label>
                <input type="text" id="nombre" name="nombre" class="form-control" maxlength="80" value="{{ old('nombre') }}" required>
            </div>

            <div class="col-md-6 mb-3">
                <label for="apellido" class="form-label">Apellido</label>
                <input type="text" id="apellido" name="apellido" class="form-control" maxlength="80" value="{{ old('apellido') }}" required>
            </div>
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
            <input type="password" id="password" name="password" class="form-control" minlength="8" title="Mínimo 8 caracteres, al menos una mayúscula, una minúscula y un número. La confirmación debe coincidir con la contraseña." aria-describedby="reglas-password" required>
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
