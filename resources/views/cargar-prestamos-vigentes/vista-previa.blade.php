@extends('layouts.app')

@section('content')
    @php
        $resumen = $analisis['resumen'];
        $puedeConfirmar = $token !== null && $resumen['total'] > 0 && $resumen['errores'] === 0;
        $etiquetas = ['correcta' => ['Correcta', 'success'], 'advertencia' => ['Con advertencia', 'warning'], 'error' => ['Con error', 'danger']];
    @endphp

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0">Vista previa de la carga</h1>
        <a href="{{ route('cargar-prestamos-vigentes.index') }}" class="btn btn-outline-secondary">Volver</a>
    </div>

    @if ($analisis['errores_archivo'] !== [])
        <div class="alert alert-danger" role="alert">
            <ul class="mb-0">
                @foreach ($analisis['errores_archivo'] as $mensaje)
                    <li>{{ $mensaje }}</li>
                @endforeach
            </ul>
        </div>
    @else
        <p class="text-muted">Archivo «{{ $nombreArchivo }}». Esta es solo una vista previa: todavía no se guardó nada.</p>

        <div class="d-flex gap-3 mb-3" id="resumen-vista-previa">
            <span class="badge text-bg-secondary fs-6">{{ $resumen['total'] }} fila(s)</span>
            <span class="badge text-bg-success fs-6">{{ $resumen['correctas'] }} correcta(s)</span>
            <span class="badge text-bg-warning fs-6">{{ $resumen['advertencias'] }} con advertencia</span>
            <span class="badge text-bg-danger fs-6">{{ $resumen['errores'] }} con error</span>
        </div>

        <div class="table-responsive">
            <table class="table table-striped bg-white align-middle small" id="tabla-vista-previa">
                <thead>
                    <tr>
                        <th>Fila</th>
                        <th>Cédula</th>
                        <th>Modalidad</th>
                        <th class="text-end">Monto inicial</th>
                        <th>Desembolso</th>
                        <th class="text-end">Plazo</th>
                        <th class="text-end">Pagadas</th>
                        <th class="text-end">Tasa E.A.</th>
                        <th class="text-end">Cuota</th>
                        <th class="text-end">Saldo calculado</th>
                        <th>Estado</th>
                        <th>Mensajes</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($analisis['filas'] as $fila)
                        @php
                            [$texto, $color] = $etiquetas[$fila['estado']];
                            $calculo = $fila['calculo'];
                        @endphp
                        <tr>
                            <td>{{ $fila['linea'] }}</td>
                            <td>{{ $fila['datos']['cedula'] }}</td>
                            <td>{{ $fila['datos']['modalidad'] }}</td>
                            <td class="text-end">{{ $fila['datos']['monto_inicial'] }}</td>
                            <td>{{ $fila['datos']['fecha_desembolso'] }}</td>
                            <td class="text-end">{{ $fila['datos']['plazo_quincenas'] }}</td>
                            <td class="text-end">{{ $fila['datos']['cuotas_pagadas'] }}</td>
                            <td class="text-end">{{ $calculo ? number_format($calculo['tasa'], 4, ',', '.') : '—' }}</td>
                            <td class="text-end">{{ $calculo ? number_format($calculo['cuota'], 2, ',', '.') : '—' }}</td>
                            <td class="text-end">{{ $calculo ? number_format($calculo['saldo'], 2, ',', '.') : '—' }}</td>
                            <td><span class="badge text-bg-{{ $color }}">{{ $texto }}</span></td>
                            <td>
                                @foreach ($fila['errores'] as $mensaje)
                                    <div class="text-danger">{{ $mensaje }}</div>
                                @endforeach
                                @foreach ($fila['advertencias'] as $mensaje)
                                    <div class="text-warning-emphasis">{{ $mensaje }}</div>
                                @endforeach
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($puedeConfirmar)
            <form method="POST" action="{{ route('cargar-prestamos-vigentes.confirmar') }}" class="mt-3">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <input type="hidden" name="nombre_archivo" value="{{ $nombreArchivo }}">
                <button type="submit" class="btn btn-primary" id="confirmar-carga">Confirmar carga de {{ $resumen['total'] }} préstamo(s)</button>
                <span class="text-muted ms-2">Todo o nada: se crean todos o ninguno. Podrá revertir el lote mientras no tenga pagos.</span>
            </form>
        @else
            <div class="alert alert-danger mt-3" role="alert">
                La carga está bloqueada: corrija las filas con error en el archivo y súbalo de nuevo. No se carga nada mientras haya errores.
            </div>
            <button type="button" class="btn btn-primary" id="confirmar-carga" disabled>Confirmar carga</button>
        @endif
    @endif
@endsection
