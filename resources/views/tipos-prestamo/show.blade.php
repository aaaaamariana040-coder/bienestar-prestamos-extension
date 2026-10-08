@extends('layouts.app')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">{{ $tipoPrestamo->nombre }}</h1>
        <div>
            @can('update', $tipoPrestamo)
                <a href="{{ route('tipos-prestamo.edit', $tipoPrestamo) }}" class="btn btn-outline-secondary">Editar</a>
            @endcan
            <a href="{{ route('tipos-prestamo.index') }}" class="btn btn-outline-secondary">Volver</a>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-3">Descripción</dt>
                <dd class="col-sm-9">{{ $tipoPrestamo->descripcion ?? '—' }}</dd>

                <dt class="col-sm-3">Plazo máximo</dt>
                <dd class="col-sm-9">{{ $tipoPrestamo->plazo_maximo_legible }} ({{ $tipoPrestamo->plazo_maximo }} cuotas quincenales)</dd>

                <dt class="col-sm-3">Estado</dt>
                <dd class="col-sm-9">{{ $tipoPrestamo->habilitada ? 'Habilitada' : 'Deshabilitada' }}</dd>

                <dt class="col-sm-3">Fuente normativa</dt>
                <dd class="col-sm-9">{{ $tipoPrestamo->fuente_normativa ?? '—' }}</dd>
            </dl>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header">Tasas de interés por año</div>
        <div class="card-body">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>Año</th>
                        <th>Tasa anual</th>
                        <th>Fuente normativa</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($tipoPrestamo->tasas as $tasa)
                        <tr>
                            <td>{{ $tasa->anio }}</td>
                            <td>{{ number_format($tasa->tasa_anual, 4) }}%</td>
                            <td>{{ $tasa->fuente_normativa ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="text-center text-muted">No hay tasas registradas.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            @can('update', $tipoPrestamo)
                <form method="POST" action="{{ route('tasas-int.store', $tipoPrestamo) }}" class="row g-2 align-items-end">
                    @csrf
                    <div class="col-auto">
                        <label for="anio" class="form-label">Año</label>
                        <input type="number" id="anio" name="anio" class="form-control" value="{{ old('anio') }}" required>
                    </div>
                    <div class="col-auto">
                        <label for="tasa_anual" class="form-label">Tasa anual (%)</label>
                        <input type="number" step="0.0001" min="0" max="100" id="tasa_anual" name="tasa_anual" class="form-control" value="{{ old('tasa_anual') }}" required>
                    </div>
                    <div class="col-auto">
                        <label for="fuente_normativa_tasa" class="form-label">Fuente normativa</label>
                        <input type="text" id="fuente_normativa_tasa" name="fuente_normativa" class="form-control" value="{{ old('fuente_normativa') }}">
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-primary">Registrar tasa</button>
                    </div>
                </form>
            @endcan
        </div>
    </div>

    @php
        $puedeEditarModalidad = auth()->user()->can('update', $tipoPrestamo);
    @endphp

    <div class="card">
        <div class="card-header">Cuantías máximas por tramo salarial (SMMLV)</div>
        <div class="card-body">
            <table class="table table-sm align-middle">
                <thead>
                    <tr>
                        <th>Desde (SMMLV)</th>
                        <th>Hasta (SMMLV)</th>
                        <th>Cuantía máxima (SMMLV)</th>
                        @if ($puedeEditarModalidad)
                            <th class="text-end">Acciones</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($tipoPrestamo->cuantias as $cuantia)
                        <tr>
                            <td style="max-width: 140px;">
                                <input type="number" step="0.01" min="0" name="tramo_desde_smmlv" class="form-control form-control-sm"
                                       value="{{ $cuantia->tramo_desde_smmlv }}" form="cuantia-editar-{{ $cuantia->id_cuantia }}" @disabled(! $puedeEditarModalidad)>
                            </td>
                            <td style="max-width: 140px;">
                                <input type="number" step="0.01" min="0" name="tramo_hasta_smmlv" class="form-control form-control-sm"
                                       value="{{ $cuantia->tramo_hasta_smmlv }}" placeholder="Sin tope" form="cuantia-editar-{{ $cuantia->id_cuantia }}" @disabled(! $puedeEditarModalidad)>
                            </td>
                            <td style="max-width: 140px;">
                                <input type="number" step="0.01" min="0" name="cuantia_maxima_smmlv" class="form-control form-control-sm"
                                       value="{{ $cuantia->cuantia_maxima_smmlv }}" form="cuantia-editar-{{ $cuantia->id_cuantia }}" @disabled(! $puedeEditarModalidad)>
                            </td>
                            @if ($puedeEditarModalidad)
                                <td class="text-end">
                                    <button type="submit" form="cuantia-editar-{{ $cuantia->id_cuantia }}" class="btn btn-sm btn-outline-secondary">Guardar</button>
                                    <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#modal-confirmar-eliminacion"
                                            data-eliminar-action="{{ route('cuantias-smmlv.destroy', $cuantia) }}"
                                            data-eliminar-nombre="el tramo de {{ $cuantia->tramo_desde_smmlv }} a {{ $cuantia->tramo_hasta_smmlv ?? 'sin tope' }} SMMLV (cuantía máxima {{ $cuantia->cuantia_maxima_smmlv }})">Eliminar</button>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center text-muted">No hay tramos de cuantía registrados.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            @if ($puedeEditarModalidad)
                @foreach ($tipoPrestamo->cuantias as $cuantia)
                    <form id="cuantia-editar-{{ $cuantia->id_cuantia }}" method="POST"
                          action="{{ route('cuantias-smmlv.update', $cuantia) }}" class="d-none">
                        @csrf
                        @method('PUT')
                    </form>
                @endforeach
            @endif

            @can('update', $tipoPrestamo)
                <form method="POST" action="{{ route('cuantias-smmlv.store', $tipoPrestamo) }}" class="row g-2 align-items-end">
                    @csrf
                    <div class="col-auto">
                        <label for="tramo_desde_smmlv" class="form-label">Desde (SMMLV)</label>
                        <input type="number" step="0.01" min="0" id="tramo_desde_smmlv" name="tramo_desde_smmlv" class="form-control" value="{{ old('tramo_desde_smmlv') }}" required>
                    </div>
                    <div class="col-auto">
                        <label for="tramo_hasta_smmlv" class="form-label">Hasta (SMMLV)</label>
                        <input type="number" step="0.01" min="0" id="tramo_hasta_smmlv" name="tramo_hasta_smmlv" class="form-control" value="{{ old('tramo_hasta_smmlv') }}" placeholder="Sin tope">
                    </div>
                    <div class="col-auto">
                        <label for="cuantia_maxima_smmlv" class="form-label">Cuantía máxima (SMMLV)</label>
                        <input type="number" step="0.01" min="0" id="cuantia_maxima_smmlv" name="cuantia_maxima_smmlv" class="form-control" value="{{ old('cuantia_maxima_smmlv') }}" required>
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-primary">Agregar tramo</button>
                    </div>
                </form>
            @endcan
        </div>
    </div>
    @if ($puedeEditarModalidad)
        <x-confirmar-eliminacion />
    @endif
@endsection
