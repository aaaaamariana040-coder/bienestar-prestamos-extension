@extends('layouts.app')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">{{ $empleado->nombre_completo }}</h1>
        <div>
            <a href="{{ route('empleados.edit', $empleado) }}" class="btn btn-outline-secondary">Editar</a>
            <a href="{{ route('empleados.index') }}" class="btn btn-outline-secondary">Volver</a>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-4">Cédula</dt>
                <dd class="col-sm-8">{{ $empleado->cedula }}</dd>

                <dt class="col-sm-4">Tipo de vinculación</dt>
                <dd class="col-sm-8">{{ $empleado->tipo_vinculacion }}</dd>

                <dt class="col-sm-4">Cargo</dt>
                <dd class="col-sm-8">{{ $empleado->cargo ?? '—' }}</dd>

                <dt class="col-sm-4">Dependencia</dt>
                <dd class="col-sm-8">{{ $empleado->dependencia ?? '—' }}</dd>

                <dt class="col-sm-4">Salario</dt>
                <dd class="col-sm-8">{{ number_format($empleado->salario, 2) }}</dd>

                <dt class="col-sm-4">Capacidad de endeudamiento</dt>
                <dd class="col-sm-8">{{ number_format($empleado->capacidad_endeudamiento ?? 0, 2) }}</dd>

                <dt class="col-sm-4">Fecha de vinculación</dt>
                <dd class="col-sm-8">{{ optional($empleado->fecha_vinculacion)->format('Y-m-d') ?? '—' }}</dd>

                <dt class="col-sm-4">Estado</dt>
                <dd class="col-sm-8">{{ $empleado->estado }}</dd>

                <dt class="col-sm-4">Cuenta de usuario</dt>
                <dd class="col-sm-8">{{ $empleado->usuario ? 'Sí (' . $empleado->usuario->rol->value . ')' : 'No tiene' }}</dd>
            </dl>
        </div>
    </div>
@endsection
