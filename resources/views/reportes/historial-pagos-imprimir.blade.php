<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Historial de pagos — Bienestar Préstamos</title>
        @vite(['resources/css/app.css'])
        <link rel="stylesheet" href="{{ asset('css/tema-institucional.css') }}">
        <style>
            @if ($tipo === 'individual')
            @page { size: A4 portrait; margin: 10mm; }
            @else
            @page { size: A4 landscape; margin: 10mm; }
            @endif

            body { background: #fff; font-size: 0.8rem; }

            @media print {
                .impresion-historial { font-size: 7.5pt; }
                .impresion-historial .container-fluid { padding: 0 !important; }
                .impresion-historial .table-responsive { overflow: visible !important; }

                .impresion-historial table {
                    width: 100%;
                    table-layout: fixed;
                    border-collapse: collapse;
                }

                /* Bordes finos en vez de colores de fondo: se lee igual con «gráficos de fondo» apagado. */
                .impresion-historial th,
                .impresion-historial td {
                    padding: 1px 3px !important;
                    border: 0.5pt solid #555 !important;
                    background: transparent !important;
                    box-shadow: none !important;
                    white-space: normal;
                    overflow-wrap: anywhere;
                    vertical-align: top;
                }

                .impresion-historial th { font-weight: 700; }

                /* El encabezado se repite en cada hoja y ninguna fila se parte entre dos. */
                .impresion-historial thead { display: table-header-group; }
                .impresion-historial tfoot { display: table-footer-group; }
                .impresion-historial tr { page-break-inside: avoid; break-inside: avoid; }

                .impresion-historial .card { border: 0.5pt solid #555; }
                .impresion-historial .card-body { padding: 4px 6px; }
                .impresion-historial h1 { font-size: 14pt; }
                .impresion-historial h2 { font-size: 10pt; margin-bottom: 3px; }

                /* Anchos de las 15 columnas de la Q y la de créditos (suman 100 %). */
                .impresion-historial .historial-q th:nth-child(1) { width: 6.5%; }
                .impresion-historial .historial-q th:nth-child(2) { width: 10.5%; }
                .impresion-historial .historial-q th:nth-child(3) { width: 7.5%; }
                .impresion-historial .historial-q th:nth-child(4) { width: 7.5%; }
                .impresion-historial .historial-q th:nth-child(5) { width: 6.5%; }
                .impresion-historial .historial-q th:nth-child(6) { width: 6%; }
                .impresion-historial .historial-q th:nth-child(7) { width: 4%; }
                .impresion-historial .historial-q th:nth-child(8) { width: 4%; }
                .impresion-historial .historial-q th:nth-child(9) { width: 6.5%; }
                .impresion-historial .historial-q th:nth-child(10) { width: 4%; }
                .impresion-historial .historial-q th:nth-child(11) { width: 4.5%; }
                .impresion-historial .historial-q th:nth-child(12) { width: 6%; }
                .impresion-historial .historial-q th:nth-child(13) { width: 4%; }
                .impresion-historial .historial-q th:nth-child(14) { width: 4.5%; }
                .impresion-historial .historial-q th:nth-child(15) { width: 6.5%; }
                .impresion-historial .historial-q th:nth-child(16) { width: 12%; }
            }
        </style>
    </head>
    <body class="impresion-historial impresion-{{ $tipo }}">
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
