<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $title ?? config('app.name', 'Bienestar Préstamos') }}</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        <link rel="stylesheet" href="{{ asset('css/tema-institucional.css') }}">
    </head>
    <body class="bg-light">
        <header class="navbar navbar-dark navbar-institucional barra-superior">
            <div class="d-flex align-items-center gap-2">
                @auth
                    <button type="button" id="alternar-menu" class="btn btn-sm btn-alternar-menu" aria-label="Mostrar u ocultar el menú" aria-controls="menu-lateral">
                        <x-icono nombre="menu" />
                    </button>
                @endauth

                <a class="navbar-brand d-flex align-items-center gap-2 mb-0" href="{{ route('inicio') }}">
                    <x-logo :alto="44" barra />
                    <span>Bienestar Préstamos</span>
                </a>
            </div>

            @auth
                <div class="d-flex align-items-center gap-3">
                    <span class="text-white">{{ auth()->user()->nombre_completo }}</span>
                    <a href="{{ route('password.edit') }}" class="nav-link text-white d-none d-md-inline">Mi contraseña</a>

                    <form method="POST" action="{{ route('logout') }}" class="mb-0">
                        @csrf
                        <button type="submit" class="btn btn-outline-light btn-sm">Salir</button>
                    </form>
                </div>
            @endauth
        </header>

        <div class="contenedor-app">
            @auth
                <aside id="menu-lateral" class="menu-lateral">
                    @include('layouts.partials.menu-lateral')
                </aside>
                <div class="menu-fondo" id="menu-fondo"></div>
            @endauth

            <main class="contenido-principal">
                <div class="contenido-ancho">
                    @if (session('status'))
                        <div class="alert alert-success">{{ session('status') }}</div>
                    @endif

                    @if (session('error'))
                        <div class="alert alert-danger">{{ session('error') }}</div>
                    @endif

                    @yield('content')
                </div>
            </main>
        </div>

        @auth
            <script>
                (function () {
                    const cuerpo = document.body;
                    const boton = document.getElementById('alternar-menu');
                    const fondo = document.getElementById('menu-fondo');
                    const esEscritorio = window.matchMedia('(min-width: 992px)');
                    const clave = 'menu-colapsado';

                    function guardado() {
                        try {
                            return window.localStorage.getItem(clave) === '1';
                        } catch (e) {
                            return false;
                        }
                    }

                    function guardar(colapsado) {
                        try {
                            window.localStorage.setItem(clave, colapsado ? '1' : '0');
                        } catch (e) {
                            // Sin almacenamiento: el menú simplemente no recuerda su estado.
                        }
                    }

                    function colapsar(colapsado) {
                        cuerpo.classList.toggle('menu-colapsado', colapsado);
                        guardar(colapsado);
                    }

                    if (esEscritorio.matches && guardado()) {
                        cuerpo.classList.add('menu-colapsado');
                    }

                    boton.addEventListener('click', function () {
                        if (esEscritorio.matches) {
                            colapsar(! cuerpo.classList.contains('menu-colapsado'));
                        } else {
                            cuerpo.classList.toggle('menu-abierto');
                        }
                    });

                    fondo.addEventListener('click', function () {
                        cuerpo.classList.remove('menu-abierto');
                    });

                    // En modo de solo íconos, tocar un grupo expande el menú para ver sus submenús.
                    document.querySelectorAll('.menu-grupo-titulo[data-bs-toggle="collapse"]').forEach(function (titulo) {
                        titulo.addEventListener('click', function () {
                            if (esEscritorio.matches && cuerpo.classList.contains('menu-colapsado')) {
                                colapsar(false);
                            }
                        });
                    });

                    esEscritorio.addEventListener('change', function () {
                        cuerpo.classList.remove('menu-abierto');
                        cuerpo.classList.toggle('menu-colapsado', esEscritorio.matches && guardado());
                    });
                })();
            </script>
        @endauth
    </body>
</html>
