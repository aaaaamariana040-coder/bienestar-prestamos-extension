@extends('layouts.app')

@section('content')
    {{--
        Simulador: hoja impresa (Simulación de crédito).
        La hoja vive en #hoja-impresion, oculta en pantalla (.solo-impresion); al imprimir solo se ve ella.
        Se archiva en la carpeta física del crédito: A4 vertical, bordes finos en lugar de colores de fondo
        (se lee igual con «gráficos de fondo» apagado), encabezado de la tabla repetido en cada página y
        pie con el aviso en todas las hojas.
        Los estilos van aquí y no en tema-institucional.css: solo se usan en esta pantalla.
    --}}
    <style>
        @page simulacion {
            size: A4 portrait;
            margin: 12mm 12mm 22mm;
        }

        @media print {
            .hoja-simulacion {
                page: simulacion;
                font-size: 9pt;
                line-height: 1.35;
                color: #000;
            }

            .hoja-simulacion h1,
            .hoja-simulacion h2,
            .hoja-simulacion p {
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
                margin: 8px 0 6px !important;
                font-size: 16pt;
                text-align: center;
            }

            .hoja-bloque {
                margin-bottom: 8px;
            }

            .hoja-bloque h2 {
                margin-bottom: 3px;
                padding-bottom: 1px;
                border-bottom: 0.5pt solid #000;
                font-size: 10pt;
                font-weight: 700;
                break-after: avoid;
            }

            .hoja-datos {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 1px 16px;
                margin: 0;
            }

            .hoja-datos > div {
                display: flex;
                justify-content: space-between;
                gap: 8px;
                border-bottom: 0.25pt dotted #777;
            }

            .hoja-datos dt {
                font-weight: 400;
            }

            .hoja-datos dd {
                margin: 0;
                text-align: right;
                font-weight: 600;
            }

            .hoja-alerta {
                margin-top: 4px !important;
                padding: 3px 6px;
                border: 1.5pt solid #000;
                font-size: 9pt;
                break-inside: avoid;
            }

            .hoja-nota {
                margin-top: 3px !important;
                font-size: 8pt;
            }

            .hoja-veredicto {
                margin-bottom: 3px !important;
                padding: 3px 6px;
                border: 1pt solid #000;
                font-weight: 700;
                break-inside: avoid;
            }

            .hoja-columnas {
                display: grid;
                grid-template-columns: 1.4fr 1fr;
                gap: 12px;
                margin-top: 4px;
                align-items: start;
                break-inside: avoid;
            }

            .hoja-tabla-simple,
            .hoja-tabla {
                width: 100%;
                border-collapse: collapse;
            }

            .hoja-tabla-simple caption {
                padding: 0 0 2px;
                caption-side: top;
                font-weight: 700;
                text-align: left;
                color: #000;
            }

            .hoja-tabla-simple td {
                padding: 1px 3px;
                border-bottom: 0.25pt solid #999;
            }

            .hoja-tabla-simple td:last-child {
                text-align: right;
                white-space: nowrap;
            }

            .hoja-tabla-simple tr.total td {
                font-weight: 700;
            }

            .hoja-tabla th,
            .hoja-tabla td {
                padding: 1px 4px;
                border: 0.5pt solid #555;
                text-align: right;
                font-size: 8pt;
            }

            .hoja-tabla th:first-child,
            .hoja-tabla td:first-child,
            .hoja-tabla th:nth-child(2),
            .hoja-tabla td:nth-child(2) {
                text-align: left;
            }

            .hoja-tabla th {
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
                <div id="nota-tope-tasa-previa" aria-live="polite"></div>
                <div id="avisos-tasa" aria-live="polite"></div>

                <button type="submit" class="btn btn-primary mb-3" id="calcular">Calcular</button>
            </form>

            <div id="resultado-simulacion" aria-live="polite"></div>
        </div>
    </div>
    </div>

    {{-- Hoja que se imprime: se llena con la misma respuesta que la pantalla; en pantalla está oculta. --}}
    <div id="hoja-impresion" class="solo-impresion"></div>

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
            const hojaImpresion = document.getElementById('hoja-impresion');
            const avisosTasa = document.getElementById('avisos-tasa');
            const notaTope = document.getElementById('nota-tope-tasa-previa');
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
                hojaImpresion.innerHTML = '';
            }

            // Imprime la hoja de la simulación que está en pantalla. El título del documento
            // sugiere el nombre del archivo al «Guardar como PDF».
            function imprimirSimulacion() {
                const hoja = hojaImpresion.querySelector('.hoja-simulacion');

                if (hoja === null) {
                    return;
                }

                const tituloAnterior = document.title;
                const restaurar = function () {
                    document.title = tituloAnterior;
                    window.removeEventListener('afterprint', restaurar);
                };

                document.title = hoja.dataset.titulo || tituloAnterior;
                window.addEventListener('afterprint', restaurar);
                window.print();
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
                pintarNotaTope(null);
                ayudaPlazo.textContent = '';
            }

            function pintarNotaTope(nota) {
                notaTope.innerHTML = nota ? '<div class="alert alert-info py-2">' + escapar(nota) + '</div>' : '';
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

                            // La nota del tope sale al elegir la modalidad, sin esperar a calcular.
                            if (r.datos.nota_tasa) {
                                pintarNotaTope(r.datos.nota_tasa);
                            }
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

                        pintarNotaTope(r.datos.nota);

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
                hojaImpresion.innerHTML = '';

                fetch(urlCalcular + '?' + parametros.toString(), { signal: peticionCalculo.signal, headers: { 'Accept': 'application/json' } })
                    .then(function (respuesta) {
                        return respuesta.json().then(function (datos) {
                            return { ok: respuesta.ok, datos: datos };
                        });
                    })
                    .then(function (r) {
                        if (r.ok) {
                            resultadoSimulacion.innerHTML = r.datos.html;
                            hojaImpresion.innerHTML = r.datos.html_impresion;
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

            resultadoSimulacion.addEventListener('click', function (evento) {
                if (evento.target.closest('#imprimir-simulacion') !== null) {
                    imprimirSimulacion();
                }
            });

            formulario.addEventListener('submit', function (evento) {
                evento.preventDefault();
                calcular();
            });
        })();
    </script>
@endsection
