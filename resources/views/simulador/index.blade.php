@extends('layouts.app')

@section('content')
    <h1 class="h3 mb-1">Simulador de crédito</h1>
    <p class="text-muted mb-4">Calcula la cuota y la tabla de amortización de un crédito y la compara con la capacidad de endeudamiento del empleado. Es solo una simulación: no registra ninguna solicitud ni préstamo.</p>

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

            <h2 class="h5">Capacidad de endeudamiento guardada</h2>
            <p class="small text-muted">Es la que usa la aprobación de solicitudes. Al simular verás además la estimada con descuentos de ley.</p>
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

            <h2 class="h5">Datos de la simulación</h2>
            <form id="form-simulador" novalidate>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="modalidad" class="form-label">Modalidad</label>
                        <select id="modalidad" class="form-select">
                            <option value="">Selecciona una modalidad…</option>
                        </select>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label for="monto" class="form-label">Monto</label>
                        <input type="text" inputmode="decimal" id="monto" class="form-control" autocomplete="off">
                    </div>
                    <div class="col-md-3 mb-3">
                        <label for="plazo" class="form-label">Plazo (quincenas)</label>
                        <input type="number" min="1" step="1" id="plazo" class="form-control">
                        <div class="form-text" id="ayuda-plazo"></div>
                    </div>
                </div>

                <div id="resultado-modalidad" aria-live="polite" class="mb-3"></div>

                <div class="row">
                    <div class="col-md-3 mb-3">
                        <label for="tasa_efectiva" class="form-label">Tasa efectiva anual (%)</label>
                        <input type="text" inputmode="decimal" id="tasa_efectiva" class="form-control" autocomplete="off">
                    </div>
                    <div class="col-md-3 mb-3">
                        <label for="puntos" class="form-label">Puntos adicionales (%)</label>
                        <input type="text" inputmode="decimal" id="puntos" class="form-control" autocomplete="off">
                    </div>
                    <div class="col-md-3 mb-3">
                        <label for="retencion" class="form-label">Retención en la fuente (mensual)</label>
                        <input type="text" inputmode="decimal" id="retencion" class="form-control" autocomplete="off" placeholder="Opcional">
                        <div class="form-text">Dato manual; se resta solo en la capacidad estimada.</div>
                    </div>
                    <div class="col-md-3 mb-3 d-flex align-items-start pt-4">
                        <button type="button" class="btn btn-sm btn-outline-secondary mt-2" id="restablecer-tasa">Restablecer tasa oficial</button>
                    </div>
                </div>
                <div id="avisos-tasa" aria-live="polite"></div>

                <button type="submit" class="btn btn-primary mb-3" id="calcular">Calcular</button>
            </form>

            <div id="resultado-simulacion" aria-live="polite"></div>
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
            const resultadoSimulacion = document.getElementById('resultado-simulacion');
            const avisosTasa = document.getElementById('avisos-tasa');
            const formulario = document.getElementById('form-simulador');
            const campoMonto = document.getElementById('monto');
            const campoPlazo = document.getElementById('plazo');
            const campoTasa = document.getElementById('tasa_efectiva');
            const campoPuntos = document.getElementById('puntos');
            const campoRetencion = document.getElementById('retencion');
            const ayudaPlazo = document.getElementById('ayuda-plazo');
            const urlEmpleado = @json(url('simulador/empleado'));
            const urlTasa = @json(route('simulador.tasa'));
            const urlCalcular = @json(route('simulador.calcular'));
            let cedulaActual = null;
            let peticionModalidad = null;
            let peticionTasa = null;
            let peticionCalculo = null;
            let temporizadorTasa = null;
            let tasaEditada = false;

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

            function decimal(valor) {
                return String(valor).replace('.', ',');
            }

            function limpiar() {
                cedulaActual = null;
                seccionEmpleado.classList.add('d-none');
                seccionElegible.classList.add('d-none');
                limpiarSimulacion();
                resultadoModalidad.innerHTML = '';
            }

            function limpiarSimulacion() {
                resultadoSimulacion.innerHTML = '';
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
                limpiarSimulacion();
                reiniciarTasa();
                seccionElegible.classList.remove('d-none');
            }

            function reiniciarTasa() {
                tasaEditada = false;
                campoTasa.value = '';
                campoPuntos.value = '';
                avisosTasa.innerHTML = '';
                ayudaPlazo.textContent = '';
            }

            function pintarReferenciaModalidad(r) {
                if (! r.puede) {
                    resultadoModalidad.innerHTML = '<div class="alert alert-warning py-2 mb-0"><strong>Referencia de la modalidad:</strong> '
                        + escapar(r.mensaje) + ' <span class="text-muted">(Aun así puedes simular.)</span></div>';

                    return;
                }

                resultadoModalidad.innerHTML = '<div class="alert alert-light border py-2 mb-0"><strong>Referencia de la modalidad:</strong> '
                    + 'monto máximo ' + dinero(r.monto_maximo) + ' con ' + escapar(r.plazo_legible)
                    + ' (limitado por ' + (r.limitado_por === 'capacidad' ? 'la capacidad guardada disponible' : 'la cuantía máxima de su tramo salarial') + ').</div>';
            }

            function cargarReferenciaModalidad() {
                if (peticionModalidad) {
                    peticionModalidad.abort();
                }

                resultadoModalidad.innerHTML = '';

                if (selector.value === '' || cedulaActual === null) {
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
                            pintarReferenciaModalidad(r.datos);
                        }
                    })
                    .catch(function () {
                        // La referencia es opcional: si falla, se puede simular igual.
                    });
            }

            function cargarTasa() {
                if (peticionTasa) {
                    peticionTasa.abort();
                }

                if (selector.value === '' || cedulaActual === null) {
                    return;
                }

                const parametros = new URLSearchParams({ cedula: cedulaActual, id_tipo: selector.value });

                if (campoPlazo.value !== '' && Number(campoPlazo.value) >= 1) {
                    parametros.set('plazo', campoPlazo.value);
                }

                peticionTasa = new AbortController();

                fetch(urlTasa + '?' + parametros.toString(), { signal: peticionTasa.signal, headers: { 'Accept': 'application/json' } })
                    .then(function (respuesta) {
                        return respuesta.json().then(function (datos) {
                            return { ok: respuesta.ok, datos: datos };
                        });
                    })
                    .then(function (r) {
                        if (! r.ok) {
                            return;
                        }

                        ayudaPlazo.textContent = 'Máximo ' + r.datos.plazo_maximo + ' quincenas.';
                        campoPlazo.max = r.datos.plazo_maximo;
                        avisosTasa.innerHTML = '';

                        if (r.datos.base === null) {
                            avisosTasa.innerHTML = '<div class="alert alert-warning py-2">No hay tasa oficial registrada para esta modalidad en el año actual: digita la tasa para simular.</div>';
                        }

                        r.datos.avisos.forEach(function (aviso) {
                            avisosTasa.insertAdjacentHTML('beforeend', '<div class="alert alert-warning py-2">' + escapar(aviso) + '</div>');
                        });

                        if (! tasaEditada) {
                            campoTasa.value = r.datos.base === null ? '' : decimal(r.datos.base);
                            campoPuntos.value = decimal(r.datos.puntos);
                        }
                    })
                    .catch(function () {
                        // Sin tasa prellenada el operador puede digitarla.
                    });
            }

            function listaDeErrores(datos) {
                if (Array.isArray(datos.errores) && datos.errores.length > 0) {
                    return datos.errores;
                }

                if (datos.errors) {
                    return Object.values(datos.errors).flat();
                }

                return [datos.mensaje || datos.message || 'No se pudo calcular la simulación.'];
            }

            function pintarErrores(errores) {
                resultadoSimulacion.innerHTML = '<div class="alert alert-danger"><ul class="mb-0">'
                    + errores.map(function (mensaje) { return '<li>' + escapar(mensaje) + '</li>'; }).join('')
                    + '</ul></div>';
            }

            function calcular() {
                if (peticionCalculo) {
                    peticionCalculo.abort();
                }

                if (cedulaActual === null) {
                    return;
                }

                const faltantes = [];

                if (selector.value === '') { faltantes.push('Selecciona una modalidad.'); }
                if (campoMonto.value.trim() === '') { faltantes.push('Escribe el monto.'); }
                if (campoPlazo.value === '') { faltantes.push('Escribe el plazo en quincenas.'); }

                if (faltantes.length > 0) {
                    pintarErrores(faltantes);

                    return;
                }

                const parametros = new URLSearchParams({
                    cedula: cedulaActual,
                    id_tipo: selector.value,
                    monto: campoMonto.value.trim(),
                    plazo: campoPlazo.value,
                });

                [['tasa_efectiva', campoTasa], ['puntos', campoPuntos], ['retencion', campoRetencion]].forEach(function (par) {
                    if (par[1].value.trim() !== '') {
                        parametros.set(par[0], par[1].value.trim());
                    }
                });

                peticionCalculo = new AbortController();
                resultadoSimulacion.innerHTML = '<p class="text-muted">Calculando…</p>';

                fetch(urlCalcular + '?' + parametros.toString(), { signal: peticionCalculo.signal, headers: { 'Accept': 'application/json' } })
                    .then(function (respuesta) {
                        return respuesta.json().then(function (datos) {
                            return { ok: respuesta.ok, datos: datos };
                        });
                    })
                    .then(function (r) {
                        if (r.ok) {
                            resultadoSimulacion.innerHTML = r.datos.html;
                        } else {
                            pintarErrores(listaDeErrores(r.datos));
                        }
                    })
                    .catch(function (error) {
                        if (error.name !== 'AbortError') {
                            pintarErrores(['No se pudo calcular la simulación. Inténtalo de nuevo.']);
                        }
                    });
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
                reiniciarTasa();
                limpiarSimulacion();
                cargarReferenciaModalidad();
                cargarTasa();
            });

            campoPlazo.addEventListener('input', function () {
                clearTimeout(temporizadorTasa);
                temporizadorTasa = setTimeout(cargarTasa, 400);
            });

            [campoTasa, campoPuntos].forEach(function (entrada) {
                entrada.addEventListener('input', function () {
                    tasaEditada = true;
                });
            });

            document.getElementById('restablecer-tasa').addEventListener('click', function () {
                tasaEditada = false;
                cargarTasa();
            });

            formulario.addEventListener('submit', function (evento) {
                evento.preventDefault();
                calcular();
            });
        })();
    </script>
@endsection
