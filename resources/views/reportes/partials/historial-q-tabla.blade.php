{{-- Tabla de la Q: datos del empleado y, por cada crédito vigente, modalidad · cuotas pagadas/total · valor de la cuota.
     En pantalla ($pantalla) recibe $columnasQ, solo las columnas con datos; la impresión muestra todas las de $columnas. --}}
@php
    $columnasTabla = $columnasQ ?? $columnas;
    $enPantalla = $pantalla ?? false;
@endphp
<div class="table-responsive {{ $enPantalla ? 'historial-q-contenedor' : '' }}">
    <table class="table table-striped table-sm bg-white align-middle historial-q {{ $enPantalla ? 'historial-q-pantalla' : '' }}">
        <thead>
            <tr>
                @foreach ($columnasTabla as $columna)
                    <th>{{ $columna['titulo'] }}</th>
                @endforeach
                <th>Crédito vigente <span class="fw-normal text-muted">(modalidad · cuotas · valor de la cuota)</span></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($empleados as $empleado)
                <tr>
                    @foreach ($columnasTabla as $columna)
                        <td>{{ $historial->valorColumna($empleado, $columna['campo']) }}</td>
                    @endforeach
                    <td>
                        @forelse ($lineas[$empleado->cedula] ?? [] as $linea)
                            <div class="linea-credito">
                                <span class="credito-modalidad">{{ $linea->modalidad }}</span> ·
                                <span class="credito-cuotas">{{ $linea->pagadas }}/{{ $linea->total }}</span> ·
                                <span class="credito-valor">{{ $linea->cuota }}</span>
                            </div>
                        @empty
                            —
                        @endforelse
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($columnasTabla) + 1 }}" class="text-center text-muted">{{ ($cedula ?? '') !== '' ? 'No hay empleados con esa cédula.' : 'No hay empleados registrados.' }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
