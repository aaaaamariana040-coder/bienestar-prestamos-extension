@extends('layouts.app')

@section('content')
    <h1 class="h3 mb-2">Préstamos</h1>
    <p class="text-muted mb-4" id="ayuda-pagos">Para registrar un pago, un abono o una cancelación, busque al empleado y entre a «Ver» en su préstamo.</p>

    <form method="GET" action="{{ route('prestamos.index') }}" id="filtros-prestamos" class="row g-2 align-items-end mb-3">
        <div class="col-md-5">
            <label for="q" class="form-label">Buscar por cédula o nombre del empleado</label>
            <input type="search" id="q" name="q" class="form-control" value="{{ $busqueda }}" placeholder="Ej. 1000000009 o Solicitud" autocomplete="off">
        </div>

        <div class="col-md-3 d-flex gap-2">
            <button type="submit" class="btn btn-primary">Buscar</button>
        </div>
    </form>

    <div id="resultados-prestamos">
    <table class="table table-striped bg-white align-middle">
        <thead>
            <tr>
                <th>Empleado</th>
                <th>Modalidad</th>
                <th>Monto aprobado</th>
                <th>Cuotas</th>
                <th>Fecha de autorización</th>
                <th>Estado</th>
                <th class="text-end">Acciones</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($prestamos as $prestamo)
                <tr>
                    <td>{{ $prestamo->empleado->nombre_completo }}</td>
                    <td>{{ $prestamo->tipoPrestamo->nombre }}</td>
                    <td>{{ number_format($prestamo->monto_aprobado, 2) }}</td>
                    <td>{{ \App\Models\TipoPrestamo::plazoEnQuincenas($prestamo->num_cuotas) }}</td>
                    <td>{{ $prestamo->fecha_autorizacion->format('Y-m-d') }}</td>
                    <td>{{ $prestamo->estado->value }}</td>
                    <td class="text-end">
                        <a href="{{ route('prestamos.show', $prestamo) }}" class="btn btn-sm btn-outline-secondary">Ver</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="text-center text-muted">{{ $busqueda !== '' ? 'No hay préstamos que coincidan con la búsqueda' : 'No hay préstamos registrados.' }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>
    </div>

    <script>
        (function () {
            const formulario = document.getElementById('filtros-prestamos');
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
                        const nuevo = new DOMParser().parseFromString(html, 'text/html').getElementById('resultados-prestamos');

                        document.getElementById('resultados-prestamos').innerHTML = nuevo.innerHTML;
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
@endsection
