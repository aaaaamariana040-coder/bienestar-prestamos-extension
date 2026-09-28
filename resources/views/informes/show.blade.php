@extends('layouts.app')

@section('content')
    <h1 class="h3 mb-3">{{ $informe->tipo->etiqueta() }} — informe guardado #{{ $informe->id_informe }}</h1>

    <div class="alert alert-warning">
        Esta es una copia del informe tal como se generó el {{ $informe->created_at->format('Y-m-d H:i') }}
        (fecha de corte {{ $informe->fecha_corte->format('Y-m-d') }}). No refleja los cambios hechos en los datos después de esa fecha.
    </div>

    <dl class="row">
        <dt class="col-sm-3">Generado por</dt>
        <dd class="col-sm-9">{{ $informe->generadoPor->nombre_completo }}</dd>

        <dt class="col-sm-3">Parámetros</dt>
        <dd class="col-sm-9">
            @php
                $parametros = array_filter($informe->parametros, fn ($valor) => $valor !== null && $valor !== '');
            @endphp
            @forelse ($parametros as $nombre => $valor)
                <span class="badge text-bg-secondary">{{ $nombre }}: {{ $valor }}</span>
            @empty
                <span class="text-muted">Sin filtros</span>
            @endforelse
        </dd>
    </dl>

    @include('informes.partials.'.$informe->tipo->value, ['informe' => $informe])

    <a href="{{ route('informes.index') }}" class="btn btn-outline-secondary">Volver al historial</a>
@endsection
