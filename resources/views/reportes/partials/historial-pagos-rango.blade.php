{{-- Pagos del empleado en una modalidad y un rango de fechas, con el total pagado. --}}
@php
    $modalidadElegida = $individual['modalidades']->firstWhere('id_tipo', $individual['modalidad']);
@endphp

<h2 class="h5">
    Pagos de {{ $modalidadElegida?->nombre }}
    <span class="text-muted">· del {{ $individual['desde'] }} al {{ $individual['hasta'] }}</span>
</h2>

@if ($individual['pagos']->isEmpty())
    <div class="alert alert-info" role="status">No hay pagos de esta modalidad en el rango de fechas elegido.</div>
@else
    <div class="table-responsive">
        <table class="table table-sm table-striped bg-white align-middle pagos-rango">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Cuota</th>
                    <th>Tipo</th>
                    <th class="text-end">Valor</th>
                    <th class="text-end">Capital</th>
                    <th class="text-end">Interés</th>
                    <th>Registrado por</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($individual['pagos'] as $pago)
                    <tr>
                        <td>{{ $pago->fecha_pago->format('Y-m-d') }}</td>
                        <td>{{ $pago->cuota?->numero_cuota ?? '—' }}</td>
                        <td>{{ $pago->tipo->value }}</td>
                        <td class="text-end">{{ number_format($pago->monto, 2) }}</td>
                        <td class="text-end">{{ number_format($pago->monto_aplicado_capital, 2) }}</td>
                        <td class="text-end">{{ number_format($pago->monto_aplicado_interes, 2) }}</td>
                        <td>{{ $pago->usuario?->nombre_completo ?? $pago->cedula_usu }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="fw-bold">
                    <td colspan="3">Total pagado en el rango</td>
                    <td class="text-end" id="total-pagado">{{ number_format($individual['total'], 2) }}</td>
                    <td colspan="3"></td>
                </tr>
            </tfoot>
        </table>
    </div>
@endif
