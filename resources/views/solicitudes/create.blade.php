@extends('layouts.app')

@section('content')
    <h1 class="h3 mb-4">Registrar solicitud de crédito</h1>

    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $mensaje)
                    <li>{{ $mensaje }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('solicitudes.store') }}">
        @csrf

        <div class="row">
            <div class="col-md-6 mb-3">
                <label for="cedula_empleado" class="form-label">Empleado</label>
                <select id="cedula_empleado" name="cedula_empleado" class="form-select" required>
                    <option value="">Selecciona un empleado…</option>
                    @foreach ($empleados as $empleado)
                        <option value="{{ $empleado->cedula }}" @selected(old('cedula_empleado') === $empleado->cedula)>
                            {{ $empleado->nombre_completo }} ({{ $empleado->cedula }})
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="col-md-6 mb-3">
                <label for="id_tipo" class="form-label">Modalidad</label>
                <select id="id_tipo" name="id_tipo" class="form-select" required>
                    <option value="">Selecciona una modalidad…</option>
                    @foreach ($tiposPrestamo as $tipoPrestamo)
                        <option value="{{ $tipoPrestamo->id_tipo }}" @selected((string) old('id_tipo') === (string) $tipoPrestamo->id_tipo)>
                            {{ $tipoPrestamo->nombre }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="col-md-4 mb-3">
                <label for="monto_solicitado" class="form-label">Monto solicitado</label>
                <input type="text" inputmode="decimal" id="monto_solicitado" name="monto_solicitado" class="form-control"
                       value="{{ old('monto_solicitado') }}" required>
            </div>

            <div class="col-md-4 mb-3">
                <label for="plazo_solicitado" class="form-label">Plazo (cuotas quincenales)</label>
                <input type="number" min="1" id="plazo_solicitado" name="plazo_solicitado" class="form-control"
                       value="{{ old('plazo_solicitado') }}" required>
            </div>

            <div class="col-md-4 mb-3">
                <label for="fecha_solicitud" class="form-label">Fecha de solicitud</label>
                <input type="date" id="fecha_solicitud" name="fecha_solicitud" class="form-control"
                       value="{{ old('fecha_solicitud', now()->toDateString()) }}" required>
            </div>
        </div>

        <button type="submit" class="btn btn-primary">Registrar</button>
        <a href="{{ route('solicitudes.index') }}" class="btn btn-outline-secondary">Cancelar</a>
    </form>
@endsection
