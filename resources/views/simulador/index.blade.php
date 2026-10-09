@extends('layouts.app')

@section('content')
    <h1 class="h3 mb-1">Simulador de crédito</h1>
    <p class="text-muted mb-4">Consulta cuánto podría pedir un empleado en cada modalidad. No registra ninguna solicitud.</p>

    <div class="row">
        <div class="col-md-6 mb-3">
            <label for="cedula_empleado" class="form-label">Cédula del empleado</label>
            <x-buscador-cedula :url="url('simulador/empleado')" id="cedula_empleado" />
        </div>
    </div>

    <div id="seccion-empleado" class="d-none">
        <div class="card mb-4">
            <div class="card-body py-3">
                <h2 class="h5 mb-3" id="empleado-nombre"></h2>
                <dl class="row mb-0">
                    <dt class="col-sm-3">Cédula</dt><dd class="col-sm-9" id="empleado-cedula"></dd>
                    <dt class="col-sm-3">Salario</dt><dd class="col-sm-9" id="empleado-salario"></dd>
                    <dt class="col-sm-3">Estado</dt><dd class="col-sm-9" id="empleado-estado"></dd>
                </dl>
            </div>
        </div>

        <div id="seccion-elegible" class="d-none">
            <div id="seccion-prestamos" class="mb-4 d-none">
                <h2 class="h5">Préstamos vigentes</h2>
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle">
                        <thead>
                            <tr>
                                <th>Modalidad</th>
                                <th class="text-end">Monto desembolsado</th>
                                <th class="text-end">Saldo pendiente</th>
                                <th class="text-end">Cuota quincenal</th>
                                <th>Cuotas pagadas</th>
                            </tr>
                        </thead>
                        <tbody id="tabla-prestamos"></tbody>
                    </table>
                </div>
            </div>

            <h2 class="h5">Capacidad de endeudamiento</h2>
            <div class="row g-3 mb-4">
                <div class="col-md-4"><div class="card"><div class="card-body py-2">
                    <div class="small text-muted">Total</div><div class="fs-5" id="capacidad-total"></div>
                </div></div></div>
                <div class="col-md-4"><div class="card"><div class="card-body py-2">
                    <div class="small text-muted">Comprometida (cuotas vigentes)</div><div class="fs-5" id="capacidad-comprometida"></div>
                </div></div></div>
                <div class="col-md-4"><div class="card"><div class="card-body py-2">
                    <div class="small text-muted">Disponible</div><div class="fs-5 fw-semibold" id="capacidad-disponible"></div>
                </div></div></div>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="modalidad" class="form-label">Modalidad</label>
                    <select id="modalidad" class="form-select">
                        <option value="">Selecciona una modalidad…</option>
                    </select>
                </div>
            </div>

            <div id="resultado-modalidad" aria-live="polite"></div>
        </div>
    </div>

    <script>
        (function () {
            const campo = document.getElementById('cedula_empleado');
            const resultado = document.getElementById('resultado-empleado');
            const seccionEmpleado = document.getElementById('seccion-empleado');
            const seccionElegible = document.getElementById('seccion-elegible');
            const seccionPrestamos = document.getElementById('seccion-prestamos');
            const selector = document.getElementById('modalidad');
            const resultadoModalidad = document.getElementById('resultado-modalidad');
            const urlEmpleado = @json(url('simulador/empleado'));
            let cedulaActual = null;
            let peticionModalidad = null;

            const pesos = new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 });

            function dinero(valor) {
                return pesos.format(valor);
            }

            function texto(id, valor) {
                document.getElementById(id).textContent = valor ?? '—';
            }

            function escapar(valor) {
                const nodo = document.createElement('div');
                nodo.textContent = valor ?? '—';

                return nodo.innerHTML;
            }

            function limpiar() {
                cedulaActual = null;
                seccionEmpleado.classList.add('d-none');
                seccionElegible.classList.add('d-none');
                resultadoModalidad.innerHTML = '';
            }

            function mostrarAviso(mensaje, clase) {
                resultado.innerHTML = '<div class="alert alert-' + clase + ' py-2 mb-0">' + escapar(mensaje) + '</div>';
            }

            function pintarPrestamos(prestamos) {
                const cuerpo = document.getElementById('tabla-prestamos');
                cuerpo.innerHTML = '';

                prestamos.forEach(function (p) {
                    cuerpo.insertAdjacentHTML('beforeend', '<tr><td>' + escapar(p.modalidad) + '</td>'
                        + '<td class="text-end">' + dinero(p.monto_desembolsado) + '</td>'
                        + '<td class="text-end">' + dinero(p.saldo_pendiente) + '</td>'
                        + '<td class="text-end">' + dinero(p.cuota_quincenal) + '</td>'
                        + '<td>' + escapar(p.progreso_cuotas) + '</td></tr>');
                });

                seccionPrestamos.classList.toggle('d-none', prestamos.length === 0);
            }

            function pintarEmpleado(e) {
                resultado.innerHTML = '';
                texto('empleado-nombre', e.nombre_completo);
                texto('empleado-cedula', e.cedula);
                texto('empleado-salario', dinero(e.salario));
                texto('empleado-estado', e.estado);
                seccionEmpleado.classList.remove('d-none');

                if (! e.elegible) {
                    seccionElegible.classList.add('d-none');
                    mostrarAviso(e.mensaje, 'danger');

                    return;
                }

                cedulaActual = e.cedula;
                pintarPrestamos(e.prestamos);
                texto('capacidad-total', dinero(e.capacidad.total));
                texto('capacidad-comprometida', dinero(e.capacidad.comprometida));
                texto('capacidad-disponible', dinero(e.capacidad.disponible));

                selector.innerHTML = '<option value="">Selecciona una modalidad…</option>';
                e.modalidades.forEach(function (m) {
                    const opcion = document.createElement('option');
                    opcion.value = m.id_tipo;
                    opcion.textContent = m.nombre;
                    selector.appendChild(opcion);
                });

                resultadoModalidad.innerHTML = '';
                seccionElegible.classList.remove('d-none');
            }

            function pintarModalidad(r) {
                if (! r.puede) {
                    resultadoModalidad.innerHTML = '<div class="alert alert-warning mb-0"><strong>No puede solicitar esta modalidad.</strong><br>'
                        + escapar(r.mensaje) + '</div>';

                    return;
                }

                resultadoModalidad.innerHTML = '<div class="card border-success"><div class="card-body">'
                    + '<div class="small text-muted">Monto máximo que puede pedir</div>'
                    + '<div class="display-6 mb-3">' + dinero(r.monto_maximo) + '</div>'
                    + '<dl class="row mb-0">'
                    + '<dt class="col-sm-4">Plazo máximo</dt><dd class="col-sm-8">' + escapar(r.plazo_legible) + '</dd>'
                    + '<dt class="col-sm-4">Tasa efectiva anual</dt><dd class="col-sm-8">' + escapar(String(r.tasa_efectiva_anual).replace('.', ',')) + ' %</dd>'
                    + '<dt class="col-sm-4">Cuota quincenal</dt><dd class="col-sm-8">' + dinero(r.cuota_quincenal) + '</dd>'
                    + '<dt class="col-sm-4">Limitado por</dt><dd class="col-sm-8">'
                    + (r.limitado_por === 'capacidad' ? 'La capacidad de endeudamiento disponible' : 'La cuantía máxima de su tramo salarial') + '</dd>'
                    + '</dl></div></div>';
            }

            campo.addEventListener('cedula:escribiendo', limpiar);

            campo.addEventListener('cedula:vacia', function () {
                limpiar();
                resultado.innerHTML = '';
            });

            campo.addEventListener('cedula:invalida', function (evento) {
                limpiar();
                mostrarAviso(evento.detail.mensaje, 'warning');
            });

            campo.addEventListener('cedula:encontrada', function (evento) {
                pintarEmpleado(evento.detail);
            });

            campo.addEventListener('cedula:error', function (evento) {
                limpiar();
                mostrarAviso(evento.detail.mensaje || 'No se pudo consultar el empleado.', 'warning');
            });

            selector.addEventListener('change', function () {
                if (peticionModalidad) {
                    peticionModalidad.abort();
                }

                if (selector.value === '' || cedulaActual === null) {
                    resultadoModalidad.innerHTML = '';

                    return;
                }

                peticionModalidad = new AbortController();

                fetch(urlEmpleado + '/' + cedulaActual + '/modalidad/' + selector.value, { signal: peticionModalidad.signal, headers: { 'Accept': 'application/json' } })
                    .then(function (respuesta) {
                        return respuesta.json().then(function (datos) {
                            return { ok: respuesta.ok, datos: datos };
                        });
                    })
                    .then(function (r) {
                        if (r.ok) {
                            pintarModalidad(r.datos);
                        } else {
                            resultadoModalidad.innerHTML = '<div class="alert alert-warning mb-0">' + escapar(r.datos.mensaje) + '</div>';
                        }
                    })
                    .catch(function (error) {
                        if (error.name !== 'AbortError') {
                            resultadoModalidad.innerHTML = '<div class="alert alert-danger mb-0">No se pudo consultar la modalidad. Inténtalo de nuevo.</div>';
                        }
                    });
            });
        })();
    </script>
@endsection
