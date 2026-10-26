{{--
    Desglose del seguro estimado: lo comparten la pantalla y la hoja impresa.
    Recibe $detalle (SimuladorCreditoService, clave seguro_detalle) y $pesos.
--}}
@php
    $formato = fn (float $tasa): string => \App\Models\PrimaSeguro::formatear($tasa);
@endphp

@if ($detalle['es_vivienda'])
    <div class="small text-muted" id="desglose-seguro">
        Vida de deudores {{ $pesos($detalle['vida'], 2) }}
        ·
        @if ($detalle['incendio'] > 0)
            incendio y terremoto {{ $pesos($detalle['incendio'], 2) }}
        @else
            incendio y terremoto: sin prima definida
        @endif
    </div>
@endif

@if ($detalle['origen_vida'] === 'respaldo')
    <div class="small text-muted" id="aviso-seguro-respaldo">
        No hay prima de seguro registrada: se usó el valor de respaldo ({{ $formato($detalle['tasa_vida']) }}).
    </div>
@elseif ($detalle['origen_vida'] === 'reciente')
    <div class="small text-muted" id="aviso-seguro-reciente">
        No hay prima de {{ $detalle['anio_simulacion'] }}: se usó la de {{ $detalle['anio_vida'] }}.
    </div>
@endif
