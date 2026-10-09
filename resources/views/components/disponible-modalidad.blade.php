@props(['tipoPrestamo', 'presupuesto' => null])

@if ($presupuesto)
    Para {{ $tipoPrestamo->nombre }} quedan ${{ number_format($presupuesto->monto_disponible_calculado, 2) }}
@else
    <span class="text-muted">Sin presupuesto asignado</span>
@endif
