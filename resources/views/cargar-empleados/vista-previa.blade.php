@extends('layouts.app')

@section('content')
    @php
        $resumen = $analisis['resumen'];
        $etiquetas = [
            'correcta' => ['Correcta', 'success'],
            'advertencia' => ['Con advertencia', 'warning'],
            'error' => ['Con error', 'danger'],
            'omitida' => ['Omitida (ya existe)', 'secondary'],
        ];
    @endphp

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0">Vista previa de la carga de empleados</h1>
        <a href="{{ route('cargar-empleados.index') }}" class="btn btn-outline-secondary">Volver</a>
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

        <div class="d-flex flex-wrap gap-3 mb-3" id="resumen-vista-previa">
            <span class="badge text-bg-secondary fs-6">{{ $resumen['total'] }} fila(s)</span>
            <span class="badge text-bg-success fs-6">{{ $resumen['correctas'] }} correcta(s)</span>
            <span class="badge text-bg-warning fs-6">{{ $resumen['advertencias'] }} con advertencia</span>
            <span class="badge text-bg-danger fs-6">{{ $resumen['errores'] }} con error</span>
            <span class="badge text-bg-secondary fs-6">{{ $resumen['omitidas'] }} omitida(s) (ya existen)</span>
        </div>

        <div class="table-responsive">
            <table class="table table-striped bg-white align-middle small" id="tabla-vista-previa">
                <thead>
                    <tr>
                        <th>Fila</th>
                        <th>Cédula</th>
                        <th>Nombre</th>
                        <th>Tipo</th>
                        <th>De carrera</th>
                        <th class="text-end">Salario</th>
                        <th>Ingreso</th>
                        <th>Estado</th>
                        <th>Motivo</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($analisis['filas'] as $fila)
                        @php [$texto, $color] = $etiquetas[$fila['estado']]; @endphp
                        <tr>
                            <td>{{ $fila['linea'] }}</td>
                            <td>{{ $fila['datos']['cedula'] }}</td>
                            <td>{{ $fila['datos']['nombre_completo'] }}</td>
                            <td>{{ $fila['datos']['tipo_vinculacion'] }}</td>
                            <td>{{ $fila['datos']['de_carrera'] }}</td>
                            <td class="text-end">{{ $fila['datos']['salario'] }}</td>
                            <td>{{ $fila['datos']['fecha_vinculacion'] }}</td>
                            <td><span class="badge text-bg-{{ $color }}">{{ $texto }}</span></td>
                            <td>
                                @foreach ($fila['errores'] as $mensaje)
                                    <div class="text-danger">{{ $mensaje }}</div>
                                @endforeach
                                @foreach ($fila['advertencias'] as $mensaje)
                                    <div class="{{ $fila['estado'] === 'omitida' ? 'text-muted' : 'text-warning-emphasis' }}">{{ $mensaje }}</div>
                                @endforeach
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($puedeConfirmar)
            <div class="d-flex gap-2 align-items-center mt-3">
                <form method="POST" action="{{ route('cargar-empleados.confirmar') }}">
                    @csrf
                    <input type="hidden" name="token" value="{{ $token }}">
                    <input type="hidden" name="nombre_archivo" value="{{ $nombreArchivo }}">
                    <button type="submit" class="btn btn-primary" id="confirmar-carga">Confirmar carga de {{ $resumen['total'] - $resumen['omitidas'] }} empleado(s)</button>
                </form>
                <form method="POST" action="{{ route('cargar-empleados.cancelar') }}">
                    @csrf
                    <input type="hidden" name="token" value="{{ $token }}">
                    <button type="submit" class="btn btn-outline-secondary" id="cancelar-carga">Cancelar</button>
                </form>
                <span class="text-muted">Todo o nada: se crean todos los nuevos o ninguno. Las omitidas no se tocan.</span>
            </div>
        @else
            <div class="alert alert-danger mt-3" role="alert">
                @if ($resumen['errores'] > 0)
                    La carga está bloqueada: corrija las filas con error en el archivo y súbalo de nuevo. No se carga nada mientras haya errores.
                @else
                    No hay empleados nuevos para crear: todas las cédulas del archivo ya existen.
                @endif
            </div>
            <button type="button" class="btn btn-primary" id="confirmar-carga" disabled>Confirmar carga</button>
        @endif
    @endif
@endsection
