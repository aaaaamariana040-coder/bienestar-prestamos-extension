{{-- Marca «Enviada al comité» a partir del último envío; $envio es un EnvioComite. --}}
<span class="badge {{ $envio->simulado ? 'text-bg-warning' : 'text-bg-info' }}">Enviada al comité</span>
<span class="small text-muted">{{ $envio->enviado_en->format('Y-m-d H:i') }}@if ($envio->simulado) — simulado (no se envió) @endif</span>
