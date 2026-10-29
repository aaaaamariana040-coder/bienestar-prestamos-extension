@extends('layouts.app')

@section('content')
    <h1 class="h3 mb-2">Cargar empleados</h1>
    <p class="text-muted mb-4">
        Cargue empleados desde un archivo CSV. Las reglas son las del formulario «Nuevo empleado». Si una cédula ya existe, la fila se
        omite y el empleado no se modifica. Todos los empleados nuevos entran con estado Activo.
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
                CSV en UTF-8, separado por punto y coma o coma. Columnas: <code>{{ implode('; ', $columnas) }}</code>.
                Obligatorias: cédula, nombre_completo, tipo_vinculacion y salario. Fechas dd/mm/aaaa o aaaa-mm-dd; tipos de vinculación:
                vinculado, docente, docente_ocasional, jubilado; de_carrera: sí o no. Máximo {{ $maximoFilas }} filas por archivo.
            </p>
            <a href="{{ route('cargar-empleados.plantilla') }}" class="btn btn-outline-primary" id="descargar-plantilla">Descargar plantilla</a>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <h2 class="h5">2. Suba el archivo y revise la vista previa</h2>
            <p class="text-muted">La vista previa valida cada fila y no guarda nada.</p>
            <form method="POST" action="{{ route('cargar-empleados.vista-previa') }}" enctype="multipart/form-data" class="row g-2 align-items-end">
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
                <th>Empleados</th>
                <th class="text-end">Acciones</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($lotes as $lote)
                <tr>
                    <td><a href="{{ route('cargar-empleados.lote', $lote['lote']) }}">{{ $lote['lote'] }}</a></td>
                    <td>{{ \Illuminate\Support\Carbon::parse($lote['fecha'])->format('Y-m-d H:i') }}</td>
                    <td>{{ $lote['cantidad'] }}</td>
                    <td class="text-end">
                        @if ($lote['reversible'])
                            <form method="POST" action="{{ route('cargar-empleados.revertir', $lote['lote']) }}" class="d-inline"
                                  onsubmit="return confirm('¿Revertir el lote {{ $lote['lote'] }}? Se eliminarán sus {{ $lote['cantidad'] }} empleados.');">
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
                    <td colspan="4" class="text-center text-muted">Todavía no se ha cargado ningún lote.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
@endsection
