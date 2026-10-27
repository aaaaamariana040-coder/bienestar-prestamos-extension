@extends('layouts.app')

@section('content')
    <div class="solo-impresion text-center mb-3">
        <x-logo :alto="80" />
        <h1 class="h3 mt-2 mb-0">Bienestar Préstamos</h1>
    </div>

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <h1 class="h3 mb-0">Historial de pagos</h1>
    </div>

    <form method="GET" action="{{ route('reportes.historial-pagos') }}" id="filtros-historial" class="row g-2 align-items-end mb-3 no-imprimir">
        <div class="col-6 col-md-2">
            <label for="anio" class="form-label">Año</label>
            <select id="anio" name="anio" class="form-select" onchange="this.form.requestSubmit()">
                @foreach (range(now()->year + 1, now()->year - 5) as $anioOpcion)
                    <option value="{{ $anioOpcion }}" @selected($anioOpcion === $anio)>{{ $anioOpcion }}</option>
                @endforeach
            </select>
        </div>

        <div class="col-6 col-md-3">
            <label for="quincena" class="form-label">Quincena</label>
            <select id="quincena" name="quincena" class="form-select" onchange="this.form.requestSubmit()">
                @foreach ($quincenas as $numero)
                    <option value="{{ $numero }}" @selected($numero === $quincena)>Q{{ $numero }} · {{ $historial->etiquetaQuincena($numero) }}</option>
                @endforeach
            </select>
        </div>

        <div class="col-md-3">
            <label for="cedula" class="form-label">Buscar por cédula</label>
            <x-buscador-cedula modo="filtro" id="cedula" name="cedula" :valor="$cedula" objetivo="resultados-historial" />
        </div>

        <div class="col-md-4 d-flex flex-wrap gap-2">
            <button type="submit" class="btn btn-primary">Buscar</button>

            <div class="dropdown" id="menu-reportes-descargas">
                <button type="button" class="btn btn-outline-secondary dropdown-toggle" id="boton-reportes-descargas"
                        data-bs-toggle="dropdown" aria-expanded="false">Reportes y descargas</button>
                <ul class="dropdown-menu dropdown-menu-md-end" aria-labelledby="boton-reportes-descargas"
                    style="max-width: calc(100vw - 2rem);">
                    <li>
                        <a class="dropdown-item text-wrap" id="resumen-prestamos" href="{{ route('reportes.prestamos') }}">Resumen de préstamos por modalidad y estado</a>
                    </li>
                    <li>
                        <a class="dropdown-item text-wrap" id="imprimir-q" target="_blank" rel="noopener"
                           href="{{ route('reportes.historial-pagos.imprimir', ['anio' => $anio, 'quincena' => $quincena, 'cedula' => $cedula !== '' ? $cedula : null]) }}">Imprimir o guardar la Q en PDF</a>
                    </li>
                    @if ($procesoQuincena !== null)
                        @can('view', $procesoQuincena)
                            <li>
                                <a class="dropdown-item text-wrap" id="descargar-informe-quincena" href="{{ route('quincenas.proceso.excel', $procesoQuincena) }}">Descargar informe de la quincena (CSV)</a>
                            </li>
                        @endcan
                    @else
                        @can('confirmar', \App\Models\ProcesoQuincena::class)
                            <li>
                                <button type="submit" class="dropdown-item text-wrap" id="descargar-informe-quincena" form="form-informe-quincena">Descargar informe de la quincena (CSV)</button>
                            </li>
                        @endcan
                    @endif
                </ul>
            </div>
        </div>
    </form>

    @if ($procesoQuincena === null)
        @can('confirmar', \App\Models\ProcesoQuincena::class)
            <form id="form-informe-quincena" method="POST" action="{{ route('quincenas.excel', [$anio, $quincena]) }}" class="d-none">
                @csrf
            </form>
        @endcan
    @endif

    <div id="resultados-historial">
        <p class="text-muted">
            <strong>Q{{ $quincena }} de {{ $anio }}</strong> ({{ $historial->etiquetaQuincena($quincena) }}, cierre {{ $cierre->format('Y-m-d') }}) ·
            {{ $empleados->total() }} {{ $empleados->total() === 1 ? 'empleado' : 'empleados' }}
        </p>

        @if ($columnasOcultas !== [])
            <p class="small text-muted columnas-sin-datos">Columnas sin datos aún: {{ implode(', ', $columnasOcultas) }} (se mostrarán cuando existan).</p>
        @endif

        @include('reportes.partials.historial-q-tabla')

        {{ $empleados->links() }}

        @if ($individual !== null)
            <hr>
            <section id="consulta-individual">
                @include('reportes.partials.historial-individual')

                @if ($individual['creditos'] !== [])
                    <form method="GET" action="{{ route('reportes.historial-pagos') }}" class="row g-2 align-items-end mb-3 no-imprimir" id="form-pagos-rango">
                        <input type="hidden" name="anio" value="{{ $anio }}">
                        <input type="hidden" name="quincena" value="{{ $quincena }}">
                        <input type="hidden" name="cedula" value="{{ $cedula }}">

                        <div class="col-md-4">
                            <label for="modalidad-individual" class="form-label">Modalidad</label>
                            <select id="modalidad-individual" name="modalidad" class="form-select">
                                <option value="">Elige una modalidad</option>
                                @foreach ($individual['modalidades'] as $modalidadOpcion)
                                    <option value="{{ $modalidadOpcion->id_tipo }}" @selected($individual['modalidad'] === $modalidadOpcion->id_tipo)>{{ $modalidadOpcion->nombre }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-md-5 row g-2" id="rango-fechas" @if ($individual['modalidad'] === null) hidden @endif>
                            <div class="col-6">
                                <label for="desde" class="form-label">Desde</label>
                                <input type="date" id="desde" name="desde" class="form-control" value="{{ $individual['desde'] }}">
                            </div>
                            <div class="col-6">
                                <label for="hasta" class="form-label">Hasta</label>
                                <input type="date" id="hasta" name="hasta" class="form-control" value="{{ $individual['hasta'] }}">
                            </div>
                        </div>

                        <div class="col-md-3 d-flex gap-2">
                            <button type="submit" class="btn btn-primary" id="confirmar-rango" @if ($individual['modalidad'] === null) hidden @endif>Ver pagos</button>
                        </div>
                    </form>

                    @if ($individual['errores'] !== [])
                        <div class="alert alert-danger" role="alert">
                            <ul class="mb-0">
                                @foreach ($individual['errores'] as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if ($individual['pagos'] !== null)
                        @include('reportes.partials.historial-pagos-rango')

                        <a href="{{ route('reportes.historial-pagos.imprimir', ['tipo' => 'individual', 'anio' => $anio, 'quincena' => $quincena, 'cedula' => $cedula, 'modalidad' => $individual['modalidad'], 'desde' => $individual['desde'], 'hasta' => $individual['hasta']]) }}"
                           target="_blank" rel="noopener" class="btn btn-outline-secondary no-imprimir" id="imprimir-individual">Imprimir o guardar el reporte individual en PDF</a>
                    @endif
                @endif
            </section>
        @endif
    </div>

    <script>
        // El rango de fechas aparece al elegir una modalidad. Se delega en el documento porque
        // el buscador en vivo reemplaza el contenido de #resultados-historial.
        document.addEventListener('change', function (evento) {
            if (evento.target.id !== 'modalidad-individual') {
                return;
            }

            const hayModalidad = evento.target.value !== '';

            document.getElementById('rango-fechas').hidden = ! hayModalidad;
            document.getElementById('confirmar-rango').hidden = ! hayModalidad;
        });

        // El enlace de impresión de la Q sigue a lo que hay escrito en el filtro.
        document.getElementById('cedula').addEventListener('input', function () {
            const enlace = document.getElementById('imprimir-q');
            const url = new URL(enlace.href);

            if (this.value !== '') {
                url.searchParams.set('cedula', this.value);
            } else {
                url.searchParams.delete('cedula');
            }

            enlace.href = url.toString();
        });
    </script>
@endsection
