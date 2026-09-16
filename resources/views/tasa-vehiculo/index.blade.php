@extends('layouts.app')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">Tasa de vehículo</h1>
        <a href="{{ route('tipos-prestamo.index') }}" class="btn btn-outline-secondary">Volver a modalidades</a>
    </div>

    <p class="text-muted">
        Matriz fija según el Acuerdo Directivo No. 19/2022 (Art. 10): puntos adicionales sobre el IPC del año,
        según el plazo elegido y si es la primera o una posterior solicitud de crédito de vehículo.
        La tasa efectiva = IPC del año (registrado en la modalidad "Vehículo") + puntos adicionales de la celda.
    </p>

    <table class="table bg-white align-middle">
        <thead>
            <tr>
                <th>Tipo de solicitud</th>
                <th>Plazo</th>
                <th>Puntos adicionales sobre IPC</th>
                <th>Tasa efectiva {{ $anioActual }}</th>
                @if ($puedeEditar)
                    <th class="text-end">Acciones</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @forelse ($tasasVehiculo as $tasaVehiculo)
                <tr>
                    <td>{{ $tasaVehiculo->es_primera_solicitud ? 'Primera solicitud' : 'A partir de la segunda solicitud' }}</td>
                    <td>{{ $tasaVehiculo->plazo_anios }} años</td>
                    <td style="max-width: 160px;">
                        <input type="number" step="0.01" min="0" name="puntos_adicionales_ipc" class="form-control form-control-sm"
                               value="{{ $tasaVehiculo->puntos_adicionales_ipc }}"
                               form="tasa-vehiculo-{{ $tasaVehiculo->id_tasa_vehiculo }}"
                               @disabled(! $puedeEditar)>
                    </td>
                    <td>
                        @if (is_null($tasasEfectivas[$tasaVehiculo->id_tasa_vehiculo]))
                            <span class="text-muted">Falta registrar la tasa IPC {{ $anioActual }} de la modalidad "Vehículo"</span>
                        @else
                            {{ number_format($tasasEfectivas[$tasaVehiculo->id_tasa_vehiculo], 4) }}%
                        @endif
                    </td>
                    @if ($puedeEditar)
                        <td class="text-end">
                            <button type="submit" form="tasa-vehiculo-{{ $tasaVehiculo->id_tasa_vehiculo }}" class="btn btn-sm btn-outline-secondary">Guardar</button>
                        </td>
                    @endif
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="text-center text-muted">No hay tasas de vehículo registradas.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @if ($puedeEditar)
        @foreach ($tasasVehiculo as $tasaVehiculo)
            <form id="tasa-vehiculo-{{ $tasaVehiculo->id_tasa_vehiculo }}" method="POST"
                  action="{{ route('tasa-vehiculo.update', $tasaVehiculo) }}" class="d-none">
                @csrf
                @method('PUT')
            </form>
        @endforeach
    @endif
@endsection
