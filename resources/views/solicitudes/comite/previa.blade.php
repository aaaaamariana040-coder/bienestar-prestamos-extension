@extends('layouts.app')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">Enviar solicitud #{{ $solicitud->id_solicitud }} al comité</h1>
        <a href="{{ route('solicitudes.show', $solicitud) }}" class="btn btn-outline-secondary">Volver a la solicitud</a>
    </div>

    @foreach ($previa['bloqueos'] as $bloqueo)
        <div class="alert alert-danger py-2 mb-2"><strong>No se puede enviar:</strong> {{ $bloqueo }}</div>
    @endforeach

    @foreach ($previa['avisos'] as $aviso)
        <div class="alert alert-warning py-2 mb-2">{{ $aviso }}</div>
    @endforeach

    @if ($previa['es_reenvio'])
        <div class="alert alert-info py-2 mb-2">
            Esta solicitud ya se envió al comité el {{ $previa['ultimo_envio']->enviado_en->format('Y-m-d H:i') }}
            (por {{ $previa['ultimo_envio']->usuario->nombre_completo }}{{ $previa['ultimo_envio']->simulado ? ', simulado' : '' }}).
            Un nuevo envío es un <strong>reenvío</strong> y quedará registrado como tal.
        </div>
    @endif

    <div class="card mb-3">
        <div class="card-header">Correo</div>
        <div class="card-body">
            <dl class="row mb-3">
                <dt class="col-sm-2">Para</dt>
                <dd class="col-sm-10" id="previa-para">{{ $previa['destinatarios'] === [] ? '— (sin destinatarios configurados)' : implode(', ', $previa['destinatarios']) }}</dd>
                <dt class="col-sm-2">Con copia</dt>
                <dd class="col-sm-10" id="previa-copia">{{ $previa['copia'] === [] ? '—' : implode(', ', $previa['copia']) }}</dd>
                <dt class="col-sm-2">Asunto</dt>
                <dd class="col-sm-10" id="previa-asunto">{{ $previa['asunto'] }}</dd>
            </dl>
            <iframe title="Cuerpo del correo" srcdoc="{{ $previa['cuerpo'] }}" sandbox="" class="w-100 border rounded bg-white" style="height: 30rem;"></iframe>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">Adjuntos</div>
        <div class="card-body">
            @if ($previa['adjuntos'] === [])
                <p class="text-muted mb-0">La solicitud no tiene documentos.</p>
            @else
                <form method="GET" action="{{ route('solicitudes.comite.previa', $solicitud) }}">
                    <input type="hidden" name="actualizar" value="1">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead>
                                <tr>
                                    <th>Adjuntar</th>
                                    <th>Tipo</th>
                                    <th>Archivo</th>
                                    <th class="text-end">Tamaño</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($previa['adjuntos'] as $adjunto)
                                    <tr>
                                        <td><input type="checkbox" name="documentos[]" value="{{ $adjunto['id'] }}" @checked($adjunto['incluido']) aria-label="Adjuntar {{ $adjunto['nombre'] }}"></td>
                                        <td>{{ $adjunto['tipo'] }}</td>
                                        <td>{{ $adjunto['nombre'] }}</td>
                                        <td class="text-end">{{ $adjunto['tamano_legible'] }}</td>
                                        <td>@if (! $adjunto['existe']) <span class="badge text-bg-danger">Archivo no disponible</span> @endif</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <p class="small mb-2">
                        Total adjunto: <strong>{{ number_format($previa['total_bytes'] / (1024 * 1024), 1, ',', '.') }} MB</strong>
                        de un máximo de {{ number_format($previa['limite_bytes'] / (1024 * 1024), 1, ',', '.') }} MB.
                    </p>
                    <button type="submit" class="btn btn-sm btn-outline-secondary">Actualizar vista previa</button>
                </form>
            @endif
        </div>
    </div>

    <form method="POST" action="{{ route('solicitudes.comite.enviar', $solicitud) }}" id="form-enviar-comite" class="card card-body mb-4">
        @csrf
        @foreach ($previa['adjuntos'] as $adjunto)
            @if ($adjunto['incluido'])
                <input type="hidden" name="documentos[]" value="{{ $adjunto['id'] }}">
            @endif
        @endforeach

        @if ($previa['es_reenvio'])
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" name="confirmar_reenvio" value="1" id="confirmar_reenvio" required>
                <label class="form-check-label" for="confirmar_reenvio">Confirmo el reenvío</label>
            </div>
        @endif

        <div>
            <button type="submit" id="boton-enviar-comite" class="btn btn-primary" @disabled(! $previa['puede_enviar'])>
                {{ $previa['es_reenvio'] ? 'Reenviar al comité' : 'Enviar al comité' }}{{ $previa['simulado'] ? ' (simulado)' : '' }}
            </button>
            <a href="{{ route('solicitudes.show', $solicitud) }}" class="btn btn-outline-secondary">Cancelar</a>
        </div>
    </form>

    <script>
        (function () {
            const formulario = document.getElementById('form-enviar-comite');
            const boton = document.getElementById('boton-enviar-comite');

            formulario.addEventListener('submit', function () {
                boton.disabled = true;
                boton.textContent = 'Enviando…';
            });
        })();
    </script>
@endsection
