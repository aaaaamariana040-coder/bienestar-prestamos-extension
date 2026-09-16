@extends('layouts.app')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">Reparto sugerido de vivienda</h1>
        <a href="{{ route('presupuestos.index', ['anio' => $anio]) }}" class="btn btn-outline-secondary">Volver</a>
    </div>

    <p class="text-muted">
        Según el Acuerdo Directivo No. 19/2022 (Art. 18): del monto total destinado a vivienda,
        70% va a compra + construcción (15% de eso para construcción) y el 30% restante se reparte
        en partes iguales entre deshipoteca, reparaciones/mejoras y cambio de vivienda.
        Esto es solo una sugerencia — el registro de cada presupuesto sigue siendo manual.
    </p>

    <form method="GET" action="{{ route('presupuestos.sugerencia-vivienda') }}" class="row g-2 align-items-end mb-4">
        <div class="col-auto">
            <label for="anio" class="form-label">Año</label>
            <input type="number" id="anio" name="anio" class="form-control" value="{{ $anio }}">
        </div>
        <div class="col-auto">
            <label for="monto_total_vivienda" class="form-label">Monto total destinado a vivienda</label>
            <input type="number" step="0.01" min="0" id="monto_total_vivienda" name="monto_total_vivienda" class="form-control"
                   value="{{ $montoTotalVivienda }}">
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-primary">Calcular</button>
        </div>
    </form>

    @if ($sugerencias)
        <table class="table table-striped bg-white">
            <thead>
                <tr>
                    <th>Modalidad</th>
                    <th>Monto sugerido</th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($sugerencias as $nombre => $montoSugerido)
                    <tr>
                        <td>{{ $nombre }}</td>
                        <td>{{ number_format($montoSugerido, 2) }}</td>
                        <td class="text-end">
                            @if ($tiposVivienda->has($nombre))
                                <a href="{{ route('presupuestos.create', ['anio' => $anio, 'id_tipo' => $tiposVivienda[$nombre], 'monto_sugerido' => $montoSugerido]) }}"
                                   class="btn btn-sm btn-primary">
                                    Registrar
                                </a>
                            @else
                                <span class="text-muted">Modalidad no encontrada en el catálogo</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endsection
