@php
    $pesos = fn (float $valor, int $decimales = 0): string => '$'.number_format($valor, $decimales, ',', '.');
    $porcentaje = fn (float $valor, int $decimales = 2): string => number_format($valor, $decimales, ',', '.').' %';
    $e = $encabezado;
    $cap = $capacidad;
    $est = $cap['estimada'];
    $sug = $sugerencias;
@endphp

@foreach ($avisos as $aviso)
    <div class="alert alert-info py-2 mb-2"><strong>Aviso:</strong> {{ $aviso }}</div>
@endforeach

<div class="d-flex justify-content-end mb-2">
    <button type="button" class="btn btn-outline-secondary" id="imprimir-simulacion">Imprimir</button>
</div>

<div class="card mb-3">
    <div class="card-header">Resumen de la simulación — {{ $e['modalidad'] }}</div>
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-4">Monto</dt><dd class="col-sm-8">{{ $pesos($e['monto']) }}</dd>
            <dt class="col-sm-4">Tasa efectiva anual</dt><dd class="col-sm-8">{{ $porcentaje($e['tasa_efectiva_anual'], 4) }}</dd>
            <dt class="col-sm-4">Puntos adicionales</dt><dd class="col-sm-8">{{ $porcentaje($e['puntos'], 4) }}</dd>
            <dt class="col-sm-4">Tasa total (E.A.)</dt><dd class="col-sm-8">{{ $porcentaje($e['tasa_total'], 4) }}</dd>
            <dt class="col-sm-4">Tasa por quincena</dt><dd class="col-sm-8">{{ number_format($e['tasa_periodica_quincenal'], 6, ',', '.') }} % <span class="text-muted small">= (1 + E.A.)^(1/24) − 1</span></dd>
            <dt class="col-sm-4">Periodos</dt><dd class="col-sm-8">{{ $e['periodos'] }} quincenas ({{ $e['plazo_legible'] }})</dd>
        </dl>
        @if ($tasa['nota'])
            <div class="alert alert-info py-2 mt-2 mb-0" id="nota-tope-tasa">{{ $tasa['nota'] }}</div>
        @endif
        <hr>
        <dl class="row mb-0">
            <dt class="col-sm-4">Cuota fija quincenal</dt><dd class="col-sm-8 fs-5">{{ $pesos($cuota_quincenal, 2) }}</dd>
            <dt class="col-sm-4">Seguro (aparte)</dt><dd class="col-sm-8">{{ $pesos($seguro, 2) }}</dd>
            <dt class="col-sm-4">Cuota + seguro</dt><dd class="col-sm-8 fw-semibold">{{ $pesos($cuota_mas_seguro, 2) }}</dd>
            <dt class="col-sm-4">Total de intereses</dt><dd class="col-sm-8">{{ $pesos($total_intereses, 2) }}</dd>
            <dt class="col-sm-4">Total a pagar (sin seguro)</dt><dd class="col-sm-8">{{ $pesos($total_a_pagar, 2) }}</dd>
        </dl>
    </div>
</div>

<div class="card mb-3 {{ $cap['cabe'] ? 'border-success' : 'border-danger' }}">
    <div class="card-header">Comparación con la capacidad de endeudamiento</div>
    <div class="card-body">
        @if ($cap['cabe'])
            <div class="alert alert-success py-2">La cuota <strong>cabe</strong> en la capacidad disponible (margen de {{ $pesos($cap['margen'], 2) }}).</div>
        @else
            <div class="alert alert-danger py-2"><strong>La cuota no cabe</strong> en la capacidad disponible: faltan {{ $pesos(abs($cap['margen']), 2) }} al mes.</div>
        @endif

        @if ($cap['veredictos_distintos'])
            <div class="alert alert-warning py-2">
                <strong>Atención: las dos capacidades dan un veredicto distinto.</strong>
                Con descuentos de ley (estimada) la cuota {{ $est['cabe'] ? 'cabe' : 'no cabe' }}; con la capacidad guardada del empleado (la que usa la aprobación) {{ $cap['guardada']['cabe'] ? 'cabe' : 'no cabe' }}.
                La aprobación de la solicitud decide con la capacidad guardada.
            </div>
        @endif

        <p class="mb-2">
            Cuota mensual = cuota quincenal × 2 = <strong>{{ $pesos($cap['cuota_mensual'], 2) }}</strong>.
            El seguro no se incluye en esta comparación (pendiente de confirmar con Bienestar).
        </p>

        <div class="row g-3">
            <div class="col-lg-7">
                <h3 class="h6">
                    Capacidad estimada con descuentos de ley
                    @if ($cap['usada'] === 'estimada') <span class="badge text-bg-primary">decide el veredicto</span> @endif
                </h3>
                <table class="table table-sm mb-2">
                    <tbody>
                        <tr><td>Salario</td><td class="text-end">{{ $pesos($est['salario'], 2) }}</td></tr>
                        <tr><td>− Salud ({{ $porcentaje($est['salud']['porcentaje'], 2) }})</td><td class="text-end">{{ $pesos($est['salud']['valor'], 2) }}</td></tr>
                        <tr><td>− Pensión ({{ $porcentaje($est['pension']['porcentaje'], 2) }})</td><td class="text-end">{{ $pesos($est['pension']['valor'], 2) }}</td></tr>
                        <tr><td>− Fondo de solidaridad pensional ({{ $porcentaje($est['solidaridad']['porcentaje'], 2) }})</td><td class="text-end">{{ $pesos($est['solidaridad']['valor'], 2) }}</td></tr>
                        <tr><td>− Retención en la fuente (dato manual)</td><td class="text-end">{{ $pesos($est['retencion'], 2) }}</td></tr>
                        <tr class="fw-semibold"><td>Base (salario − descuentos)</td><td class="text-end">{{ $pesos($est['base'], 2) }}</td></tr>
                        <tr class="fw-semibold"><td>50 % de la base = capacidad estimada</td><td class="text-end">{{ $pesos($est['capacidad'], 2) }}</td></tr>
                        <tr><td>− Comprometida en préstamos vigentes</td><td class="text-end">{{ $pesos($cap['comprometida'], 2) }}</td></tr>
                        <tr class="fw-semibold"><td>Disponible (estimada)</td><td class="text-end">{{ $pesos($est['disponible'], 2) }}</td></tr>
                    </tbody>
                </table>
                <p class="small text-muted mb-0">Porcentajes por verificar con la norma vigente. El Acuerdo 19/2022 (art. 10, par. 3) indica que la seguridad social y la retención no se cuentan; Bienestar debe confirmarlo.</p>
            </div>
            <div class="col-lg-5">
                <h3 class="h6">
                    Capacidad guardada del empleado
                    @if ($cap['usada'] === 'guardada') <span class="badge text-bg-primary">decide el veredicto</span> @endif
                </h3>
                <table class="table table-sm mb-0">
                    <tbody>
                        <tr><td>Capacidad guardada</td><td class="text-end">{{ $pesos($cap['guardada']['capacidad'], 2) }}</td></tr>
                        <tr><td>− Comprometida en préstamos vigentes</td><td class="text-end">{{ $pesos($cap['comprometida'], 2) }}</td></tr>
                        <tr class="fw-semibold"><td>Disponible (guardada)</td><td class="text-end">{{ $pesos($cap['guardada']['disponible'], 2) }}</td></tr>
                        <tr><td>¿Cabe la cuota?</td><td class="text-end">{{ $cap['guardada']['cabe'] ? 'Sí' : 'No' }}</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<details class="card mb-3" @if (! $cap['cabe']) open @endif>
    <summary class="card-header">Sugerencias para que quepa</summary>
    <div class="card-body">
        <p class="small text-muted">Calculadas con la capacidad disponible que decide el veredicto: cuota máxima de {{ $pesos($sug['cuota_maxima_quincenal'], 2) }} por quincena.</p>

        <h3 class="h6">Plazo mínimo para el monto pedido ({{ $pesos($e['monto']) }})</h3>
        @if ($sug['plazo_minimo']['posible'])
            <p>{{ $sug['plazo_minimo']['quincenas'] }} quincenas ({{ \App\Models\TipoPrestamo::equivalenciaDelPlazo($sug['plazo_minimo']['quincenas']) }}), con una cuota de {{ $pesos($sug['plazo_minimo']['cuota_quincenal'], 2) }} por quincena.</p>
        @else
            <p class="text-danger">{{ $sug['plazo_minimo']['motivo'] }}</p>
        @endif

        <h3 class="h6">Monto máximo que cabe con {{ $e['periodos'] }} quincenas</h3>
        @if ($sug['monto_maximo']['posible'])
            <p class="mb-1">{{ $pesos($sug['monto_maximo']['monto']) }}, con una cuota de {{ $pesos($sug['monto_maximo']['cuota_quincenal'], 2) }} por quincena.</p>
            @if ($sug['monto_maximo']['cuantia_maxima'] !== null)
                <p class="small text-muted mb-0">La cuantía máxima de su tramo salarial en esta modalidad es {{ $pesos($sug['monto_maximo']['cuantia_maxima']) }}.</p>
            @endif
        @else
            <p class="text-danger mb-0">{{ $sug['monto_maximo']['motivo'] }}</p>
        @endif
    </div>
</details>

@include('simulador.partials.tabla-amortizacion', ['tabla' => $tabla_amortizacion])

<div class="mb-4">
    @if ($tasa['editada'])
        <div class="alert alert-warning py-2">
            Editaste la tasa. La solicitud <strong>no</strong> usará la tasa simulada ({{ $porcentaje($e['tasa_total'], 4) }}), sino la oficial de tasas_int
            @if ($tasa['oficial_total'] !== null) ({{ $porcentaje($tasa['oficial_total'], 4) }}) @else (aún sin registrar para este año) @endif
            al aprobarse.
        </div>
    @endif
    <a href="{{ $enlaceSolicitud }}" class="btn btn-primary" id="crear-solicitud">Crear solicitud con estos datos</a>
    <span class="small text-muted ms-2">Solo abre el formulario con cédula, modalidad, monto y plazo; no registra nada.</span>
</div>
