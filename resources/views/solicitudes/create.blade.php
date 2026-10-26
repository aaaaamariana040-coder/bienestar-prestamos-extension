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

    <form method="POST" action="{{ route('solicitudes.store') }}" enctype="multipart/form-data">
        @csrf

        <div class="row">
            <div class="col-md-6 mb-3">
                <label for="cedula_empleado" class="form-label">Cédula del empleado</label>
                <x-buscador-cedula :url="url('solicitudes/empleado')" id="cedula_empleado" name="cedula_empleado"
                                   :valor="old('cedula_empleado', $previos['cedula_empleado'])" requerido />
            </div>

            <div class="col-md-6 mb-3">
                <label for="id_tipo" class="form-label">Modalidad</label>
                <select id="id_tipo" name="id_tipo" class="form-select" required>
                    <option value="">Selecciona una modalidad…</option>
                    @foreach ($tiposPrestamo as $tipoPrestamo)
                        <option value="{{ $tipoPrestamo->id_tipo }}" @selected((string) old('id_tipo', $previos['id_tipo']) === (string) $tipoPrestamo->id_tipo)>
                            {{ $tipoPrestamo->nombre }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="col-md-4 mb-3">
                <label for="monto_solicitado" class="form-label">Monto solicitado</label>
                <input type="text" inputmode="decimal" id="monto_solicitado" name="monto_solicitado" class="form-control"
                       value="{{ old('monto_solicitado', $previos['monto_solicitado']) }}" required>
            </div>

            <div class="col-md-4 mb-3">
                <label for="plazo_solicitado" class="form-label">Plazo (quincenas)</label>
                <input type="number" min="1" id="plazo_solicitado" name="plazo_solicitado" class="form-control"
                       value="{{ old('plazo_solicitado', $previos['plazo_solicitado'] === '0' ? '' : $previos['plazo_solicitado']) }}" required>
            </div>

            <div class="col-md-4 mb-3">
                <label for="fecha_solicitud" class="form-label">Fecha de solicitud</label>
                <input type="date" id="fecha_solicitud" name="fecha_solicitud" class="form-control"
                       value="{{ old('fecha_solicitud', now()->toDateString()) }}" required>
            </div>
        </div>

        <fieldset class="border rounded p-3 mb-3">
            <legend class="float-none w-auto px-2 fs-6">Documentos (opcional)</legend>
            <p class="small text-muted mb-2">Puedes adjuntarlos ahora o después, desde el detalle de la solicitud. Solo PDF, JPG o PNG; máximo 5 MB por archivo y 15 por solicitud.</p>
            <div class="row">
                <div class="col-md-4 mb-2">
                    <label for="id_tipo_documento" class="form-label">Tipo de documento</label>
                    <select id="id_tipo_documento" name="id_tipo_documento" class="form-select">
                        <option value="">Selecciona un tipo…</option>
                        @foreach ($tiposDocumento as $tipoDocumento)
                            <option value="{{ $tipoDocumento->id_tipo_documento }}" @selected((string) old('id_tipo_documento') === (string) $tipoDocumento->id_tipo_documento)>{{ $tipoDocumento->nombre }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-8 mb-2">
                    <label for="archivos" class="form-label">Archivos</label>
                    <input type="file" id="archivos" name="archivos[]" class="form-control" multiple accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png">
                </div>
            </div>
        </fieldset>

        <button type="submit" id="registrar-solicitud" class="btn btn-primary">Registrar</button>
        <a href="{{ route('solicitudes.index') }}" class="btn btn-outline-secondary">Cancelar</a>
    </form>

    <script>
        (function () {
            const campo = document.getElementById('cedula_empleado');
            const resultado = document.getElementById('resultado-empleado');
            const boton = document.getElementById('registrar-solicitud');
            const urlCrear = @json(route('empleados.create'));

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
                    + '<dt class="col-sm-5">Capacidad de endeudamiento</dt><dd class="col-sm-7">' + (e.capacidad_aplicada === null ? 'No se puede calcular' : '$' + escapar(e.capacidad_aplicada)) + (e.capacidad_fuente === 'calculada' ? '<div class="text-muted">Guardada (referencia): $' + escapar(e.capacidad_endeudamiento) + '</div>' : '') + '</dd>'
                    + '</dl></div></div>';
                boton.disabled = false;
            }

            function mostrarMensaje(mensaje, ofrecerCrear) {
                resultado.innerHTML = '<div class="alert alert-warning py-2 mb-0">' + escapar(mensaje)
                    + (ofrecerCrear ? ' <a href="' + urlCrear + '" target="_blank" rel="noopener" class="alert-link">Crear empleado</a>' : '')
                    + '</div>';
                boton.disabled = true;
            }

            campo.addEventListener('cedula:escribiendo', function () {
                boton.disabled = true;
            });

            campo.addEventListener('cedula:vacia', function () {
                resultado.innerHTML = '';
                boton.disabled = true;
            });

            campo.addEventListener('cedula:invalida', function (evento) {
                mostrarMensaje(evento.detail.mensaje, false);
            });

            campo.addEventListener('cedula:encontrada', function (evento) {
                if (evento.detail.encontrado) {
                    mostrarEmpleado(evento.detail);
                } else {
                    mostrarMensaje(evento.detail.mensaje || 'No se pudo consultar el empleado.', ! evento.detail.inactivo);
                }
            });

            campo.addEventListener('cedula:error', function (evento) {
                mostrarMensaje(evento.detail.mensaje || 'No se pudo consultar el empleado.', ! evento.detail.inactivo);
            });

            boton.disabled = true;

            if (campo.value !== '') {
                campo.buscarAhora();
            }
        })();
    </script>
@endsection
