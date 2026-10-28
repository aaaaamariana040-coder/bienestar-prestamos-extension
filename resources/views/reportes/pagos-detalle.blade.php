@extends('layouts.app')

@section('content')
    @php
        $volver = route('reportes.pagos', array_filter(['desde' => $desde, 'hasta' => $hasta]));
        $dinero = fn ($valor): string => '$'.number_format((float) $valor, 2, ',', '.');
        $generadaEn = now();
    @endphp

    {{--
        Detalle de una modalidad del Resumen de pagos. Los estilos de impresión van aquí (como en la hoja
        del simulador y la del préstamo): la hoja vive en .solo-impresion, oculta en pantalla, y es lo único
        que se imprime. A4 horizontal por las nueve columnas.
    --}}
    <style>
        @page detalle {
            size: A4 landscape;
            margin: 12mm 12mm 20mm;
        }

        @media print {
            .hoja-detalle {
                page: detalle;
                font-size: 9pt;
                line-height: 1.35;
                color: #000;
            }

            .hoja-detalle h1,
            .hoja-detalle h2,
            .hoja-detalle p {
                margin: 0;
            }

            .hoja-encabezado {
                display: flex;
                align-items: center;
                gap: 12px;
                padding-bottom: 6px;
                border-bottom: 1.5pt solid #000;
            }

            .hoja-institucion {
                font-size: 12pt;
                font-weight: 700;
            }

            .hoja-area {
                font-size: 9pt;
            }

            .hoja-titulo {
                margin: 8px 0 4px !important;
                font-size: 15pt;
                text-align: center;
            }

            .hoja-subtitulo {
                margin-bottom: 6px !important;
                text-align: center;
                font-size: 10pt;
            }

            .hoja-tabla {
                width: 100%;
                border-collapse: collapse;
            }

            .hoja-tabla th,
            .hoja-tabla td {
                padding: 1px 4px;
                border: 0.5pt solid #555;
                font-size: 8pt;
                text-align: right;
            }

            .hoja-tabla th:nth-child(-n+5),
            .hoja-tabla td:nth-child(-n+5) {
                text-align: left;
            }

            .hoja-tabla th {
                font-weight: 700;
            }

            .hoja-tabla tfoot td {
                font-weight: 700;
            }

            /* Encabezado repetido en cada página y ninguna fila partida entre dos. */
            .hoja-tabla thead {
                display: table-header-group;
            }

            .hoja-tabla tr {
                break-inside: avoid;
            }

            /* Pie fijo: se repite en todas las páginas. */
            .hoja-pie {
                position: fixed;
                right: 0;
                bottom: 0;
                left: 0;
                padding-top: 3px;
                border-top: 0.5pt solid #000;
                font-size: 8pt;
                text-align: center;
                background: #fff;
            }
        }
    </style>

    <div class="no-imprimir">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h1 class="h3 mb-0">Detalle de pagos — {{ $modalidad->nombre }}</h1>
            <div class="d-flex gap-2">
                <button type="button" id="imprimir-detalle" class="btn btn-outline-primary" onclick="window.print()">Imprimir</button>
                <a href="{{ $volver }}" id="volver-resumen" class="btn btn-outline-secondary">Volver al resumen</a>
            </div>
        </div>

        <p class="text-muted" id="linea-detalle">Modalidad: <strong>{{ $modalidad->nombre }}</strong> · Periodo: {{ $periodo }}.</p>

        @if ($ofreceBusqueda)
            <form method="GET" action="{{ route('reportes.pagos.detalle', $modalidad) }}" id="filtros-detalle" class="row g-2 align-items-end mb-3">
                <input type="hidden" name="desde" value="{{ $desde }}">
                <input type="hidden" name="hasta" value="{{ $hasta }}">
                <div class="col-md-5">
                    <label for="q" class="form-label">Buscar por cédula o nombre</label>
                    <input type="search" id="q" name="q" class="form-control" value="{{ $busqueda }}" placeholder="Ej. 1000000009 o Solicitud" autocomplete="off">
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Buscar</button>
                </div>
            </form>
        @endif

        <div id="resultados-detalle">
            <div class="table-responsive">
                <table class="table table-striped bg-white align-middle">
                    <thead>
                        <tr>
                            <th>Empleado</th>
                            <th>Cédula</th>
                            <th>N.º de préstamo</th>
                            <th>Estado</th>
                            <th class="text-end">Monto solicitado</th>
                            <th class="text-end">N.º de cuotas</th>
                            <th class="text-end">Valor de la cuota</th>
                            <th>Cuotas pagadas</th>
                            <th class="text-end">Total pagado en el periodo</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($filas as $fila)
                            <tr>
                                <td>{{ $fila->empleado }}</td>
                                <td>{{ $fila->cedula }}</td>
                                <td><a href="{{ route('prestamos.show', $fila->prestamo) }}">#{{ $fila->prestamo->id_prestamo }}</a></td>
                                <td>{{ $fila->prestamo->estado->value }}</td>
                                <td class="text-end">{{ $dinero($fila->monto_solicitado) }}</td>
                                <td class="text-end">{{ $fila->prestamo->num_cuotas }}</td>
                                <td class="text-end">{{ $dinero($fila->prestamo->cuota_periodica) }}</td>
                                <td>{{ $fila->prestamo->progreso_cuotas }}</td>
                                <td class="text-end">{{ $dinero($fila->total_pagado) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="text-center text-muted">{{ $hayFilas ? 'No hay préstamos que coincidan con la búsqueda' : 'No hay pagos de esta modalidad en el periodo seleccionado.' }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if ($filas->isNotEmpty())
                        <tfoot>
                            <tr class="fw-bold" id="totales-detalle">
                                <td colspan="4">Totales: {{ $totales->prestamos }} {{ $totales->prestamos === 1 ? 'préstamo' : 'préstamos' }}</td>
                                <td class="text-end">{{ $dinero($totales->monto_solicitado) }}</td>
                                <td colspan="3"></td>
                                <td class="text-end">{{ $dinero($totales->total_pagado) }}</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </div>
    </div>

    <article class="hoja-detalle solo-impresion" id="hoja-detalle">
        <header class="hoja-encabezado">
            <x-logo :alto="64" />
            <div>
                <p class="hoja-institucion">Politécnico Colombiano Jaime Isaza Cadavid</p>
                <p class="hoja-area">Bienestar Social — Préstamos para el personal</p>
            </div>
        </header>

        <h1 class="hoja-titulo">Resumen de pagos — detalle de la modalidad</h1>
        <p class="hoja-subtitulo">Modalidad: <strong>{{ $modalidad->nombre }}</strong> · Periodo: {{ $periodo }}.{{ $busqueda !== '' ? ' Búsqueda: «'.$busqueda.'».' : '' }}</p>

        <table class="hoja-tabla">
            <thead>
                <tr>
                    <th>Empleado</th>
                    <th>Cédula</th>
                    <th>N.º de préstamo</th>
                    <th>Estado</th>
                    <th>Monto solicitado</th>
                    <th>N.º de cuotas</th>
                    <th>Valor de la cuota</th>
                    <th>Cuotas pagadas</th>
                    <th>Total pagado en el periodo</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($filas as $fila)
                    <tr>
                        <td>{{ $fila->empleado }}</td>
                        <td>{{ $fila->cedula }}</td>
                        <td>#{{ $fila->prestamo->id_prestamo }}</td>
                        <td>{{ $fila->prestamo->estado->value }}</td>
                        <td>{{ $dinero($fila->monto_solicitado) }}</td>
                        <td>{{ $fila->prestamo->num_cuotas }}</td>
                        <td>{{ $dinero($fila->prestamo->cuota_periodica) }}</td>
                        <td>{{ $fila->prestamo->progreso_cuotas }}</td>
                        <td>{{ $dinero($fila->total_pagado) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9">{{ $hayFilas ? 'No hay préstamos que coincidan con la búsqueda' : 'No hay pagos de esta modalidad en el periodo seleccionado.' }}</td></tr>
                @endforelse
            </tbody>
            @if ($filas->isNotEmpty())
                <tfoot>
                    <tr>
                        <td colspan="4">Totales: {{ $totales->prestamos }} {{ $totales->prestamos === 1 ? 'préstamo' : 'préstamos' }}</td>
                        <td>{{ $dinero($totales->monto_solicitado) }}</td>
                        <td colspan="3"></td>
                        <td>{{ $dinero($totales->total_pagado) }}</td>
                    </tr>
                </tfoot>
            @endif
        </table>

        <footer class="hoja-pie">
            <p>Impreso el {{ $generadaEn->format('d/m/Y H:i') }} por {{ auth()->user()->nombre_completo }}.</p>
        </footer>
    </article>

    @if ($ofreceBusqueda)
        <script>
            (function () {
                const formulario = document.getElementById('filtros-detalle');
                const busqueda = document.getElementById('q');
                let temporizador = null;
                let peticion = null;

                function filtrar() {
                    const parametros = new URLSearchParams(new FormData(formulario));

                    for (const [clave, valor] of Array.from(parametros)) {
                        if (valor.trim() === '') {
                            parametros.delete(clave);
                        }
                    }

                    const url = formulario.action + (parametros.toString() ? '?' + parametros.toString() : '');

                    if (peticion) {
                        peticion.abort();
                    }
                    peticion = new AbortController();

                    fetch(url, { signal: peticion.signal, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                        .then(function (respuesta) {
                            if (! respuesta.ok) {
                                throw new Error('Respuesta inesperada');
                            }

                            return respuesta.text();
                        })
                        .then(function (html) {
                            const documento = new DOMParser().parseFromString(html, 'text/html');

                            document.getElementById('resultados-detalle').innerHTML = documento.getElementById('resultados-detalle').innerHTML;
                            document.getElementById('hoja-detalle').innerHTML = documento.getElementById('hoja-detalle').innerHTML;
                            history.replaceState(null, '', url);
                        })
                        .catch(function (error) {
                            if (error.name !== 'AbortError') {
                                formulario.submit();
                            }
                        });
                }

                busqueda.addEventListener('input', function () {
                    clearTimeout(temporizador);
                    temporizador = setTimeout(filtrar, 300);
                });
            })();
        </script>
    @endif
@endsection
