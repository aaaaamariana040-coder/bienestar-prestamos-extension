<table class="table table-striped bg-white">
    <thead>
        <tr>
            <th>Modalidad</th>
            <th>Estado</th>
            <th>Cantidad</th>
            <th>Monto total</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($informe->datos['filas'] as $fila)
            <tr>
                <td>{{ $fila['modalidad'] }}</td>
                <td>{{ $fila['estado'] }}</td>
                <td>{{ $fila['total_prestamos'] }}</td>
                <td>{{ number_format($fila['monto_total'], 2) }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="4" class="text-center text-muted">El informe no contiene préstamos.</td>
            </tr>
        @endforelse
    </tbody>
    @if (count($informe->datos['filas']) > 0)
        <tfoot>
            <tr class="fw-bold">
                <td colspan="2">Total</td>
                <td>{{ $informe->datos['totales']['total_prestamos'] }}</td>
                <td>{{ number_format($informe->datos['totales']['monto_total'], 2) }}</td>
            </tr>
        </tfoot>
    @endif
</table>
