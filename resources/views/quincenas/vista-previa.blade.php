@extends('layouts.app')

@section('content')
    @php
        $repetidos = collect($vista['cuotas'])->groupBy('id_prestamo')->filter(fn ($cuotas) => $cuotas->count() > 1)->keys()->all();
    @endphp

    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <h1 class="h3 mb-1">Confirmar quincena {{ $vista['quincena'] }} de {{ $vista['anio'] }}</h1>
            <p class="text-muted mb-0">
                Periodo {{ $vista['inicio'] }} al {{ $vista['corte'] }} · Fecha de los pagos: {{ $fechaPago->toDateString() }}
            </p>
            <p class="text-muted small mb-0" id="cierre-novedades">
                Cierre de novedades de nómina: {{ $cierreNovedades->toDateString() }}
                <span class="badge text-bg-warning">provisional</span>
                <span>({{ config('quincena.cierre_novedades_dias_antes') }} días antes del vencimiento; solo informativo, no bloquea nada)</span>
            </p>
        </div>
        <a href="{{ route('quincenas.index') }}" class="btn btn-outline-secondary btn-sm">Volver a Quincenas</a>
    </div>

    @if (session('diferencias'))
        <div class="alert alert-warning">
            <strong>Lo que cambió desde que viste la pantalla:</strong>
            <ul class="mb-0">
                @foreach (session('diferencias') as $diferencia)
                    <li>{{ $diferencia }}</li>
                @endforeach
            </ul>
            Esta es la vista previa actualizada; revísala y confirma de nuevo.
        </div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $mensaje)
                    <li>{{ $mensaje }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @foreach ($vista['advertencias'] as $advertencia)
        <div class="alert alert-warning">{{ $advertencia }}. Puedes excluir una de ellas con la casilla.</div>
    @endforeach

    @if ($vista['vencidas_anteriores']['cuotas'] > 0)
        <div class="alert alert-info">
            Hay {{ $vista['vencidas_anteriores']['cuotas'] }} cuotas pendientes de {{ $vista['vencidas_anteriores']['prestamos'] }} préstamos que vencieron antes de esta quincena. No se pagan aquí.
        </div>
    @endif

    @if ($vista['cuotas'] === [])
        <div class="alert alert-secondary">Esta quincena no tiene cuotas para confirmar.</div>
    @else
        <form method="POST" action="{{ route('quincenas.confirmar', [$vista['anio'], $vista['quincena']]) }}" id="form-confirmar">
            @csrf

            <table class="table table-striped bg-white align-middle">
                <thead>
                    <tr>
                        <th>Excluir</th>
                        <th>Préstamo</th>
                        <th>Empleado</th>
                        <th>Cuota</th>
                        <th>Vence</th>
                        <th class="text-end">Valor</th>
                        <th>Motivo de la exclusión</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($vista['cuotas'] as $cuota)
                        @php
                            $id = $cuota['id_cuota'];
                            $excluida = in_array($id, array_map('intval', (array) old('excluir', [])), true);
                            $repetida = in_array($cuota['id_prestamo'], $repetidos, true);
                        @endphp
                        <tr class="{{ $repetida ? 'table-warning' : '' }}">
                            <td>
                                <input type="hidden" name="vistas[{{ $id }}]" value="{{ $cuota['valor_total'] }}">
                                <input type="checkbox" name="excluir[]" value="{{ $id }}" class="form-check-input casilla-excluir"
                                       data-valor="{{ $cuota['valor_total'] }}"
                                       aria-label="Excluir la cuota {{ $cuota['numero_cuota'] }} del préstamo {{ $cuota['id_prestamo'] }}"
                                       @checked($excluida)>
                            </td>
                            <td>
                                <a href="{{ route('prestamos.show', $cuota['id_prestamo']) }}">#{{ $cuota['id_prestamo'] }}</a>
                                @if ($repetida)
                                    <span class="badge text-bg-warning">2 o más cuotas</span>
                                @endif
                            </td>
                            <td>{{ $cuota['cedula_empleado'] }} · {{ $cuota['nombre_empleado'] }}</td>
                            <td>{{ $cuota['numero_cuota'] }}</td>
                            <td>{{ $cuota['fecha_vencimiento'] }}</td>
                            <td class="text-end">{{ number_format($cuota['valor_total'], 2) }}</td>
                            <td>
                                <input type="text" name="motivo[{{ $id }}]" value="{{ old('motivo.'.$id) }}" maxlength="255"
                                       class="form-control form-control-sm campo-motivo" placeholder="Obligatorio si se excluye"
                                       @disabled(! $excluida)>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="fw-semibold">
                        <td colspan="5" class="text-end">Total a pagar (<span id="cuotas-a-pagar">{{ count($vista['cuotas']) }}</span> cuotas)</td>
                        <td class="text-end" id="total-a-pagar">{{ number_format($vista['total'], 2) }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>

            <button type="submit" class="btn btn-primary">Confirmar quincena</button>
            <p class="text-muted small mt-2 mb-0">
                Los pagos quedan a tu nombre. Una cuota excluida sigue Pendiente y aparece como vencida anterior en la quincena siguiente.
                Si algo cambia mientras revisas esta pantalla, no se paga nada y se te muestra la diferencia.
            </p>
        </form>
    @endif

    @if ($vista['excluidos'] !== [])
        <h2 class="h6 mt-4">No entran en esta quincena</h2>
        <table class="table table-sm bg-white">
            <thead>
                <tr><th>Préstamo</th><th>Cuota</th><th>Motivo</th></tr>
            </thead>
            <tbody>
                @foreach ($vista['excluidos'] as $fila)
                    <tr>
                        <td>#{{ $fila['id_prestamo'] }}</td>
                        <td>{{ $fila['numero_cuota'] }}</td>
                        <td>{{ $fila['motivo'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endsection

@push('scripts')
    <script>
        (function () {
            const formulario = document.getElementById('form-confirmar');

            if (! formulario) {
                return;
            }

            const casillas = formulario.querySelectorAll('.casilla-excluir');

            function actualizar() {
                let total = 0;
                let cuotas = 0;

                casillas.forEach(function (casilla) {
                    const motivo = casilla.closest('tr').querySelector('.campo-motivo');

                    motivo.disabled = ! casilla.checked;
                    motivo.required = casilla.checked;

                    if (! casilla.checked) {
                        total += parseFloat(casilla.dataset.valor);
                        cuotas++;
                    }
                });

                document.getElementById('total-a-pagar').textContent = total.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                document.getElementById('cuotas-a-pagar').textContent = cuotas;
            }

            casillas.forEach(function (casilla) {
                casilla.addEventListener('change', actualizar);
            });

            formulario.addEventListener('submit', function (evento) {
                const cuotas = document.getElementById('cuotas-a-pagar').textContent;

                if (! window.confirm('Se van a pagar ' + cuotas + ' cuotas a tu nombre. ¿Confirmar la quincena?')) {
                    evento.preventDefault();
                }
            });

            actualizar();
        })();
    </script>
@endpush
