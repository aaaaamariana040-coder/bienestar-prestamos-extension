@php
    $primeraCuota = $primera_cuota ?? ['fecha_desembolso' => null, 'fecha' => null];
@endphp
{{-- Tabla de amortización de la simulación. Las fechas son estimadas: se calculan como si el préstamo se autorizara hoy. --}}
<details class="mb-3">
    {{-- La fecha real de la primera cuota se fija al desembolsar. --}}
    <summary class="h6 mb-2">Tabla de amortización ({{ count($tabla) }} cuotas) <span class="text-muted fw-normal">— fechas estimadas</span></summary>
    @if ($primeraCuota['fecha_desembolso'] !== null)
        <p class="small text-muted mb-2" id="nota-primera-cuota">Estimación según la fecha de desembolso indicada; la fecha real se define al desembolsar.</p>
    @else
        <p class="small text-muted mb-2" id="nota-primera-cuota">La fecha real de la primera cuota se define al desembolsar.</p>
    @endif
    <div class="table-responsive" style="max-height: 28rem;">
        <table class="table table-sm table-striped align-middle mb-0">
            <thead class="sticky-top bg-white">
                <tr>
                    <th>Periodo</th>
                    <th>Fecha estimada</th>
                    <th class="text-end">Interés</th>
                    <th class="text-end">Capital</th>
                    <th class="text-end">Cuota</th>
                    <th class="text-end">Saldo</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($tabla as $fila)
                    <tr>
                        <td>{{ $fila['numero'] }}</td>
                        <td>{{ \Carbon\Carbon::parse($fila['fecha'])->format('d/m/Y') }}</td>
                        <td class="text-end">${{ number_format($fila['interes'], 2, ',', '.') }}</td>
                        <td class="text-end">${{ number_format($fila['capital'], 2, ',', '.') }}</td>
                        <td class="text-end">${{ number_format($fila['cuota'], 2, ',', '.') }}</td>
                        <td class="text-end">${{ number_format($fila['saldo'], 2, ',', '.') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <p class="small text-muted mt-1 mb-0">El saldo es el que queda después de pagar la cuota del periodo.</p>
</details>
