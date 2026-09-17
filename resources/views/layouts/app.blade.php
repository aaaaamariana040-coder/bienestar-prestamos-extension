<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $title ?? config('app.name', 'Bienestar Préstamos') }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="bg-light">
        <nav class="navbar navbar-expand navbar-dark bg-dark mb-4">
            <div class="container">
                <a class="navbar-brand" href="{{ route('empleados.index') }}">Bienestar Préstamos</a>

                @auth
                    <div class="d-flex align-items-center gap-3">
                        <a href="{{ route('empleados.index') }}" class="nav-link text-white">Empleados</a>

                        @can('viewAny', \App\Models\Usuario::class)
                            <a href="{{ route('usuarios.index') }}" class="nav-link text-white">Usuarios</a>
                        @endcan

                        @can('viewAny', \App\Models\TipoPrestamo::class)
                            <a href="{{ route('tipos-prestamo.index') }}" class="nav-link text-white">Modalidades</a>
                        @endcan

                        @can('viewAny', \App\Models\PresupuestoAnual::class)
                            <a href="{{ route('presupuestos.index') }}" class="nav-link text-white">Presupuesto</a>
                        @endcan

                        @can('viewAny', \App\Models\SmmlvAnual::class)
                            <a href="{{ route('smmlv.index') }}" class="nav-link text-white">SMMLV</a>
                        @endcan

                        @can('viewAny', \App\Models\SolicitudCredito::class)
                            <a href="{{ route('solicitudes.index') }}" class="nav-link text-white">Solicitudes</a>
                        @endcan

                        @can('viewAny', \App\Models\Prestamo::class)
                            <a href="{{ route('prestamos.index') }}" class="nav-link text-white">Préstamos</a>
                        @endcan

                        @can('viewAny', \App\Models\Proveedor::class)
                            <a href="{{ route('proveedores.index') }}" class="nav-link text-white">Proveedores</a>
                        @endcan

                        <a href="{{ route('password.edit') }}" class="nav-link text-white">Mi contraseña</a>

                        <span class="text-white-50">{{ auth()->user()->nombre_completo }}</span>

                        <form method="POST" action="{{ route('logout') }}" class="mb-0">
                            @csrf
                            <button type="submit" class="btn btn-outline-light btn-sm">Salir</button>
                        </form>
                    </div>
                @endauth
            </div>
        </nav>

        <main class="container pb-5">
            @if (session('status'))
                <div class="alert alert-success">{{ session('status') }}</div>
            @endif

            @if (session('error'))
                <div class="alert alert-danger">{{ session('error') }}</div>
            @endif

            @yield('content')
        </main>
    </body>
</html>
