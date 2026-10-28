{{--
    Hoja impresa de la simulación, lista para archivar en la carpeta física del crédito.
    Usa las mismas variables y el mismo formato que partials/resultado.blade.php, así que las
    cifras impresas son las de la pantalla. Se oculta en pantalla (.solo-impresion) y es lo
    único que se imprime en esta página. Ver la sección «Simulador: hoja impresa» de
    public/css/tema-institucional.css.
--}}
@php
    $pesos = fn (float $valor, int $decimales = 0): string => '$'.number_format($valor, $decimales, ',', '.');
    $porcentaje = fn (float $valor, int $decimales = 2): string => number_format($valor, $decimales, ',', '.').' %';
    $e = $encabezado;
    $cap = $capacidad;
    $est = $cap['estimada'];
    $fechaHora = $generadaEn->format('d/m/Y H:i');
    $nombreArchivo = 'Simulacion de credito - '.$empleado->cedula.' - '.$generadaEn->format('Y-m-d H-i');
@endphp

<article class="hoja-simulacion" data-titulo="{{ $nombreArchivo }}">
    <header class="hoja-encabezado">
        <x-logo :alto="64" />
        <div>
            <p class="hoja-institucion">Politécnico Colombiano Jaime Isaza Cadavid</p>
            <p class="hoja-area">Bienestar Social — Préstamos para el personal</p>
        </div>
    </header>

    <h1 class="hoja-titulo">Simulación de crédito</h1>

    <section class="hoja-bloque">
        <h2>Empleado</h2>
        <dl class="hoja-datos">
            <div><dt>Nombre</dt><dd>{{ $empleado->nombre_completo }}</dd></div>
            <div><dt>Cédula</dt><dd>{{ $empleado->cedula }}</dd></div>
            <div><dt>Tipo de vinculación</dt><dd>{{ $empleado->tipo_vinculacion->etiqueta() }}</dd></div>
        </dl>
    </section>

    <section class="hoja-bloque">
        <h2>Condiciones simuladas</h2>
        <dl class="hoja-datos">
            <div><dt>Modalidad</dt><dd>{{ $e['modalidad'] }}</dd></div>
            <div><dt>Monto</dt><dd>{{ $pesos($e['monto']) }}</dd></div>
            <div><dt>Plazo</dt><dd>{{ $e['periodos'] }} quincenas ({{ $e['plazo_legible'] }})</dd></div>
            <div><dt>Tasa efectiva anual</dt><dd>{{ $porcentaje($e['tasa_efectiva_anual'], 4) }}</dd></div>
            <div><dt>Puntos adicionales</dt><dd>{{ $porcentaje($e['puntos'], 4) }}</dd></div>
            <div><dt>Tasa total (E.A.)</dt><dd>{{ $porcentaje($e['tasa_total'], 4) }}</dd></div>
            <div><dt>Tasa por quincena</dt><dd>{{ number_format($e['tasa_periodica_quincenal'], 6, ',', '.') }} %</dd></div>
        </dl>

        @if ($tasa['nota'])
            <p class="hoja-alerta">{{ $tasa['nota'] }}</p>
        @endif

        @if ($tasa['nota_tramo'])
            <p class="hoja-alerta">{{ $tasa['nota_tramo'] }}</p>
        @endif

        @if ($tasa['editada'])
            <p class="hoja-alerta">
                <strong>TASA CORREGIDA A MANO.</strong>
                Esta simulación usa una tasa distinta de la oficial
                @if ($tasa['oficial_total'] !== null)
                    ({{ $porcentaje($tasa['oficial_total'], 4) }} E.A. en total).
                @else
                    (no hay tasa oficial registrada para esta modalidad y año).
                @endif
                La solicitud no usará esta tasa, sino la oficial vigente al aprobarse.
            </p>
        @else
            <p class="hoja-nota">Tasa oficial de la modalidad, sin correcciones.</p>
        @endif
    </section>

    <section class="hoja-bloque">
        <h2>Cuota</h2>
        <dl class="hoja-datos">
            <div><dt>Cuota fija quincenal</dt><dd>{{ $pesos($cuota_quincenal, 2) }}</dd></div>
            <div>
                <dt>Seguro estimado (aparte)</dt>
                <dd>
                    {{ $pesos($seguro, 2) }}
                    <span class="hoja-nota">Estimación; el valor real lo calcula el área de seguros.</span>
                    @include('simulador.partials.seguro-detalle', ['detalle' => $seguro_detalle, 'pesos' => $pesos])
                </dd>
            </div>
            <div><dt>{{ $seguro_detalle['es_vivienda'] ? 'Cuota estimada con seguros' : 'Cuota + seguro' }}</dt><dd><strong>{{ $pesos($cuota_mas_seguro, 2) }}</strong></dd></div>
            @if ($primera_cuota['fecha_desembolso'] !== null)
                <div><dt>Primera cuota</dt><dd>Primera cuota estimada: {{ \Carbon\Carbon::parse($primera_cuota['fecha'])->format('d/m/Y') }} <span class="hoja-nota">Estimación según la fecha de desembolso indicada; la fecha real se define al desembolsar.</span></dd></div>
            @endif
            <div><dt>Total de intereses</dt><dd>{{ $pesos($total_intereses, 2) }}</dd></div>
            <div><dt>Total a pagar (sin seguro)</dt><dd>{{ $pesos($total_a_pagar, 2) }}</dd></div>
        </dl>
    </section>

    <section class="hoja-bloque">
        <h2>Capacidad de endeudamiento</h2>
        <p class="hoja-veredicto {{ $cap['cabe'] ? 'cabe' : 'no-cabe' }}">
            @if ($cap['cabe'])
                Veredicto: la cuota CABE en la capacidad disponible (margen de {{ $pesos($cap['margen'], 2) }}).
            @else
                Veredicto: la cuota NO CABE en la capacidad disponible (faltan {{ $pesos(abs($cap['margen']), 2) }} al mes).
            @endif
        </p>
        <p>
            @if ($cap['seguro_mensual'] > 0)
                Cuota mensual con seguros = (cuota quincenal + seguros) × 2 = <strong>{{ $pesos($cap['cuota_mensual'], 2) }}</strong>
                (cuota {{ $pesos($cap['cuota_sin_seguro_mensual'], 2) }} + seguros estimados {{ $pesos($cap['seguro_mensual'], 2) }}).
            @else
                Cuota mensual = cuota quincenal × 2 = <strong>{{ $pesos($cap['cuota_mensual'], 2) }}</strong>.
                El seguro solo se incluye en esta comparación en vivienda.
            @endif
            Decide el veredicto la capacidad {{ $cap['usada'] === 'estimada' ? 'estimada con descuentos de ley' : 'guardada del empleado' }}.
        </p>
        @if ($cap['veredictos_distintos'])
            <p class="hoja-alerta">
                <strong>Las dos capacidades dan un veredicto distinto.</strong>
                Con descuentos de ley (estimada) la cuota {{ $est['cabe'] ? 'cabe' : 'no cabe' }}; con la capacidad guardada {{ $cap['guardada']['cabe'] ? 'cabe' : 'no cabe' }}.
            </p>
        @endif

        <div class="hoja-columnas">
            <table class="hoja-tabla-simple">
                <caption>Capacidad estimada con descuentos de ley</caption>
                <tbody>
                    <tr><td>Salario</td><td>{{ $pesos($est['salario'], 2) }}</td></tr>
                    <tr><td>− Salud ({{ $porcentaje($est['salud']['porcentaje'], 2) }})</td><td>{{ $pesos($est['salud']['valor'], 2) }}</td></tr>
                    <tr><td>− Pensión ({{ $porcentaje($est['pension']['porcentaje'], 2) }})</td><td>{{ $pesos($est['pension']['valor'], 2) }}</td></tr>
                    <tr><td>− Fondo de solidaridad pensional ({{ $porcentaje($est['solidaridad']['porcentaje'], 2) }})</td><td>{{ $pesos($est['solidaridad']['valor'], 2) }}</td></tr>
                    <tr><td>− Retención en la fuente (dato manual)</td><td>{{ $pesos($est['retencion'], 2) }}</td></tr>
                    <tr class="total"><td>Base (salario − descuentos)</td><td>{{ $pesos($est['base'], 2) }}</td></tr>
                    <tr class="total"><td>50 % de la base = capacidad estimada</td><td>{{ $pesos($est['capacidad'], 2) }}</td></tr>
                    <tr><td>− Comprometida en préstamos vigentes</td><td>{{ $pesos($cap['comprometida'], 2) }}</td></tr>
                    <tr class="total"><td>Disponible (estimada)</td><td>{{ $pesos($est['disponible'], 2) }}</td></tr>
                    <tr><td>¿Cabe la cuota?</td><td>{{ $est['cabe'] ? 'Sí' : 'No' }}</td></tr>
                </tbody>
            </table>
            <table class="hoja-tabla-simple">
                <caption>Capacidad guardada del empleado</caption>
                <tbody>
                    <tr><td>Capacidad guardada</td><td>{{ $pesos($cap['guardada']['capacidad'], 2) }}</td></tr>
                    <tr><td>− Comprometida en préstamos vigentes</td><td>{{ $pesos($cap['comprometida'], 2) }}</td></tr>
                    <tr class="total"><td>Disponible (guardada)</td><td>{{ $pesos($cap['guardada']['disponible'], 2) }}</td></tr>
                    <tr><td>¿Cabe la cuota?</td><td>{{ $cap['guardada']['cabe'] ? 'Sí' : 'No' }}</td></tr>
                </tbody>
            </table>
        </div>
    </section>

    @if (count($avisos) > 0)
        <section class="hoja-bloque">
            <h2>Avisos</h2>
            <ul>
                @foreach ($avisos as $aviso)
                    <li>{{ $aviso }}</li>
                @endforeach
            </ul>
        </section>
    @endif

    <section class="hoja-bloque hoja-amortizacion">
        <h2>Tabla de amortización ({{ count($tabla_amortizacion) }} cuotas) — fechas estimadas</h2>
        <p>{{ $primera_cuota['fecha_desembolso'] !== null ? 'Estimación según la fecha de desembolso indicada; la fecha real se define al desembolsar.' : 'La fecha real de la primera cuota se define al desembolsar.' }}</p>
        <table class="hoja-tabla">
            <thead>
                <tr>
                    <th>Periodo</th>
                    <th>Fecha estimada</th>
                    <th>Interés</th>
                    <th>Capital</th>
                    <th>Cuota</th>
                    <th>Saldo</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($tabla_amortizacion as $fila)
                    <tr>
                        <td>{{ $fila['numero'] }}</td>
                        <td>{{ \Carbon\Carbon::parse($fila['fecha'])->format('d/m/Y') }}</td>
                        <td>${{ number_format($fila['interes'], 2, ',', '.') }}</td>
                        <td>${{ number_format($fila['capital'], 2, ',', '.') }}</td>
                        <td>${{ number_format($fila['cuota'], 2, ',', '.') }}</td>
                        <td>${{ number_format($fila['saldo'], 2, ',', '.') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="hoja-nota">El saldo es el que queda después de pagar la cuota del periodo. Las fechas se calculan como si el préstamo se autorizara el día de la simulación.</p>
    </section>

    <footer class="hoja-pie">
        <p>Simulación realizada el {{ $fechaHora }} por {{ $usuario->nombre_completo }}.</p>
        <p><strong>Simulación informativa; no constituye aprobación del crédito.</strong></p>
    </footer>
</article>
