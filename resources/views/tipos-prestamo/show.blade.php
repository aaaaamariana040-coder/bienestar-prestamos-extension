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
                <dd class="col-sm-9">{{ $tipoPrestamo->plazo_maximo_legible }}</dd>

                <dt class="col-sm-3">Estado</dt>
                <dd class="col-sm-9">{{ $tipoPrestamo->habilitada ? 'Habilitada' : 'Deshabilitada' }}</dd>

                <dt class="col-sm-3">Fuente normativa</dt>
                <dd class="col-sm-9">{{ $tipoPrestamo->fuente_normativa ?? '—' }}</dd>
            </dl>
        </div>
    </div>

    @php
        $puedeEditarTasas = auth()->user()->can('update', $tipoPrestamo);
        $tasaEditada = (int) old('_tasa_editada');
        $erroresTasa = collect(['anio', 'tasa_anual', 'fuente_normativa'])->flatMap(fn (string $campo): array => $errors->get($campo))
            ->merge($errors->getBag('edicionTasa')->all())
            ->unique();
        $topeVivienda = rtrim(rtrim(number_format((float) config('prestamos.tasa_vivienda.tope') * 100, 4, ',', ''), '0'), ',');
        $vigenteVivienda = \Illuminate\Support\Carbon::parse(config('prestamos.tasa_vivienda.vigente_desde'))->format('d/m/Y');
    @endphp

    <div class="card mb-4">
        <div class="card-header">Tasas de interés por año</div>
        <div class="card-body">
            @if ($erroresTasa->isNotEmpty())
                <div class="alert alert-danger" id="errores-tasa" role="alert">
                    <ul class="mb-0">
                        @foreach ($erroresTasa as $mensaje)
                            <li>{{ $mensaje }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($tipoPrestamo->esVivienda())
                <div class="alert alert-info py-2" id="nota-tope-vivienda">
                    Para vivienda, desde las solicitudes del {{ $vigenteVivienda }} se aplica un tope del {{ $topeVivienda }} % (Acuerdo 11 de 2023); aquí se registra el IPC real.
                </div>
            @endif

            <table class="table table-sm align-middle">
                <thead>
                    <tr>
                        <th>Año</th>
                        <th>Tasa anual</th>
                        <th>Fuente normativa</th>
                        @if ($puedeEditarTasas)
                            <th class="text-end">Acciones</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($tipoPrestamo->tasas as $tasa)
                        @php
                            $esLaEditada = $tasaEditada === $tasa->id_tasa;
                            $errorEdicion = $esLaEditada && $errors->getBag('edicionTasa')->has('tasa_anual');
                        @endphp
                        <tr>
                            <td>{{ $tasa->anio }}</td>
                            @if ($puedeEditarTasas)
                                <td style="max-width: 160px;">
                                    <div class="input-group input-group-sm">
                                        <input type="text" inputmode="decimal" name="tasa_anual" form="tasa-editar-{{ $tasa->id_tasa }}"
                                               @class(['form-control', 'is-invalid' => $errorEdicion]) autocomplete="off"
                                               value="{{ $esLaEditada ? old('tasa_anual') : rtrim(rtrim(number_format($tasa->tasa_anual, 4, ',', ''), '0'), ',') }}"
                                               aria-label="Tasa anual de {{ $tasa->anio }} (%)">
                                        <span class="input-group-text">%</span>
                                    </div>
                                </td>
                            @else
                                <td>{{ number_format($tasa->tasa_anual, 4) }}%</td>
                            @endif
                            <td>{{ $tasa->fuente_normativa ?? '—' }}</td>
                            @if ($puedeEditarTasas)
                                <td class="text-end">
                                    <button type="submit" form="tasa-editar-{{ $tasa->id_tasa }}" class="btn btn-sm btn-outline-secondary">Guardar</button>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $puedeEditarTasas ? 4 : 3 }}" class="text-center text-muted">No hay tasas registradas.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            @if ($puedeEditarTasas)
                @foreach ($tipoPrestamo->tasas as $tasa)
                    <form id="tasa-editar-{{ $tasa->id_tasa }}" method="POST" action="{{ route('tasas-int.update', $tasa) }}" class="d-none">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="_tasa_editada" value="{{ $tasa->id_tasa }}">
                    </form>
                @endforeach

                <form method="POST" action="{{ route('tasas-int.store', $tipoPrestamo) }}" class="row g-2 align-items-end">
                    @csrf
                    <div class="col-auto">
                        <label for="anio" class="form-label">Año</label>
                        <input type="number" id="anio" name="anio" @class(['form-control', 'is-invalid' => $errors->has('anio')]) value="{{ old('anio', now()->year) }}" required>
                    </div>
                    <div class="col-auto">
                        <label for="tasa_anual" class="form-label">Tasa anual (%)</label>
                        <input type="text" inputmode="decimal" id="tasa_anual" name="tasa_anual" @class(['form-control', 'is-invalid' => $errors->has('tasa_anual')]) autocomplete="off" value="{{ old('_tasa_editada') ? '' : old('tasa_anual') }}" required>
                    </div>
                    <div class="col-auto">
                        <label for="fuente_normativa_tasa" class="form-label">Fuente normativa</label>
                        <input type="text" id="fuente_normativa_tasa" name="fuente_normativa" @class(['form-control', 'is-invalid' => $errors->has('fuente_normativa')]) value="{{ old('fuente_normativa') }}">
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-primary">Registrar tasa</button>
                    </div>
                </form>
                <div class="form-text">Se acepta coma o punto decimal (6,5 o 6.5), entre 0 y 100. Cada año se registra una sola vez por modalidad; para cambiarlo, edita su fila de la tabla.</div>
            @endif
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
