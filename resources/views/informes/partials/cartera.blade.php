<table class="table table-striped bg-white">
    <thead>
        <tr>
            <th>Empleado</th>
            <th>Modalidad</th>
            <th>Préstamo</th>
            <th>Cuota</th>
            <th>Vencimiento</th>
            <th>Valor</th>
            <th>Días de mora</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($informe->datos['filas'] as $fila)
            <tr>
                <td>{{ $fila['empleado'] }}</td>
                <td>{{ $fila['modalidad'] }}</td>
                <td>#{{ $fila['id_prestamo'] }}</td>
                <td>{{ $fila['numero_cuota'] }}</td>
                <td>{{ $fila['fecha_vencimiento'] }}</td>
                <td>{{ number_format($fila['valor_total'], 2) }}</td>
                <td>{{ $fila['dias_mora'] }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="7" class="text-center text-muted">El informe no contiene cuotas vencidas.</td>
            </tr>
        @endforelse
    </tbody>
    @if (count($informe->datos['filas']) > 0)
        <tfoot>
            <tr class="fw-bold">
                <td colspan="3">Total ({{ $informe->datos['totales']['total_cuotas'] }} cuotas)</td>
                <td colspan="2"></td>
                <td>{{ number_format($informe->datos['totales']['valor_total'], 2) }}</td>
                <td></td>
            </tr>
        </tfoot>
    @endif
</table>
