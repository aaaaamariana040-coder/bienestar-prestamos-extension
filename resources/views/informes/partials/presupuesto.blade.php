<table class="table table-striped bg-white">
    <thead>
        <tr>
            <th>Modalidad</th>
            <th>Asignado</th>
            <th>Comprometido</th>
            <th>Disponible</th>
            <th>% ejecutado</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($informe->datos['filas'] as $fila)
            <tr>
                <td>{{ $fila['modalidad'] }}</td>
                <td>{{ number_format($fila['monto_asignado'], 2) }}</td>
                <td>{{ number_format($fila['monto_comprometido'], 2) }}</td>
                <td>{{ number_format($fila['monto_disponible'], 2) }}</td>
                <td>{{ number_format($fila['porcentaje_ejecutado'], 1) }}%</td>
            </tr>
        @empty
            <tr>
                <td colspan="5" class="text-center text-muted">El informe no contiene presupuesto para este año.</td>
            </tr>
        @endforelse
    </tbody>
    @if (count($informe->datos['filas']) > 0)
        <tfoot>
            <tr class="fw-bold">
                <td>Total</td>
                <td>{{ number_format($informe->datos['totales']['monto_asignado'], 2) }}</td>
                <td>{{ number_format($informe->datos['totales']['monto_comprometido'], 2) }}</td>
                <td>{{ number_format($informe->datos['totales']['monto_disponible'], 2) }}</td>
                <td></td>
            </tr>
        </tfoot>
    @endif
</table>
