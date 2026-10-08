@extends('layouts.app')

@section('content')
    <h1 class="h3 mb-4">Cambiar mi contraseña</h1>

    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $mensaje)
                    <li>{{ $mensaje }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('password.update') }}">
        @csrf
        @method('PUT')

        <div class="mb-3">
            <label for="current_password" class="form-label">Contraseña actual</label>
            <input type="password" id="current_password" name="current_password" class="form-control" required>
        </div>

        <div class="mb-3">
            <label for="password" class="form-label">Nueva contraseña</label>
            <input type="password" id="password" name="password" class="form-control" minlength="8" title="Mínimo 8 caracteres, al menos una mayúscula, una minúscula y un número. La confirmación debe coincidir con la contraseña." aria-describedby="reglas-password" required>
            <x-reglas-password />
        </div>

        <div class="mb-3">
            <label for="password_confirmation" class="form-label">Confirmar nueva contraseña</label>
            <input type="password" id="password_confirmation" name="password_confirmation" class="form-control" minlength="8" required>
        </div>

        <button type="submit" class="btn btn-primary">Actualizar contraseña</button>
    </form>
@endsection
