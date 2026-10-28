{{--
    Hoja impresa del préstamo: lo mismo que muestra la pantalla (prestamos/show), sin botones ni
    formularios. Vive en .solo-impresion (oculta en pantalla) y es lo único que se imprime. Mismo
    patrón que la hoja del simulador: A4 vertical, bordes finos en lugar de colores de fondo,
    encabezado de tabla repetido en cada página y pie fijo. Los estilos van aquí y no en
    tema-institucional.css porque solo se usan en esta pantalla.
    Variables: $prestamo, $tasa, $cierrePrimeraCuota, $cuotasExcluidas, $avisoCuotaExcluida.
--}}
@php
    $generadaEn = now();
    $usuarioImpresion = auth()->user();
    $fecha = fn ($valor): string => $valor?->format('d/m/Y') ?? '—';
    $dinero = fn ($valor): string => '$'.number_format((float) $valor, 2, ',', '.');
    $primerDesembolso = $prestamo->desembolsos->sortBy('numero_tramo')->first();
    $primeraCuota = $prestamo->pendiente_desembolso ? null : ($prestamo->inicio_primera_cuota ?? $prestamo->cuotas->first()?->fecha_vencimiento);
@endphp

<style>
    @page prestamo {
        size: A4 portrait;
        margin: 12mm 12mm 22mm;
    }

    @media print {
        .hoja-prestamo {
            page: prestamo;
            font-size: 9pt;
            line-height: 1.35;
            color: #000;
        }

        .hoja-prestamo h1,
        .hoja-prestamo h2,
        .hoja-prestamo p {
            margin: 0;
        }

        .hoja-encabezado {
            display: flex;
            align-items: center;
            gap: 12px;
            padding-bottom: 6px;
            border-bottom: 1.5pt solid #000;
        }

        .hoja-institucion {
            font-size: 12pt;
            font-weight: 700;
        }

        .hoja-area {
            font-size: 9pt;
        }

        .hoja-titulo {
            margin: 8px 0 6px !important;
            font-size: 16pt;
            text-align: center;
        }

        .hoja-bloque {
            margin-bottom: 8px;
        }

        .hoja-bloque h2 {
            margin-bottom: 3px;
            padding-bottom: 1px;
            border-bottom: 0.5pt solid #000;
            font-size: 10pt;
            font-weight: 700;
            break-after: avoid;
        }

        .hoja-datos {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1px 16px;
            margin: 0;
        }

        .hoja-datos > div {
            display: flex;
            justify-content: space-between;
            gap: 8px;
            border-bottom: 0.25pt dotted #777;
        }

        .hoja-datos dt {
            font-weight: 400;
        }

        .hoja-datos dd {
            margin: 0;
            text-align: right;
            font-weight: 600;
        }

        .hoja-alerta {
            margin-top: 4px !important;
            padding: 3px 6px;
            border: 1.5pt solid #000;
            font-size: 9pt;
            break-inside: avoid;
        }

        .hoja-nota {
            margin-top: 3px !important;
            font-size: 8pt;
        }

        .hoja-tabla {
            width: 100%;
            border-collapse: collapse;
        }

        .hoja-tabla th,
        .hoja-tabla td {
            padding: 1px 4px;
            border: 0.5pt solid #555;
            text-align: right;
            font-size: 8pt;
        }

        .hoja-tabla th:first-child,
        .hoja-tabla td:first-child,
        .hoja-tabla th:nth-child(2),
        .hoja-tabla td:nth-child(2) {
            text-align: left;
        }

        .hoja-tabla th {
            font-weight: 700;
        }

        /* Encabezado repetido en cada página y ninguna fila partida entre dos. */
        .hoja-tabla thead {
            display: table-header-group;
        }

        .hoja-tabla tr {
            break-inside: avoid;
        }

        /* Pie fijo: se repite en todas las páginas. */
        .hoja-pie {
            position: fixed;
            right: 0;
            bottom: 0;
            left: 0;
            padding-top: 3px;
            border-top: 0.5pt solid #000;
            font-size: 8pt;
            text-align: center;
            background: #fff;
        }
    }
</style>

<article class="hoja-prestamo solo-impresion" id="hoja-prestamo" data-titulo="Prestamo {{ $prestamo->id_prestamo }} - {{ $prestamo->cedula_empleado }}">
    <header class="hoja-encabezado">
        <x-logo :alto="64" />
        <div>
            <p class="hoja-institucion">Politécnico Colombiano Jaime Isaza Cadavid</p>
            <p class="hoja-area">Bienestar Social — Préstamos para el personal</p>
        </div>
    </header>

    <h1 class="hoja-titulo">Información del préstamo</h1>

    <section class="hoja-bloque">
        <h2>Empleado</h2>
        <dl class="hoja-datos">
            <div><dt>Nombre</dt><dd>{{ $prestamo->empleado->nombre_completo }}</dd></div>
            <div><dt>Cédula</dt><dd>{{ $prestamo->cedula_empleado }}</dd></div>
            <div><dt>Tipo de vinculación</dt><dd>{{ $prestamo->empleado->tipo_vinculacion->etiqueta() }}</dd></div>
        </dl>
    </section>

    <section class="hoja-bloque">
        <h2>Préstamo #{{ $prestamo->id_prestamo }}</h2>
        <dl class="hoja-datos">
            <div><dt>Modalidad</dt><dd>{{ $prestamo->tipoPrestamo->nombre }}</dd></div>
            <div><dt>Monto aprobado</dt><dd>{{ $dinero($prestamo->monto_aprobado) }}</dd></div>
            <div><dt>Plazo</dt><dd>{{ \App\Models\TipoPrestamo::plazoEnQuincenas($prestamo->num_cuotas) }}</dd></div>
            <div><dt>Tasa efectiva anual</dt><dd>{{ $tasa['tasa'] !== null ? number_format($tasa['tasa'], 4, ',', '.').' %' : '—' }}</dd></div>
            <div><dt>Cuota periódica</dt><dd>{{ $dinero($prestamo->cuota_periodica) }}</dd></div>
            <div><dt>Estado</dt><dd>{{ $prestamo->estado->value }}</dd></div>
            <div><dt>Fecha de autorización</dt><dd>{{ $fecha($prestamo->fecha_autorizacion) }}</dd></div>
            <div><dt>Fecha de desembolso</dt><dd>{{ $primerDesembolso ? $fecha($primerDesembolso->fecha) : 'Pendiente de desembolso' }}</dd></div>
            <div><dt>Primera cuota</dt><dd>{{ $primeraCuota ? $fecha($primeraCuota) : 'Desde el desembolso' }}</dd></div>
            <div><dt>Cierre de novedades de esa quincena</dt><dd>{{ $cierrePrimeraCuota ? $fecha($cierrePrimeraCuota) : '—' }}</dd></div>
            <div><dt>Cuotas pagadas</dt><dd>{{ $prestamo->progreso_cuotas }}</dd></div>
        </dl>
        @if ($tasa['nota'])
            <p class="hoja-nota">{{ $tasa['nota'] }}</p>
        @endif
        @if ($prestamo->motivo_primera_cuota)
            <p class="hoja-nota">Quincena elegida por el funcionario: {{ $prestamo->motivo_primera_cuota }}</p>
        @endif
    </section>

    <section class="hoja-bloque">
        <h2>Cuotas ({{ $prestamo->cuotas->count() }})</h2>
        <table class="hoja-tabla">
            <thead>
                <tr>
                    <th>N.º</th>
                    <th>Fecha de vencimiento</th>
                    <th>Capital</th>
                    <th>Interés</th>
                    <th>Valor</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($prestamo->cuotas as $cuota)
                    <tr>
                        <td>{{ $cuota->numero_cuota }}</td>
                        <td>{{ $cuota->fecha_vencimiento->format('d/m/Y') }}</td>
                        <td>{{ number_format($cuota->valor_capital, 2, ',', '.') }}</td>
                        <td>{{ number_format($cuota->valor_interes, 2, ',', '.') }}</td>
                        <td>{{ number_format($cuota->valor_total, 2, ',', '.') }}</td>
                        <td>{{ $cuota->estado->value }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @foreach ($prestamo->cuotas as $cuota)
            @if (isset($cuotasExcluidas[$cuota->id_cuota]))
                <p class="hoja-alerta">Cuota {{ $cuota->numero_cuota }}: {{ $avisoCuotaExcluida }} Motivo de la exclusión: {{ $cuotasExcluidas[$cuota->id_cuota] }}</p>
            @endif
        @endforeach
    </section>

    <section class="hoja-bloque">
        <h2>Pagos y abonos registrados</h2>
        <table class="hoja-tabla">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Tipo</th>
                    <th>Monto</th>
                    <th>Aplicado a interés</th>
                    <th>Aplicado a capital</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($prestamo->pagos as $pago)
                    <tr>
                        <td>{{ $pago->fecha_pago->format('d/m/Y') }}</td>
                        <td>{{ $pago->tipo->value }}</td>
                        <td>{{ number_format($pago->monto, 2, ',', '.') }}</td>
                        <td>{{ number_format($pago->monto_aplicado_interes, 2, ',', '.') }}</td>
                        <td>{{ number_format($pago->monto_aplicado_capital, 2, ',', '.') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5">No hay pagos registrados.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <section class="hoja-bloque">
        <h2>Desembolsos</h2>
        <table class="hoja-tabla">
            <thead>
                <tr>
                    <th>Tramo</th>
                    <th>Porcentaje</th>
                    <th>Valor</th>
                    <th>Fecha</th>
                    <th>Proveedor</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($prestamo->desembolsos as $desembolso)
                    <tr>
                        <td>{{ $desembolso->numero_tramo }}</td>
                        <td>{{ number_format($desembolso->porcentaje, 2, ',', '.') }}%</td>
                        <td>{{ number_format($desembolso->valor, 2, ',', '.') }}</td>
                        <td>{{ $fecha($desembolso->fecha) }}</td>
                        <td>{{ $desembolso->proveedor->nombre_empresa ?? 'Directo al empleado' }}</td>
                        <td>{{ $desembolso->estado->value }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6">Sin desembolsos registrados.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <footer class="hoja-pie">
        <p>Impreso el {{ $generadaEn->format('d/m/Y H:i') }} por {{ $usuarioImpresion->nombre_completo }}.</p>
    </footer>
</article>
