<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Iniciar sesión — {{ config('app.name', 'Bienestar Préstamos') }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="bg-light d-flex align-items-center justify-content-center vh-100">
        <div class="card shadow-sm" style="width: 100%; max-width: 24rem;">
            <div class="card-body p-4">
                <h1 class="h4 mb-4 text-center">Bienestar Préstamos</h1>

                @if ($errors->any())
                    <div class="alert alert-danger">
                        {{ $errors->first() }}
                    </div>
                @endif

                <form method="POST" action="{{ route('login') }}">
                    @csrf

                    <div class="mb-3">
                        <label for="cedula" class="form-label">Cédula</label>
                        <input type="text" id="cedula" name="cedula" class="form-control" value="{{ old('cedula') }}" required autofocus>
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label">Contraseña</label>
                        <input type="password" id="password" name="password" class="form-control" required>
                    </div>

                    <button type="submit" class="btn btn-primary w-100">Entrar</button>
                </form>
            </div>
        </div>
    </body>
</html>
