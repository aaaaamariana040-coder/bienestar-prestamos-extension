<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Historial de pagos — Bienestar Préstamos</title>
        @vite(['resources/css/app.css'])
        <link rel="stylesheet" href="{{ asset('css/tema-institucional.css') }}">
        <style>
            @page { size: landscape; margin: 1cm; }
            body { background: #fff; font-size: 0.8rem; }
        </style>
    </head>
    <body>
        <div class="container-fluid py-3">
            <div class="text-center mb-3">
                <x-logo :alto="80" />
                <h1 class="h3 mt-2 mb-0">Bienestar Préstamos</h1>
                <p class="mb-0">
                    Historial de pagos — Q{{ $quincena }} de {{ $anio }}
                    ({{ $historial->etiquetaQuincena($quincena) }}, cierre {{ $cierre->format('Y-m-d') }})
                </p>
            </div>

            <div class="no-imprimir text-center mb-3">
                <button type="button" class="btn btn-primary" onclick="window.print()">Imprimir o guardar como PDF</button>
            </div>

            @if ($tipo === 'individual')
                @php
                    $columnas = \App\Services\HistorialPagosService::COLUMNAS;
                @endphp
                @include('reportes.partials.historial-individual')
                @include('reportes.partials.historial-pagos-rango')
            @else
                @include('reportes.partials.historial-q-tabla')
            @endif
        </div>

        <script>
            window.addEventListener('load', function () {
                window.print();
            });
        </script>
    </body>
</html>
