@extends('layouts.app')

@section('content')
    @php
        $calendario = app(\App\Services\CalendarioQuincenalService::class);
        $zona = \App\Services\CalendarioQuincenalService::ZONA;
    @endphp

    <h1 class="h3 mb-4">Quincenas</h1>

    <div class="card mb-4" id="selector-quincena">
        <div class="card-body">
            <label for="quincena-elegida" class="form-label">Quincena</label>
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <select id="quincena-elegida" class="form-select w-auto">
                    @foreach ($opciones as $opcion)
                        <option value="{{ $opcion['url'] }}" data-abrible="{{ $opcion['abrible'] ? '1' : '0' }}" data-etiqueta="{{ $opcion['etiqueta'] }}" @selected($opcion['por_defecto'])>{{ $opcion['texto'] }}</option>
                    @endforeach
                </select>
                <button type="button" id="abrir-quincena" class="btn btn-primary">Abrir</button>
                <span class="text-muted small d-none" id="quincena-no-empieza">No se puede abrir: la quincena todavía no empieza (solo se confirman quincenas ya iniciadas) o no tienes permiso para confirmarla.</span>
            </div>
            <div class="form-text">La quincena en curso aparece seleccionada. Las demás siguen disponibles en la lista.</div>
        </div>
    </div>

    <h2 class="h5 mb-3">Pendientes de confirmar</h2>

    <table class="table table-striped bg-white align-middle mb-4">
        <thead>
            <tr>
                <th>Quincena</th>
                <th>Estado</th>
                <th>Periodo</th>
                <th class="text-end">Cuotas</th>
                <th class="text-end">Total</th>
                <th class="text-end">Acciones</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($pendientes as $quincena)
                <tr>
                    <td>Quincena {{ $quincena['numero'] }} de {{ $quincena['anio'] }}</td>
                    <td>
                        @if ($quincena['anio'] === $enCurso['anio'] && $quincena['numero'] === $enCurso['numero'])
                            <span class="badge text-bg-primary">En curso</span>
                        @else
                            <span class="badge text-bg-warning">Pendiente anterior</span>
                        @endif
                    </td>
                    <td>{{ $quincena['inicio'] }} al {{ $quincena['corte'] }}</td>
                    <td class="text-end">{{ $quincena['cuotas'] }}</td>
                    <td class="text-end">{{ number_format($quincena['total'], 2) }}</td>
                    <td class="text-end">
                        <a href="{{ route('quincenas.vista-previa', [$quincena['anio'], $quincena['numero']]) }}" class="btn btn-sm btn-primary">Revisar y confirmar</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="text-center text-muted">No hay quincenas iniciadas pendientes de confirmar.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <h2 class="h5 mb-3">Quincenas confirmadas</h2>

    <table class="table table-striped bg-white align-middle">
        <thead>
            <tr>
                <th>Quincena</th>
                <th>Estado</th>
                <th>Periodo</th>
                <th>Confirmada por</th>
                <th>Fecha y hora</th>
                <th class="text-end">Cuotas pagadas</th>
                <th class="text-end">Total</th>
                <th class="text-end">Excluidas</th>
                <th class="text-end">Acciones</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($procesos as $proceso)
                @php
                    $periodo = $calendario->quincena($proceso->anio, $proceso->numero_quincena);
                @endphp
                <tr>
                    <td>Quincena {{ $proceso->numero_quincena }} de {{ $proceso->anio }}</td>
                    <td><span class="badge text-bg-success">Confirmada</span></td>
                    <td>{{ $periodo['inicio']->toDateString() }} al {{ $periodo['corte']->toDateString() }}</td>
                    <td>
                        @if ($proceso->modo_marcado === \App\Enums\ModoMarcado::Automatico)
                            Proceso automático
                        @else
                            {{ $proceso->usuario?->nombre_completo }}
                        @endif
                    </td>
                    <td>{{ $proceso->finalizado_en?->setTimezone($zona)->format('d/m/Y H:i') }}</td>
                    <td class="text-end">{{ $proceso->resumen['pagos_creados'] ?? 0 }}</td>
                    <td class="text-end">{{ number_format($proceso->resumen['total'] ?? 0, 2) }}</td>
                    <td class="text-end">{{ count($proceso->resumen['excluidas_manuales'] ?? []) }}</td>
                    <td class="text-end">
                        <a href="{{ route('quincenas.proceso', $proceso) }}" class="btn btn-sm btn-outline-secondary">Ver</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="9" class="text-center text-muted">Todavía no se ha confirmado ninguna quincena.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    {{ $procesos->links() }}

    <script>
        (function () {
            const selector = document.getElementById('quincena-elegida');
            const boton = document.getElementById('abrir-quincena');
            const aviso = document.getElementById('quincena-no-empieza');

            function actualizar() {
                const abrible = selector.selectedOptions[0].dataset.abrible === '1';

                boton.disabled = ! abrible;
                aviso.classList.toggle('d-none', abrible);
            }

            selector.addEventListener('change', actualizar);
            boton.addEventListener('click', function () {
                window.location.href = selector.value;
            });
            actualizar();
        })();
    </script>
@endsection
