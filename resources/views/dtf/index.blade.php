@extends('layouts.app')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">DTF</h1>
    </div>

    <p class="text-muted">
        DTF trimestre anticipado del Banco de la República, desde su fecha de vigencia. Se digita el nominal; el efectivo anual
        se calcula como (1 / (1 − nominal/4))<sup>4</sup> − 1. Se usará para liquidar el crédito de quien se retira (Acuerdo 19,
        art. 40); por ahora solo se registra y se consulta.
    </p>

    @php
        $puedeGestionar = auth()->user()->can('modalidades.gestionar');
        $dtfEditado = (int) old('_dtf_editado');
        $erroresDtf = collect(['fecha_vigencia', 'dtf_nominal', 'fuente_normativa'])->flatMap(fn (string $campo): array => $errors->get($campo))
            ->merge($errors->getBag('edicionDtf')->all())
            ->unique();
    @endphp

    @if ($erroresDtf->isNotEmpty())
        <div class="alert alert-danger" id="errores-dtf" role="alert">
            <ul class="mb-0">
                @foreach ($erroresDtf as $mensaje)
                    <li>{{ $mensaje }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <table class="table table-striped bg-white align-middle">
        <thead>
            <tr>
                <th>Vigente desde</th>
                <th>Nominal trimestre anticipado (%)</th>
                <th>Efectivo anual (%)</th>
                <th>Fuente u observación</th>
                @if ($puedeGestionar)
                    <th class="text-end">Acciones</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @forelse ($tasas as $tasa)
                @php
                    $esElEditado = $dtfEditado === $tasa->id_tasa_dtf;
                    $bolsa = $errors->getBag('edicionDtf');
                @endphp
                <tr>
                    @if ($puedeGestionar)
                        <td style="max-width: 180px;">
                            <input type="date" name="fecha_vigencia" form="dtf-editar-{{ $tasa->id_tasa_dtf }}"
                                   @class(['form-control', 'form-control-sm', 'is-invalid' => $esElEditado && $bolsa->has('fecha_vigencia')])
                                   value="{{ $esElEditado ? old('fecha_vigencia') : $tasa->fecha_vigencia->toDateString() }}"
                                   aria-label="Fecha de vigencia del DTF {{ $tasa->fecha_vigencia->toDateString() }}">
                        </td>
                        <td style="max-width: 160px;">
                            <input type="text" inputmode="decimal" name="dtf_nominal" form="dtf-editar-{{ $tasa->id_tasa_dtf }}" autocomplete="off"
                                   @class(['form-control', 'form-control-sm', 'is-invalid' => $esElEditado && $bolsa->has('dtf_nominal')])
                                   value="{{ $esElEditado ? old('dtf_nominal') : $tasa->nominal_legible }}"
                                   aria-label="DTF nominal del {{ $tasa->fecha_vigencia->toDateString() }}">
                        </td>
                        <td>{{ $tasa->efectivo_legible }}</td>
                        <td>
                            <input type="text" name="fuente_normativa" form="dtf-editar-{{ $tasa->id_tasa_dtf }}" maxlength="100" autocomplete="off"
                                   @class(['form-control', 'form-control-sm', 'is-invalid' => $esElEditado && $bolsa->has('fuente_normativa')])
                                   value="{{ $esElEditado ? old('fuente_normativa') : $tasa->fuente_normativa }}"
                                   aria-label="Fuente del DTF {{ $tasa->fecha_vigencia->toDateString() }}">
                        </td>
                        <td class="text-end">
                            <button type="submit" form="dtf-editar-{{ $tasa->id_tasa_dtf }}" class="btn btn-sm btn-outline-secondary">Guardar</button>
                        </td>
                    @else
                        <td>{{ $tasa->fecha_vigencia->toDateString() }}</td>
                        <td>{{ $tasa->nominal_legible }}</td>
                        <td>{{ $tasa->efectivo_legible }}</td>
                        <td>{{ $tasa->fuente_normativa ?? '—' }}</td>
                    @endif
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $puedeGestionar ? 5 : 4 }}" class="text-center text-muted">No hay DTF registrados.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @if ($puedeGestionar)
        @foreach ($tasas as $tasa)
            <form id="dtf-editar-{{ $tasa->id_tasa_dtf }}" method="POST" action="{{ route('dtf.update', $tasa) }}" class="d-none">
                @csrf
                @method('PUT')
                <input type="hidden" name="_dtf_editado" value="{{ $tasa->id_tasa_dtf }}">
            </form>
        @endforeach

        <div class="card">
            <div class="card-body">
                <h2 class="h5 mb-3">Registrar un DTF</h2>

                <form method="POST" action="{{ route('dtf.store') }}" class="row g-2 align-items-end">
                    @csrf
                    <div class="col-auto">
                        <label for="fecha_vigencia" class="form-label">Vigente desde</label>
                        <input type="date" id="fecha_vigencia" name="fecha_vigencia" @class(['form-control', 'is-invalid' => ! $dtfEditado && $errors->has('fecha_vigencia')]) value="{{ $dtfEditado ? '' : old('fecha_vigencia') }}" required>
                    </div>
                    <div class="col-auto">
                        <label for="dtf_nominal" class="form-label">Nominal trimestre anticipado (%)</label>
                        <input type="text" inputmode="decimal" id="dtf_nominal" name="dtf_nominal" autocomplete="off" @class(['form-control', 'is-invalid' => ! $dtfEditado && $errors->has('dtf_nominal')]) value="{{ $dtfEditado ? '' : old('dtf_nominal') }}" required>
                    </div>
                    <div class="col-auto">
                        <label class="form-label">Efectivo anual (%)</label>
                        <div class="form-control-plaintext fw-semibold" id="dtf-efectivo-calculado" aria-live="polite">—</div>
                    </div>
                    <div class="col-md-4">
                        <label for="fuente_normativa" class="form-label">Fuente u observación (opcional)</label>
                        <input type="text" id="fuente_normativa" name="fuente_normativa" maxlength="100" autocomplete="off" @class(['form-control', 'is-invalid' => ! $dtfEditado && $errors->has('fuente_normativa')]) value="{{ $dtfEditado ? '' : old('fuente_normativa') }}" placeholder="Banco de la República, semana del …">
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-primary">Registrar</button>
                    </div>
                </form>
                <div class="form-text">Se acepta coma o punto decimal (9,81), de 0 a menos de 100, con hasta 4 decimales. Cada fecha de vigencia se registra una sola vez; para cambiarla, edita su fila de la tabla. Los DTF no se borran.</div>
            </div>
        </div>
    @endif
@endsection

@push('scripts')
    <script>
        (function () {
            const campo = document.getElementById('dtf_nominal');
            const salida = document.getElementById('dtf-efectivo-calculado');

            if (! campo || ! salida) {
                return;
            }

            function calcular() {
                const nominal = parseFloat(campo.value.replace(',', '.'));

                if (isNaN(nominal) || nominal < 0 || nominal >= 100) {
                    salida.textContent = '—';
                    return;
                }

                const efectivo = (Math.pow(1 / (1 - nominal / 100 / 4), 4) - 1) * 100;

                salida.textContent = efectivo.toLocaleString('es-CO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }

            campo.addEventListener('input', calcular);
            calcular();
        })();
    </script>
@endpush
