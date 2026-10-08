@extends('layouts.app')

@section('content')
    <h1 class="h3 mb-4">Registrar solicitud de crédito</h1>

    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $mensaje)
                    <li>{{ $mensaje }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('solicitudes.store') }}">
        @csrf

        <div class="row">
            <div class="col-md-6 mb-3">
                <label for="cedula_empleado" class="form-label">Cédula del empleado</label>
                <input type="text" id="cedula_empleado" name="cedula_empleado" class="form-control"
                       value="{{ old('cedula_empleado') }}" inputmode="numeric" maxlength="10" pattern="[0-9]{1,10}"
                       title="Solo números, máximo 10 dígitos" autocomplete="off" placeholder="Escribe la cédula" required>
                <div id="resultado-empleado" class="mt-2" aria-live="polite"></div>
            </div>

            <div class="col-md-6 mb-3">
                <label for="id_tipo" class="form-label">Modalidad</label>
                <select id="id_tipo" name="id_tipo" class="form-select" required>
                    <option value="">Selecciona una modalidad…</option>
                    @foreach ($tiposPrestamo as $tipoPrestamo)
                        <option value="{{ $tipoPrestamo->id_tipo }}" @selected((string) old('id_tipo') === (string) $tipoPrestamo->id_tipo)>
                            {{ $tipoPrestamo->nombre }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="col-md-4 mb-3">
                <label for="monto_solicitado" class="form-label">Monto solicitado</label>
                <input type="text" inputmode="decimal" id="monto_solicitado" name="monto_solicitado" class="form-control"
                       value="{{ old('monto_solicitado') }}" required>
            </div>

            <div class="col-md-4 mb-3">
                <label for="plazo_solicitado" class="form-label">Plazo (quincenas)</label>
                <input type="number" min="1" id="plazo_solicitado" name="plazo_solicitado" class="form-control"
                       value="{{ old('plazo_solicitado') }}" required>
            </div>

            <div class="col-md-4 mb-3">
                <label for="fecha_solicitud" class="form-label">Fecha de solicitud</label>
                <input type="date" id="fecha_solicitud" name="fecha_solicitud" class="form-control"
                       value="{{ old('fecha_solicitud', now()->toDateString()) }}" required>
            </div>
        </div>

        <button type="submit" id="registrar-solicitud" class="btn btn-primary">Registrar</button>
        <a href="{{ route('solicitudes.index') }}" class="btn btn-outline-secondary">Cancelar</a>
    </form>

    <script>
        (function () {
            const campo = document.getElementById('cedula_empleado');
            const resultado = document.getElementById('resultado-empleado');
            const boton = document.getElementById('registrar-solicitud');
            const urlBase = @json(url('solicitudes/empleado'));
            const urlCrear = @json(route('empleados.create'));
            let temporizador = null;
            let peticion = null;

            function escapar(texto) {
                const nodo = document.createElement('div');
                nodo.textContent = texto ?? '—';

                return nodo.innerHTML;
            }

            function mostrarEmpleado(e) {
                resultado.innerHTML = '<div class="card border-success"><div class="card-body py-2">'
                    + '<div><strong>' + escapar(e.nombre_completo) + '</strong></div>'
                    + '<dl class="row mb-0 small">'
                    + '<dt class="col-sm-5">Cédula</dt><dd class="col-sm-7">' + escapar(e.cedula) + '</dd>'
                    + '<dt class="col-sm-5">Dependencia</dt><dd class="col-sm-7">' + escapar(e.dependencia) + '</dd>'
                    + '<dt class="col-sm-5">Salario</dt><dd class="col-sm-7">$' + escapar(e.salario) + '</dd>'
                    + '<dt class="col-sm-5">Capacidad de endeudamiento</dt><dd class="col-sm-7">$' + escapar(e.capacidad_endeudamiento) + '</dd>'
                    + '</dl></div></div>';
                boton.disabled = false;
            }

            function mostrarMensaje(mensaje, ofrecerCrear) {
                resultado.innerHTML = '<div class="alert alert-warning py-2 mb-0">' + escapar(mensaje)
                    + (ofrecerCrear ? ' <a href="' + urlCrear + '" target="_blank" rel="noopener" class="alert-link">Crear empleado</a>' : '')
                    + '</div>';
                boton.disabled = true;
            }

            function buscar() {
                const cedula = campo.value.replace(/\s+/g, '');

                if (peticion) {
                    peticion.abort();
                }

                if (cedula === '') {
                    resultado.innerHTML = '';
                    boton.disabled = true;

                    return;
                }

                if (! /^[0-9]{1,10}$/.test(cedula)) {
                    mostrarMensaje('La cédula debe contener solo números, máximo 10 dígitos.', false);

                    return;
                }

                peticion = new AbortController();

                fetch(urlBase + '/' + cedula, { signal: peticion.signal, headers: { 'Accept': 'application/json' } })
                    .then(function (respuesta) {
                        return respuesta.json().then(function (datos) {
                            return { ok: respuesta.ok, datos: datos };
                        });
                    })
                    .then(function (r) {
                        if (r.ok && r.datos.encontrado) {
                            mostrarEmpleado(r.datos);
                        } else {
                            mostrarMensaje(r.datos.mensaje || 'No se pudo consultar el empleado.', ! r.datos.inactivo);
                        }
                    })
                    .catch(function (error) {
                        if (error.name !== 'AbortError') {
                            mostrarMensaje('No se pudo consultar el empleado. Inténtalo de nuevo.', false);
                        }
                    });
            }

            campo.addEventListener('input', function () {
                clearTimeout(temporizador);
                boton.disabled = true;
                temporizador = setTimeout(buscar, 250);
            });

            boton.disabled = true;

            if (campo.value !== '') {
                buscar();
            }
        })();
    </script>
@endsection
