@extends('layouts.app')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">Préstamo #{{ $prestamo->id_prestamo }}</h1>
        <a href="{{ route('prestamos.index') }}" class="btn btn-outline-secondary">Volver a préstamos</a>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $mensaje)
                    <li>{{ $mensaje }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <table class="table bg-white mb-4">
        <tbody>
            <tr>
                <th style="width: 220px;">Empleado</th>
                <td>{{ $prestamo->empleado->nombre_completo }} ({{ $prestamo->cedula_empleado }})</td>
            </tr>
            <tr>
                <th>Modalidad</th>
                <td>{{ $prestamo->tipoPrestamo->nombre }}</td>
            </tr>
            <tr>
                <th>Monto aprobado</th>
                <td>{{ number_format($prestamo->monto_aprobado, 2) }}</td>
            </tr>
            <tr>
                <th>Tasa efectiva anual</th>
                <td>
                    {{ $tasa['tasa'] !== null ? number_format($tasa['tasa'], 4, ',', '.').' %' : '—' }}
                    @if ($tasa['nota'])
                        <div class="small text-info-emphasis mt-1" id="nota-tope-tasa">{{ $tasa['nota'] }}</div>
                    @endif
                </td>
            </tr>
            <tr>
                <th>Cuota periódica</th>
                <td>{{ number_format($prestamo->cuota_periodica, 2) }}</td>
            </tr>
            <tr>
                <th>Plazo</th>
                <td>{{ \App\Models\TipoPrestamo::plazoEnQuincenas($prestamo->num_cuotas) }}</td>
            </tr>
            <tr>
                <th>Cuotas pagadas</th>
                <td>{{ $prestamo->progreso_cuotas }}</td>
            </tr>
            <tr>
                <th>Fecha de autorización</th>
                <td>{{ $prestamo->fecha_autorizacion->format('Y-m-d') }}</td>
            </tr>
            <tr>
                <th>Estado</th>
                <td>{{ $prestamo->estado->value }}</td>
            </tr>
        </tbody>
    </table>

    <h2 class="h5 mb-3">Desembolsos ({{ $tramosEsperados }} tramo(s) esperado(s))</h2>

    <table class="table table-sm bg-white align-middle mb-3">
        <thead>
            <tr>
                <th>Tramo</th>
                <th>Porcentaje</th>
                <th>Valor</th>
                <th>Fecha</th>
                <th>Proveedor</th>
                <th>Estado</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($prestamo->desembolsos as $desembolso)
                <tr>
                    <td>{{ $desembolso->numero_tramo }}</td>
                    <td>{{ number_format($desembolso->porcentaje, 2) }}%</td>
                    <td>{{ number_format($desembolso->valor, 2) }}</td>
                    <td>{{ $desembolso->fecha?->format('Y-m-d') }}</td>
                    <td>{{ $desembolso->proveedor->nombre_empresa ?? 'Directo al empleado' }}</td>
                    <td>{{ $desembolso->estado->value }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="text-center text-muted">Sin desembolsos registrados.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @can('registrarDesembolso', $prestamo)
        @if ($prestamo->desembolsos->count() < $tramosEsperados)
            @php
                $siguienteTramo = $prestamo->desembolsos->count() + 1;
            @endphp
            <div class="card mb-4">
                <div class="card-body">
                    <h3 class="h6 mb-3">Registrar desembolso — tramo {{ $siguienteTramo }}</h3>

                    <form method="POST" action="{{ route('desembolsos.store', $prestamo) }}" class="row g-2 align-items-end">
                        @csrf
                        <input type="hidden" name="numero_tramo" value="{{ $siguienteTramo }}">

                        <div class="col-auto">
                            <label for="fecha" class="form-label">Fecha</label>
                            <input type="date" id="fecha" name="fecha" class="form-control" value="{{ old('fecha', now()->toDateString()) }}" required>
                        </div>

                        <div class="col-auto">
                            <label for="id_proveedor" class="form-label">Proveedor (opcional)</label>
                            <select id="id_proveedor" name="id_proveedor" class="form-select">
                                <option value="">Directo al empleado</option>
                                @foreach ($proveedores as $proveedor)
                                    <option value="{{ $proveedor->id_proveedor }}">{{ $proveedor->nombre_empresa }}</option>
                                @endforeach
                            </select>
                        </div>

                        @if ($siguienteTramo === 2)
                            <div class="col-auto form-check">
                                <input type="checkbox" id="inspeccion_aprobada" name="inspeccion_aprobada" value="1" class="form-check-input">
                                <label for="inspeccion_aprobada" class="form-check-label">
                                    Inspección técnico-administrativa aprobada (80% de inversión del primer tramo)
                                </label>
                            </div>
                        @endif

                        <div class="col-auto">
                            <button type="submit" class="btn btn-primary">Registrar desembolso</button>
                        </div>
                    </form>
                </div>
            </div>
        @endif
    @endcan

    <h2 class="h5 mb-3">Tabla de amortización</h2>

    <table class="table table-sm table-striped bg-white align-middle mb-4">
        <thead>
            <tr>
                <th>#</th>
                <th>Fecha de vencimiento</th>
                <th>Capital</th>
                <th>Interés</th>
                <th>Total</th>
                <th>Estado</th>
                @can('registrarPagoCuota', $prestamo)
                    <th class="text-end">Acciones</th>
                @endcan
            </tr>
        </thead>
        <tbody>
            @foreach ($prestamo->cuotas as $cuota)
                <tr>
                    <td>{{ $cuota->numero_cuota }}</td>
                    <td>{{ $cuota->fecha_vencimiento->format('Y-m-d') }}</td>
                    <td>{{ number_format($cuota->valor_capital, 2) }}</td>
                    <td>{{ number_format($cuota->valor_interes, 2) }}</td>
                    <td>{{ number_format($cuota->valor_total, 2) }}</td>
                    <td>{{ $cuota->estado->value }}</td>
                    @can('registrarPagoCuota', $prestamo)
                        <td class="text-end">
                            @if ($cuota->estado->value === 'Pendiente')
                                <form method="POST" action="{{ route('pagos.store-cuota', [$prestamo, $cuota]) }}" class="d-inline">
                                    @csrf
                                    <input type="hidden" name="fecha_pago" value="{{ now()->toDateString() }}">
                                    <button type="submit" class="btn btn-sm btn-outline-success">Pagar</button>
                                </form>
                            @endif
                        </td>
                    @endcan
                </tr>
            @endforeach
        </tbody>
    </table>

    @canany(['registrarAbono', 'cancelar'], $prestamo)
        <div class="row">
            @can('registrarAbono', $prestamo)
            <div class="col-md-6 mb-4">
                <div class="card h-100">
                    <div class="card-body">
                        <h3 class="h6 mb-3">Registrar abono extraordinario</h3>
                        <p class="text-muted small">
                            Un abono de hasta {{ $umbralCuotas }} cuotas ({{ number_format($umbralCuotas * (float) $prestamo->cuota_periodica, 2) }}) baja el saldo y el plazo; la cuota queda igual.
                            @if ($abonoAplicaAModalidad)
                                Si es mayor, elija si baja la cuota o el plazo (Art. 12 §4 y §5).
                            @else
                                En esta modalidad todo abono baja el plazo con la misma cuota.
                            @endif
                        </p>
                        <form method="POST" action="{{ route('pagos.store-abono', $prestamo) }}" id="form-abono" class="row g-2 align-items-end">
                            @csrf
                            <div class="col-auto">
                                <label for="monto" class="form-label">Monto</label>
                                <input type="text" inputmode="decimal" id="monto" name="monto" class="form-control" required>
                            </div>
                            <div class="col-auto">
                                <label for="fecha_pago_abono" class="form-label">Fecha</label>
                                <input type="date" id="fecha_pago_abono" name="fecha_pago" class="form-control" value="{{ now()->toDateString() }}" required>
                            </div>
                            <div class="col-12 d-none" id="abono-opciones">
                                <label class="form-label d-block">Este abono permite reliquidar. Elija:</label>
                                <div class="form-check form-check-inline">
                                    <input type="radio" id="modo_cuota" name="modo_reliquidacion" value="cuota" class="form-check-input">
                                    <label for="modo_cuota" class="form-check-label">Bajar el valor de la cuota</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input type="radio" id="modo_plazo" name="modo_reliquidacion" value="plazo" class="form-check-input">
                                    <label for="modo_plazo" class="form-check-label">Bajar el número de cuotas</label>
                                </div>
                                <div class="mt-2 d-none" id="abono-plazo">
                                    <label for="num_cuotas" class="form-label">Cuotas restantes (sugerido, editable)</label>
                                    <input type="number" min="1" id="num_cuotas" name="num_cuotas" class="form-control w-auto">
                                </div>
                            </div>
                            <div class="col-12 d-none" id="abono-vista-previa">
                                <p class="small text-muted d-none" id="vp-automatica">Este abono no supera el umbral: se reliquida solo, bajando el número de cuotas. La cuota queda igual y la última puede quedar menor.</p>
                                <table class="table table-sm bg-white mb-0">
                                    <tbody>
                                        <tr><th>Saldo anterior</th><td id="vp-saldo-anterior"></td><th>Saldo nuevo</th><td id="vp-saldo-nuevo"></td></tr>
                                        <tr><th>Cuota actual</th><td id="vp-cuota-actual"></td><th>Cuota nueva</th><td id="vp-cuota-nueva"></td></tr>
                                        <tr><th>Cuotas restantes actuales</th><td id="vp-cuotas-actuales"></td><th>Cuotas restantes nuevas</th><td id="vp-cuotas-nuevas"></td></tr>
                                    </tbody>
                                </table>
                            </div>
                            <div class="col-12 text-danger small d-none" id="abono-error"></div>
                            <div class="col-auto">
                                <button type="submit" class="btn btn-outline-primary">Confirmar abono</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            @endcan

            @can('cancelar', $prestamo)
            <div class="col-md-6 mb-4">
                <div class="card h-100">
                    <div class="card-body">
                        <h3 class="h6 mb-3">Cancelación total</h3>
                        <p class="text-muted">Paga de una vez el saldo de todas las cuotas pendientes y cierra el préstamo.</p>
                        <form method="POST" action="{{ route('pagos.store-cancelacion', $prestamo) }}">
                            @csrf
                            <input type="hidden" name="fecha_pago" value="{{ now()->toDateString() }}">
                            <button type="submit" class="btn btn-danger">Cancelar préstamo</button>
                        </form>
                    </div>
                </div>
            </div>
            @endcan

            @if (str_starts_with($prestamo->tipoPrestamo->nombre, 'Vivienda') && auth()->user()->can('registrarAbono', $prestamo))
                <div class="col-md-6 mb-4">
                    <div class="card h-100">
                        <div class="card-body">
                            <h3 class="h6 mb-3">Registrar abono de cesantías</h3>
                            <p class="text-muted small">Bianual, solo vivienda: 50% de las cesantías del año anterior (Art. 13). Siempre reliquida.</p>
                            <form method="POST" action="{{ route('cesantias.store', $prestamo) }}" class="row g-2 align-items-end">
                                @csrf
                                <div class="col-auto">
                                    <label for="anio_cesantias" class="form-label">Año de las cesantías</label>
                                    <input type="number" id="anio_cesantias" name="anio" class="form-control" value="{{ now()->subYear()->year }}" required>
                                </div>
                                <div class="col-auto">
                                    <label for="monto_cesantias" class="form-label">Monto (50%)</label>
                                    <input type="text" inputmode="decimal" id="monto_cesantias" name="monto" class="form-control" required>
                                </div>
                                <div class="col-auto">
                                    <label for="fecha_abono_cesantias" class="form-label">Fecha</label>
                                    <input type="date" id="fecha_abono_cesantias" name="fecha_abono" class="form-control" value="{{ now()->toDateString() }}" required>
                                </div>
                                <div class="col-auto">
                                    <label class="form-label d-block">Al reliquidar, prefiero</label>
                                    <div class="form-check form-check-inline">
                                        <input type="radio" id="cesantias_modo_plazo" name="modo_reliquidacion" value="plazo" class="form-check-input" checked>
                                        <label for="cesantias_modo_plazo" class="form-check-label">Bajar el plazo</label>
                                    </div>
                                    <div class="form-check form-check-inline">
                                        <input type="radio" id="cesantias_modo_cuota" name="modo_reliquidacion" value="cuota" class="form-check-input">
                                        <label for="cesantias_modo_cuota" class="form-check-label">Bajar la cuota</label>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <button type="submit" class="btn btn-outline-primary">Registrar abono de cesantías</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    @endcanany

    <h2 class="h5 mb-3">Historial de reliquidaciones</h2>

    <table class="table table-sm bg-white align-middle mb-4">
        <thead>
            <tr>
                <th>Fecha</th>
                <th>Motivo</th>
                <th>Opción</th>
                <th>Saldo anterior</th>
                <th>Saldo nuevo</th>
                <th>Tasa aplicada</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($prestamo->reliquidaciones as $reliquidacion)
                <tr>
                    <td>{{ $reliquidacion->fecha->format('Y-m-d') }}</td>
                    <td>{{ $reliquidacion->motivo->value }}</td>
                    <td>{{ match ($reliquidacion->opcion_elegida) { 'cuota' => 'Bajó la cuota', 'plazo' => $reliquidacion->automatica ? 'Bajó el plazo (automática: abono bajo el umbral)' : 'Bajó el plazo', default => '—' } }}</td>
                    <td>{{ number_format($reliquidacion->saldo_anterior, 2) }}</td>
                    <td>{{ $reliquidacion->saldo_nuevo !== null ? number_format($reliquidacion->saldo_nuevo, 2) : 'Pendiente de cálculo' }}</td>
                    <td>{{ $reliquidacion->tasa_aplicada !== null ? number_format($reliquidacion->tasa_aplicada, 4).'%' : '—' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="text-center text-muted">No hay reliquidaciones registradas.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <h2 class="h5 mb-3">Historial de pagos</h2>

    <table class="table table-sm bg-white align-middle">
        <thead>
            <tr>
                <th>Fecha</th>
                <th>Tipo</th>
                <th>Monto</th>
                <th>Aplicado a interés</th>
                <th>Aplicado a capital</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($prestamo->pagos as $pago)
                <tr>
                    <td>{{ $pago->fecha_pago->format('Y-m-d') }}</td>
                    <td>{{ $pago->tipo->value }}</td>
                    <td>{{ number_format($pago->monto, 2) }}</td>
                    <td>{{ number_format($pago->monto_aplicado_interes, 2) }}</td>
                    <td>{{ number_format($pago->monto_aplicado_capital, 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="text-center text-muted">No hay pagos registrados.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
@endsection

@push('scripts')
    @can('registrarAbono', $prestamo)
        <script>
            (function () {
                const url = @json(route('pagos.vista-previa-abono', $prestamo));
                const montoInput = document.getElementById('monto');
                const numInput = document.getElementById('num_cuotas');
                const opciones = document.getElementById('abono-opciones');
                const plazo = document.getElementById('abono-plazo');
                const tabla = document.getElementById('abono-vista-previa');
                const automatica = document.getElementById('vp-automatica');
                const error = document.getElementById('abono-error');
                const modos = document.querySelectorAll('input[name="modo_reliquidacion"]');
                let temporizador = null;
                let numEditado = false;

                const dinero = (n) => Number(n).toLocaleString('es-CO', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                const modoElegido = () => (document.querySelector('input[name="modo_reliquidacion"]:checked') || {}).value || '';

                function limpiar() {
                    opciones.classList.add('d-none');
                    tabla.classList.add('d-none');
                    error.classList.add('d-none');
                    modos.forEach((m) => { m.checked = false; m.required = false; });
                    numInput.disabled = true;
                    numInput.value = '';
                    numEditado = false;
                }

                function consultar() {
                    if (montoInput.value.trim() === '') { return limpiar(); }

                    const params = new URLSearchParams({monto: montoInput.value, modo_reliquidacion: modoElegido()});
                    if (modoElegido() === 'plazo' && numEditado && numInput.value !== '') {
                        params.set('num_cuotas', numInput.value);
                    }

                    fetch(url + '?' + params.toString(), {headers: {'Accept': 'application/json'}})
                        .then((r) => r.status === 422
                            ? r.json().then((j) => ({validacion: Object.values(j.errors || {}).flat()[0] || j.message || 'El monto no es válido.'}))
                            : (r.ok ? r.json() : Promise.reject()))
                        .then((d) => {
                            if (d.validacion || d.cancelacion_total) {
                                limpiar();
                                error.textContent = d.validacion || d.error;
                                error.classList.remove('d-none');

                                return;
                            }

                            automatica.classList.toggle('d-none', d.supera_umbral);
                            opciones.classList.toggle('d-none', ! d.supera_umbral);
                            modos.forEach((m) => { m.required = d.supera_umbral; if (! d.supera_umbral) { m.checked = false; } });
                            plazo.classList.toggle('d-none', ! d.supera_umbral || modoElegido() !== 'plazo');
                            numInput.disabled = ! d.supera_umbral || modoElegido() !== 'plazo';
                            error.classList.toggle('d-none', ! d.error);
                            error.textContent = d.error || '';

                            if (! d.plan) { return tabla.classList.add('d-none'); }

                            if (d.supera_umbral && d.plan.n_sugerido !== null && ! numEditado) { numInput.value = d.plan.n_sugerido; }
                            numInput.max = d.plan.cuotas_actuales;
                            document.getElementById('vp-saldo-anterior').textContent = dinero(d.plan.saldo_anterior);
                            document.getElementById('vp-saldo-nuevo').textContent = dinero(d.plan.saldo_nuevo);
                            document.getElementById('vp-cuota-actual').textContent = dinero(d.plan.cuota_actual);
                            document.getElementById('vp-cuota-nueva').textContent = dinero(d.plan.cuota_nueva);
                            document.getElementById('vp-cuotas-actuales').textContent = d.plan.cuotas_actuales;
                            document.getElementById('vp-cuotas-nuevas').textContent = d.plan.cuotas_nuevas;
                            tabla.classList.remove('d-none');
                        })
                        .catch(() => limpiar());
                }

                function programar() {
                    clearTimeout(temporizador);
                    temporizador = setTimeout(consultar, 300);
                }

                numInput.disabled = true;
                montoInput.addEventListener('input', programar);
                modos.forEach((m) => m.addEventListener('change', consultar));
                numInput.addEventListener('input', () => { numEditado = true; programar(); });
            })();
        </script>
    @endcan
@endpush