{{-- Información personal, situación de crédito y (si ya se consultó) los pagos del rango. Se usa en pantalla y en la impresión individual. --}}
@php
    $empleado = $individual['empleado'];
@endphp

<div class="card mb-3">
    <div class="card-body">
        <h2 class="h5">{{ $empleado->nombre_completo }} <span class="text-muted">· cédula {{ $empleado->cedula }}</span></h2>
        <dl class="row mb-0 small">
            @foreach ($columnas as $columna)
                @continue (in_array($columna['campo'], ['cedula', 'nombre_completo'], true))
                <dt class="col-sm-3 col-md-2">{{ $columna['titulo'] }}</dt>
                <dd class="col-sm-9 col-md-4">{{ $historial->valorColumna($empleado, $columna['campo']) }}</dd>
            @endforeach
            <dt class="col-sm-3 col-md-2">Estado</dt>
            <dd class="col-sm-9 col-md-4">{{ $empleado->estado }}</dd>
        </dl>
    </div>
</div>

<h2 class="h5">Situación de crédito</h2>

@if ($individual['creditos'] === [])
    <div class="alert alert-info" role="status">Este empleado no tiene créditos.</div>
@else
    <div class="table-responsive">
        <table class="table table-sm table-striped bg-white align-middle">
            <thead>
                <tr>
                    <th>Modalidad</th>
                    <th>Estado</th>
                    <th>Cuotas pagadas</th>
                    <th>Valor de la cuota</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($individual['creditos'] as $credito)
                    <tr>
                        <td>{{ $credito->modalidad }}</td>
                        <td>{{ $credito->estado }}</td>
                        <td>{{ $credito->pagadas }}/{{ $credito->total }}</td>
                        <td>{{ $credito->cuota }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
