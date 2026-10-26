@extends('layouts.app')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">Primas de seguros</h1>
    </div>

    <p class="text-muted">
        Prima de cada seguro por año, como fracción del monto del crédito (0,0003231 = 0,03231 %). El simulador la usa para
        estimar el seguro, que se cobra aparte de la cuota: el valor real lo calcula el área de seguros. Si un año no tiene
        prima, el simulador usa la más reciente de un año anterior; si no hay ninguna, usa el valor de respaldo
        ({{ $respaldo }}) para vida de deudores.
    </p>

    @php
        $puedeGestionar = auth()->user()->can('create', \App\Models\PrimaSeguro::class);
        $primaEditada = (int) old('_prima_editada');
        $erroresPrima = collect(['concepto', 'anio', 'tasa'])->flatMap(fn (string $campo): array => $errors->get($campo))
            ->merge($errors->getBag('edicionPrima')->all())
            ->unique();
    @endphp

    @if ($erroresPrima->isNotEmpty())
        <div class="alert alert-danger" id="errores-prima" role="alert">
            <ul class="mb-0">
                @foreach ($erroresPrima as $mensaje)
                    <li>{{ $mensaje }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <table class="table table-striped bg-white align-middle">
        <thead>
            <tr>
                <th>Año</th>
                <th>Seguro</th>
                <th>Prima (fracción del monto)</th>
                @if ($puedeGestionar)
                    <th class="text-end">Acciones</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @forelse ($primas as $prima)
                @php
                    $esLaEditada = $primaEditada === $prima->id_prima;
                    $errorEdicion = $esLaEditada && $errors->getBag('edicionPrima')->has('tasa');
                @endphp
                <tr>
                    <td>{{ $prima->anio }}</td>
                    <td>{{ $prima->concepto->etiqueta() }}</td>
                    @if ($puedeGestionar)
                        <td style="max-width: 200px;">
                            <input type="text" inputmode="decimal" name="tasa" form="prima-editar-{{ $prima->id_prima }}" autocomplete="off"
                                   @class(['form-control', 'form-control-sm', 'is-invalid' => $errorEdicion])
                                   value="{{ $esLaEditada ? old('tasa') : $prima->tasa_legible }}"
                                   aria-label="Prima de {{ $prima->concepto->etiqueta() }} {{ $prima->anio }}">
                        </td>
                        <td class="text-end">
                            <button type="submit" form="prima-editar-{{ $prima->id_prima }}" class="btn btn-sm btn-outline-secondary">Guardar</button>
                        </td>
                    @else
                        <td>{{ $prima->tasa_legible }}</td>
                    @endif
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $puedeGestionar ? 4 : 3 }}" class="text-center text-muted">No hay primas registradas.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @if ($puedeGestionar)
        @foreach ($primas as $prima)
            <form id="prima-editar-{{ $prima->id_prima }}" method="POST" action="{{ route('seguros.update', $prima) }}" class="d-none">
                @csrf
                @method('PUT')
                <input type="hidden" name="_prima_editada" value="{{ $prima->id_prima }}">
            </form>
        @endforeach

        <div class="card">
            <div class="card-body">
                <h2 class="h5 mb-3">Registrar la prima de un año</h2>

                <form method="POST" action="{{ route('seguros.store') }}" class="row g-2 align-items-end">
                    @csrf
                    <div class="col-auto">
                        <label for="concepto" class="form-label">Seguro</label>
                        <select id="concepto" name="concepto" @class(['form-select', 'is-invalid' => $errors->has('concepto')]) required>
                            <option value="">Elige un seguro…</option>
                            @foreach ($conceptos as $concepto)
                                <option value="{{ $concepto->value }}" @selected(old('concepto') === $concepto->value)>{{ $concepto->etiqueta() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-auto">
                        <label for="anio" class="form-label">Año</label>
                        <input type="number" id="anio" name="anio" @class(['form-control', 'is-invalid' => $errors->has('anio')]) value="{{ old('anio', now()->year) }}" required>
                    </div>
                    <div class="col-auto">
                        <label for="tasa" class="form-label">Prima</label>
                        <input type="text" inputmode="decimal" id="tasa" name="tasa" autocomplete="off" @class(['form-control', 'is-invalid' => $errors->has('tasa')]) value="{{ old('_prima_editada') ? '' : old('tasa') }}" required>
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-primary">Registrar</button>
                    </div>
                </form>
                <div class="form-text">Se acepta coma o punto decimal (0,0003231), entre 0 y 1, con hasta 8 decimales. Cada seguro se registra una sola vez por año; para cambiarlo, edita su fila de la tabla.</div>
            </div>
        </div>
    @endif
@endsection
