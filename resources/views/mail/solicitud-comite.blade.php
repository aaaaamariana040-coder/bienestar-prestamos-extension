@php
    $pesos = fn (?float $valor, int $decimales = 0): string => $valor === null ? 'No disponible' : '$'.number_format($valor, $decimales, ',', '.');
    $porcentaje = fn (?float $valor): string => $valor === null ? 'No registrada' : number_format($valor, 4, ',', '.').' % E.A.';
    $d = $datos;
@endphp
<!DOCTYPE html>
<html lang="es">
<body style="font-family: Arial, Helvetica, sans-serif; font-size: 14px; color: #222;">
    <p>{{ $introduccion }}</p>

    <table cellpadding="6" cellspacing="0" style="border-collapse: collapse; border: 1px solid #ccc;">
        <tr><th align="left" style="border: 1px solid #ccc;">Solicitud</th><td style="border: 1px solid #ccc;">#{{ $d['id_solicitud'] }} — {{ $d['fecha_solicitud'] }}</td></tr>
        <tr><th align="left" style="border: 1px solid #ccc;">Empleado</th><td style="border: 1px solid #ccc;">{{ $d['empleado'] }}</td></tr>
        <tr><th align="left" style="border: 1px solid #ccc;">Cédula</th><td style="border: 1px solid #ccc;">{{ $d['cedula'] }}</td></tr>
        <tr><th align="left" style="border: 1px solid #ccc;">Modalidad</th><td style="border: 1px solid #ccc;">{{ $d['modalidad'] }}</td></tr>
        <tr><th align="left" style="border: 1px solid #ccc;">Monto solicitado</th><td style="border: 1px solid #ccc;">{{ $pesos($d['monto'], 2) }}</td></tr>
        <tr><th align="left" style="border: 1px solid #ccc;">Plazo</th><td style="border: 1px solid #ccc;">{{ $d['plazo_legible'] }}</td></tr>
        <tr><th align="left" style="border: 1px solid #ccc;">Tasa</th><td style="border: 1px solid #ccc;">{{ $porcentaje($d['tasa_total']) }}@if (($d['tasa_puntos'] ?? 0) > 0) (incluye {{ number_format($d['tasa_puntos'], 2, ',', '.') }} puntos adicionales)@endif</td></tr>
        <tr><th align="left" style="border: 1px solid #ccc;">Cuota quincenal</th><td style="border: 1px solid #ccc;">{{ $pesos($d['cuota_quincenal'], 2) }}</td></tr>
        <tr><th align="left" style="border: 1px solid #ccc;">Cuota mensual (cuota quincenal × 2)</th><td style="border: 1px solid #ccc;">{{ $pesos($d['cuota_mensual'], 2) }}</td></tr>
        <tr><th align="left" style="border: 1px solid #ccc;">Capacidad de endeudamiento</th><td style="border: 1px solid #ccc;">{{ $pesos($d['capacidad_total'], 2) }}</td></tr>
        <tr><th align="left" style="border: 1px solid #ccc;">Comprometida en préstamos vigentes</th><td style="border: 1px solid #ccc;">{{ $pesos($d['capacidad_comprometida'], 2) }}</td></tr>
        <tr><th align="left" style="border: 1px solid #ccc;">Capacidad disponible</th><td style="border: 1px solid #ccc;">{{ $pesos($d['capacidad_disponible'], 2) }}</td></tr>
    </table>

    @if (! empty($d['observaciones']))
        <p><strong>Observaciones del sistema:</strong></p>
        <ul>
            @foreach ($d['observaciones'] as $observacion)
                <li>{{ $observacion }}</li>
            @endforeach
        </ul>
    @endif

    <p>
        @if ($documentos->isEmpty())
            Esta solicitud se envía sin documentos adjuntos.
        @else
            Documentos adjuntos ({{ $documentos->count() }}):
        @endif
    </p>
    @if ($documentos->isNotEmpty())
        <ul>
            @foreach ($documentos as $documento)
                <li>{{ $documento->tipoDocumento->nombre }}: {{ $documento->nombre_original }}</li>
            @endforeach
        </ul>
    @endif

    <p>{{ $cierre }}</p>
</body>
</html>
