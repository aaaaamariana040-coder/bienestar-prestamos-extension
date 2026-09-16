@extends('layouts.app')

@section('content')
    <h1 class="h3 mb-4">Editar presupuesto: {{ $presupuesto->tipoPrestamo->nombre }} ({{ $presupuesto->anio }})</h1>

    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $mensaje)
                    <li>{{ $mensaje }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('presupuestos.update', $presupuesto) }}">
        @csrf
        @method('PUT')

        <div class="row">
            <div class="col-md-4 mb-3">
                <label for="monto_asignado" class="form-label">Monto asignado</label>
                <input type="number" step="0.01" min="0" id="monto_asignado" name="monto_asignado" class="form-control"
                       value="{{ old('monto_asignado', $presupuesto->monto_asignado) }}" required>
            </div>

            <div class="col-md-4 mb-3">
                <label for="monto_comprometido" class="form-label">Monto comprometido</label>
                <input type="number" step="0.01" min="0" id="monto_comprometido" name="monto_comprometido" class="form-control"
                       value="{{ old('monto_comprometido', $presupuesto->monto_comprometido) }}" required>
                <div class="form-text">No puede superar el monto asignado.</div>
            </div>

            <div class="col-md-4 mb-3">
                <label class="form-label">Disponible actual</label>
                <input type="text" class="form-control" value="{{ number_format($presupuesto->monto_disponible, 2) }}" disabled>
                <div class="form-text">Se recalcula automáticamente al guardar (asignado − comprometido).</div>
            </div>
        </div>

        <div class="mb-3">
            <label for="motivo" class="form-label">Motivo del cambio</label>
            <textarea id="motivo" name="motivo" class="form-control" rows="2" required>{{ old('motivo') }}</textarea>
            <div class="form-text">Obligatorio: queda registrado en el historial del presupuesto.</div>
        </div>

        <button type="submit" class="btn btn-primary">Guardar cambios</button>
        <a href="{{ route('presupuestos.index', ['anio' => $presupuesto->anio]) }}" class="btn btn-outline-secondary">Cancelar</a>
    </form>
@endsection
