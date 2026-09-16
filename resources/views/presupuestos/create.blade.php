@extends('layouts.app')

@section('content')
    <h1 class="h3 mb-4">Registrar presupuesto</h1>

    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $mensaje)
                    <li>{{ $mensaje }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('presupuestos.store') }}">
        @csrf

        <div class="row">
            <div class="col-md-4 mb-3">
                <label for="anio" class="form-label">Año</label>
                <input type="number" id="anio" name="anio" class="form-control"
                       value="{{ old('anio', request()->query('anio', now()->year)) }}" required>
            </div>

            <div class="col-md-4 mb-3">
                <label for="id_tipo" class="form-label">Modalidad</label>
                <select id="id_tipo" name="id_tipo" class="form-select" required>
                    <option value="">Selecciona una modalidad…</option>
                    @foreach ($tiposPrestamo as $tipoPrestamo)
                        <option value="{{ $tipoPrestamo->id_tipo }}"
                                @selected((string) old('id_tipo', request()->query('id_tipo')) === (string) $tipoPrestamo->id_tipo)>
                            {{ $tipoPrestamo->nombre }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="col-md-4 mb-3">
                <label for="monto_asignado" class="form-label">Monto asignado</label>
                <input type="number" step="0.01" min="0" id="monto_asignado" name="monto_asignado" class="form-control"
                       value="{{ old('monto_asignado', request()->query('monto_sugerido')) }}" required>
            </div>
        </div>

        <button type="submit" class="btn btn-primary">Registrar</button>
        <a href="{{ route('presupuestos.index') }}" class="btn btn-outline-secondary">Cancelar</a>
    </form>
@endsection
