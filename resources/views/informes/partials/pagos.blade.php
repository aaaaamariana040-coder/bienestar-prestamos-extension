<table class="table table-striped bg-white">
    <thead>
        <tr>
            <th>Modalidad</th>
            <th>Cantidad de pagos</th>
            <th>Monto total</th>
            <th>Interés aplicado</th>
            <th>Capital aplicado</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($informe->datos['filas'] as $fila)
            <tr>
                <td>{{ $fila['modalidad'] }}</td>
                <td>{{ $fila['total_pagos'] }}</td>
                <td>{{ number_format($fila['monto_total'], 2) }}</td>
                <td>{{ number_format($fila['monto_interes'], 2) }}</td>
                <td>{{ number_format($fila['monto_capital'], 2) }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="5" class="text-center text-muted">El informe no contiene pagos.</td>
            </tr>
        @endforelse
    </tbody>
    @if (count($informe->datos['filas']) > 0)
        <tfoot>
            <tr class="fw-bold">
                <td>Total</td>
                <td>{{ $informe->datos['totales']['total_pagos'] }}</td>
                <td>{{ number_format($informe->datos['totales']['monto_total'], 2) }}</td>
                <td>{{ number_format($informe->datos['totales']['monto_interes'], 2) }}</td>
                <td>{{ number_format($informe->datos['totales']['monto_capital'], 2) }}</td>
            </tr>
        </tfoot>
    @endif
</table>
