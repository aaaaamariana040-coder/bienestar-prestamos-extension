@extends('layouts.app')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">Empleados</h1>
        @can('empleados.gestionar')
            <a href="{{ route('empleados.create') }}" class="btn btn-primary">Nuevo empleado</a>
        @endcan
    </div>

    <form method="GET" action="{{ route('empleados.index') }}" id="filtros-empleados" class="row g-2 align-items-end mb-3">
        <div class="col-md-6">
            <label for="q" class="form-label">Buscar por cédula o nombre</label>
            <input type="search" id="q" name="q" class="form-control" value="{{ $busqueda }}" placeholder="Ej. 1000000009 o Solicitud">
        </div>

        <div class="col-md-3">
            <label for="estado" class="form-label">Estado</label>
            <select id="estado" name="estado" class="form-select">
                <option value="" @selected($estado === '')>Todos</option>
                <option value="Activo" @selected($estado === 'Activo')>Activo</option>
                <option value="Retirado" @selected($estado === 'Retirado')>Retirado</option>
            </select>
        </div>

        <div class="col-md-3 d-flex gap-2">
            <button type="submit" class="btn btn-primary">Buscar</button>
        </div>
    </form>

    <div id="resultados-empleados">
    <table class="table table-striped bg-white">
        <thead>
            <tr>
                <th>Cédula</th>
                <th>Nombre</th>
                <th>Tipo</th>
                <th>Estado</th>
                <th>Salario</th>
                <th class="text-end">Acciones</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($empleados as $empleado)
                <tr>
                    <td>{{ $empleado->cedula }}</td>
                    <td>{{ $empleado->nombre_completo }}</td>
                    <td>{{ $empleado->tipo_vinculacion }}</td>
                    <td>{{ $empleado->estado }}</td>
                    <td>{{ number_format($empleado->salario, 2) }}</td>
                    <td class="text-end">
                        <a href="{{ route('empleados.show', $empleado) }}" class="btn btn-sm btn-outline-secondary">Ver</a>
                        @can('empleados.gestionar')
                            <a href="{{ route('empleados.edit', $empleado) }}" class="btn btn-sm btn-outline-secondary">Editar</a>
                        @endcan
                        @can('empleados.eliminar')
                            <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#modal-confirmar-eliminacion"
                                    data-eliminar-action="{{ route('empleados.destroy', $empleado) }}"
                                    data-eliminar-nombre="{{ $empleado->nombre_completo }} (cédula {{ $empleado->cedula }})">Eliminar</button>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="text-center text-muted">{{ $busqueda !== '' || $estado !== '' ? 'Ningún empleado coincide con la búsqueda.' : 'No hay empleados registrados.' }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    {{ $empleados->links() }}
    </div>

    <x-confirmar-eliminacion />

    <script>
        (function () {
            const formulario = document.getElementById('filtros-empleados');
            const busqueda = document.getElementById('q');
            const estado = document.getElementById('estado');
            let temporizador = null;
            let peticion = null;

            function filtrar() {
                const parametros = new URLSearchParams(new FormData(formulario));

                for (const [clave, valor] of Array.from(parametros)) {
                    if (valor === '') {
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
                        const nuevo = new DOMParser().parseFromString(html, 'text/html').getElementById('resultados-empleados');

                        document.getElementById('resultados-empleados').innerHTML = nuevo.innerHTML;
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

            estado.addEventListener('change', function () {
                clearTimeout(temporizador);
                filtrar();
            });
        })();
    </script>
@endsection
