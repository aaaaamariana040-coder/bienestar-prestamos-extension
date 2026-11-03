@extends('layouts.app')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">{{ $empleado->nombre_completo }}</h1>
        <div>
            @can('empleados.gestionar')
                <a href="{{ route('empleados.edit', $empleado) }}" class="btn btn-outline-secondary">Editar</a>
            @endcan
            <a href="{{ route('empleados.index') }}" class="btn btn-outline-secondary">Volver</a>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-4">Cédula</dt>
                <dd class="col-sm-8">{{ $empleado->cedula }}</dd>

                <dt class="col-sm-4">Tipo de vinculación</dt>
                <dd class="col-sm-8">{{ $empleado->tipo_vinculacion->etiqueta() }}</dd>

                <dt class="col-sm-4">Empleado de carrera</dt>
                <dd class="col-sm-8">{{ $empleado->de_carrera ? 'Sí' : 'No' }}</dd>

                <dt class="col-sm-4">Cargo</dt>
                <dd class="col-sm-8">{{ $empleado->cargo ?? '—' }}</dd>

                <dt class="col-sm-4">Dependencia</dt>
                <dd class="col-sm-8">{{ $empleado->dependencia ?? '—' }}</dd>

                <dt class="col-sm-4">Salario</dt>
                <dd class="col-sm-8">{{ number_format($empleado->salario, 2) }}</dd>

                @if ($capacidad['fuente'] === 'guardada')
                    <dt class="col-sm-4">Capacidad de endeudamiento (valor registrado)</dt>
                    <dd class="col-sm-8" id="capacidad-endeudamiento">{{ number_format($capacidad['total'], 2) }}</dd>
                @elseif (! $capacidad['calculable'])
                    <dt class="col-sm-4">Capacidad de endeudamiento (50 % del salario neto)</dt>
                    <dd class="col-sm-8" id="capacidad-endeudamiento">No se puede calcular (el empleado no tiene salario o falta el SMMLV del año).</dd>
                @else
                    <dt class="col-sm-4">Capacidad de endeudamiento (50 % del salario neto)</dt>
                    <dd class="col-sm-8" id="capacidad-endeudamiento">{{ number_format($capacidad['total'], 2) }}
                        <span class="text-muted small">Salario menos salud, pensión y fondo de solidaridad.</span></dd>
                @endif

                <dt class="col-sm-4">Fecha de vinculación</dt>
                <dd class="col-sm-8">{{ optional($empleado->fecha_vinculacion)->format('Y-m-d') ?? '—' }}</dd>

                <dt class="col-sm-4">Estado</dt>
                <dd class="col-sm-8">{{ $empleado->estado }}</dd>
            </dl>
        </div>
    </div>
@endsection
