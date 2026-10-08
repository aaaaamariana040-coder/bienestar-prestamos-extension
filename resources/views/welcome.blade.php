<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ config('app.name', 'Laravel') }}</title>

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="d-flex align-items-center justify-content-center vh-100 bg-light">
        <div class="container text-center">
            <h1 class="mb-3">{{ config('app.name', 'Laravel') }}</h1>
            <p class="text-muted mb-4">Préstamos de Bienestar Social — Politécnico Colombiano Jaime Isaza Cadavid</p>

            <a href="{{ route('login') }}" class="btn btn-primary">Iniciar sesión</a>
        </div>
    </body>
</html>
