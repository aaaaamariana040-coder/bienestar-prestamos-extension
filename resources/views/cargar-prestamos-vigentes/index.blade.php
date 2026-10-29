@extends('layouts.app')

@section('content')
    <h1 class="h3 mb-2">Cargar préstamos vigentes</h1>
    <p class="text-muted mb-4">
        Cargue por archivo la cartera de préstamos que ya están vigentes, para practicar y empezar a operar el aplicativo.
        Son préstamos históricos: no se revisan capacidad de endeudamiento, antigüedad, salario mínimo ni presupuesto, y la carga
        no consume ni modifica el presupuesto. Los pagos anteriores no se reconstruyen: las primeras cuotas indicadas quedan
        marcadas como pagadas, sin pagos registrados en el aplicativo (el Resumen de pagos solo cuenta los pagos registrados aquí).
    </p>

    @if ($errors->any())
        <div class="alert alert-danger" role="alert">
            <ul class="mb-0">
                @foreach ($errors->all() as $mensaje)
                    <li>{{ $mensaje }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card mb-4">
        <div class="card-body">
            <h2 class="h5">1. Descargue la plantilla</h2>
            <p class="mb-2">
                CSV en UTF-8, separado por punto y coma. Columnas: <code>{{ implode('; ', $columnas) }}</code>.
                Montos y tasas aceptan el formato colombiano (<code>3.000.000,50</code>, <code>10,5</code>). Máximo {{ $maximoFilas }} filas por archivo.
            </p>
            <a href="{{ route('cargar-prestamos-vigentes.plantilla') }}" class="btn btn-outline-primary" id="descargar-plantilla">Descargar plantilla</a>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <h2 class="h5">2. Suba el archivo y revise la vista previa</h2>
            <p class="text-muted">La vista previa valida cada fila y no guarda nada.</p>
            <form method="POST" action="{{ route('cargar-prestamos-vigentes.vista-previa') }}" enctype="multipart/form-data" class="row g-2 align-items-end">
                @csrf
                <div class="col-md-6">
                    <label for="archivo" class="form-label">Archivo CSV</label>
                    <input type="file" id="archivo" name="archivo" accept=".csv,.txt" required class="form-control">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary">Ver vista previa</button>
                </div>
            </form>
        </div>
    </div>

    <h2 class="h5 mb-3">Lotes cargados</h2>
    <table class="table table-striped bg-white align-middle" id="lotes-cargados">
        <thead>
            <tr>
                <th>Lote</th>
                <th>Fecha</th>
                <th>Préstamos</th>
                <th>Monto inicial total</th>
                <th class="text-end">Acciones</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($lotes as $lote)
                <tr>
                    <td><a href="{{ route('cargar-prestamos-vigentes.lote', $lote['lote']) }}">{{ $lote['lote'] }}</a></td>
                    <td>{{ \Illuminate\Support\Carbon::parse($lote['fecha'])->format('Y-m-d H:i') }}</td>
                    <td>{{ $lote['cantidad'] }}</td>
                    <td>{{ number_format($lote['monto'], 2, ',', '.') }}</td>
                    <td class="text-end">
                        @if ($lote['reversible'])
                            <form method="POST" action="{{ route('cargar-prestamos-vigentes.revertir', $lote['lote']) }}" class="d-inline"
                                  onsubmit="return confirm('¿Revertir el lote {{ $lote['lote'] }}? Se eliminarán sus {{ $lote['cantidad'] }} préstamos con sus cuotas y desembolsos.');">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline-danger">Revertir lote</button>
                            </form>
                        @else
                            <details class="text-start d-inline-block">
                                <summary class="text-muted small">No se puede revertir</summary>
                                <ul class="small mb-0 ps-3">
                                    @foreach ($lote['motivos'] as $motivo)
                                        <li>{{ $motivo }}</li>
                                    @endforeach
                                </ul>
                            </details>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="text-center text-muted">Todavía no se ha cargado ningún lote.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
@endsection
