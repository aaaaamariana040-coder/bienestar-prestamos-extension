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
                <th>Cuota periódica</th>
                <td>{{ number_format($prestamo->cuota_periodica, 2) }}</td>
            </tr>
            <tr>
                <th>Número de cuotas</th>
                <td>{{ $prestamo->num_cuotas }}</td>
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
                @can('registrarPago', $prestamo)
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
                    @can('registrarPago', $prestamo)
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

    @can('registrarPago', $prestamo)
        <div class="row">
            <div class="col-md-6 mb-4">
                <div class="card h-100">
                    <div class="card-body">
                        <h3 class="h6 mb-3">Registrar abono extraordinario</h3>
                        <form method="POST" action="{{ route('pagos.store-abono', $prestamo) }}" class="row g-2 align-items-end">
                            @csrf
                            <div class="col-auto">
                                <label for="monto" class="form-label">Monto</label>
                                <input type="number" step="0.01" min="0.01" id="monto" name="monto" class="form-control" required>
                            </div>
                            <div class="col-auto">
                                <label for="fecha_pago_abono" class="form-label">Fecha</label>
                                <input type="date" id="fecha_pago_abono" name="fecha_pago" class="form-control" value="{{ now()->toDateString() }}" required>
                            </div>
                            <div class="col-auto">
                                <button type="submit" class="btn btn-outline-primary">Registrar abono</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

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
        </div>
    @endcan

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
