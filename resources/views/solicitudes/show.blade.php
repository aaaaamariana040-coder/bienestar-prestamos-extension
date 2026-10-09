@extends('layouts.app')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">Solicitud #{{ $solicitud->id_solicitud }}</h1>
        <a href="{{ route('solicitudes.index') }}" class="btn btn-outline-secondary">Volver a solicitudes</a>
    </div>

    <table class="table bg-white">
        <tbody>
            <tr>
                <th style="width: 220px;">Empleado</th>
                <td>{{ $solicitud->empleado->nombre_completo }} ({{ $solicitud->cedula_empleado }})</td>
            </tr>
            <tr>
                <th>Modalidad</th>
                <td>{{ $solicitud->tipoPrestamo->nombre }}</td>
            </tr>
            <tr>
                <th>Monto solicitado</th>
                <td>{{ number_format($solicitud->monto_solicitado, 2) }}</td>
            </tr>
            <tr>
                <th>Plazo</th>
                <td>{{ $solicitud->plazo_solicitado_legible }}</td>
            </tr>
            <tr>
                <th>Fecha de solicitud</th>
                <td>{{ $solicitud->fecha_solicitud->format('Y-m-d') }}</td>
            </tr>
            <tr>
                <th>Estado</th>
                <td>{{ $solicitud->estado->value }}</td>
            </tr>
            @if ($solicitud->motivo_rechazo)
                <tr>
                    <th>Motivo de rechazo</th>
                    <td>{{ $solicitud->motivo_rechazo }}</td>
                </tr>
            @endif
            @if ($solicitud->prestamo)
                <tr>
                    <th>Préstamo generado</th>
                    <td><a href="{{ route('prestamos.show', $solicitud->prestamo) }}">Ver préstamo #{{ $solicitud->prestamo->id_prestamo }}</a></td>
                </tr>
            @endif
        </tbody>
    </table>

    <h2 class="h5 mt-4">Documentos</h2>

    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $mensaje)
                    <li>{{ $mensaje }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($documentos->isEmpty())
        <p class="text-muted">Esta solicitud todavía no tiene documentos.</p>
    @else
        <div class="table-responsive">
            <table class="table table-sm table-striped align-middle bg-white">
                <thead>
                    <tr>
                        <th>Tipo</th>
                        <th>Archivo</th>
                        <th class="text-end">Tamaño</th>
                        <th>Subido por</th>
                        <th>Fecha</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($documentos as $documento)
                        <tr>
                            <td>{{ $documento->tipoDocumento->nombre }}</td>
                            <td>{{ $documento->nombre_original }}</td>
                            <td class="text-end">{{ $documento->tamano_legible }}</td>
                            <td>{{ $documento->subidoPor->nombre_completo }}</td>
                            <td>{{ $documento->created_at->format('Y-m-d H:i') }}</td>
                            <td class="text-end text-nowrap">
                                <a href="{{ route('documentos.download', $documento) }}" class="btn btn-sm btn-outline-primary">Descargar</a>
                                <form method="POST" action="{{ route('documentos.destroy', $documento) }}" class="d-inline"
                                      onsubmit="return confirm('¿Eliminar el documento «{{ addslashes($documento->nombre_original) }}»?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Eliminar</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <form method="POST" action="{{ route('documentos.store', $solicitud) }}" enctype="multipart/form-data" class="card card-body mb-4">
        @csrf
        <div class="row align-items-end">
            <div class="col-md-4 mb-2">
                <label for="id_tipo_documento" class="form-label">Tipo de documento</label>
                <select id="id_tipo_documento" name="id_tipo_documento" class="form-select" required>
                    <option value="">Selecciona un tipo…</option>
                    @foreach ($tiposDocumento as $tipoDocumento)
                        <option value="{{ $tipoDocumento->id_tipo_documento }}" @selected((string) old('id_tipo_documento') === (string) $tipoDocumento->id_tipo_documento)>{{ $tipoDocumento->nombre }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-5 mb-2">
                <label for="archivos" class="form-label">Archivos (PDF, JPG o PNG; máximo 5 MB cada uno)</label>
                <input type="file" id="archivos" name="archivos[]" class="form-control" multiple required accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png">
            </div>
            <div class="col-md-3 mb-2">
                <button type="submit" class="btn btn-primary w-100">Subir documentos</button>
            </div>
        </div>
        <div class="small text-muted">Máximo 15 documentos por solicitud (tiene {{ $documentos->count() }}).</div>
    </form>

    @can('decidir', $solicitud)
        @if ($problemas !== [])
            <div class="alert alert-warning">
                <p class="mb-1">La solicitud no cumple las condiciones para ser aprobada:</p>
                <ul class="mb-0">
                    @foreach ($problemas as $problema)
                        <li>{{ $problema }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="d-flex gap-2">
            <form method="POST" action="{{ route('solicitudes.aprobar', $solicitud) }}">
                @csrf
                @method('PATCH')
                <button type="submit" class="btn btn-success">Aprobar</button>
            </form>

            <button type="button" class="btn btn-danger" data-bs-toggle="collapse" data-bs-target="#rechazar-form">
                Rechazar
            </button>
        </div>

        <div id="rechazar-form" class="collapse mt-3">
            <form method="POST" action="{{ route('solicitudes.rechazar', $solicitud) }}" class="card card-body">
                @csrf
                @method('PATCH')

                <label for="motivo_rechazo" class="form-label">Motivo del rechazo</label>
                <textarea id="motivo_rechazo" name="motivo_rechazo" class="form-control mb-2" required>{{ old('motivo_rechazo') }}</textarea>

                <button type="submit" class="btn btn-danger align-self-start">Confirmar rechazo</button>
            </form>
        </div>
    @endcan
@endsection
