@php
    $empleado = $empleado ?? null;
@endphp

@if ($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0">
            @foreach ($errors->all() as $mensaje)
                <li>{{ $mensaje }}</li>
            @endforeach
        </ul>
    </div>
@endif

@if (! $empleado)
    <div class="mb-3">
        <label for="cedula" class="form-label">Cédula</label>
        <input type="text" id="cedula" name="cedula" class="form-control" value="{{ old('cedula') }}" required>
    </div>
@endif

<div class="mb-3">
    <label for="nombre_completo" class="form-label">Nombre completo</label>
    <input type="text" id="nombre_completo" name="nombre_completo" class="form-control"
           value="{{ old('nombre_completo', optional($empleado)->nombre_completo) }}" required>
</div>

<div class="row">
    <div class="col-md-6 mb-3">
        <label for="tipo_vinculacion" class="form-label">Tipo de vinculación</label>
        <select id="tipo_vinculacion" name="tipo_vinculacion" class="form-select" required>
            @foreach (['Empleado', 'Docente'] as $opcion)
                <option value="{{ $opcion }}" @selected(old('tipo_vinculacion', optional($empleado)->tipo_vinculacion) === $opcion)>
                    {{ $opcion }}
                </option>
            @endforeach
        </select>
    </div>

    <div class="col-md-6 mb-3">
        <label for="estado" class="form-label">Estado</label>
        <select id="estado" name="estado" class="form-select" required>
            @foreach ((optional($empleado)->exists && $empleado->estado !== 'Retirado' ? ['Activo'] : ['Activo', 'Retirado']) as $opcion)
                <option value="{{ $opcion }}" @selected(old('estado', optional($empleado)->estado ?? 'Activo') === $opcion)>
                    {{ $opcion }}
                </option>
            @endforeach
        </select>
    </div>
</div>

<div class="row">
    <div class="col-md-6 mb-3">
        <label for="cargo" class="form-label">Cargo</label>
        <input type="text" id="cargo" name="cargo" class="form-control" value="{{ old('cargo', optional($empleado)->cargo) }}">
    </div>

    <div class="col-md-6 mb-3">
        <label for="dependencia" class="form-label">Dependencia</label>
        <input type="text" id="dependencia" name="dependencia" class="form-control" value="{{ old('dependencia', optional($empleado)->dependencia) }}">
    </div>
</div>

<div class="row">
    <div class="col-md-6 mb-3">
        <label for="salario" class="form-label">Salario</label>
        <input type="number" step="0.01" min="0" id="salario" name="salario" class="form-control"
               value="{{ old('salario', optional($empleado)->salario) }}" required>
    </div>

    <div class="col-md-6 mb-3">
        <label for="fecha_vinculacion" class="form-label">Fecha de vinculación</label>
        <input type="date" id="fecha_vinculacion" name="fecha_vinculacion" class="form-control"
               value="{{ old('fecha_vinculacion', optional($empleado)->fecha_vinculacion?->format('Y-m-d')) }}">
    </div>
</div>

<div class="mb-3">
    <label for="capacidad_endeudamiento" class="form-label">Capacidad de endeudamiento</label>
    <input type="number" step="0.01" min="0" id="capacidad_endeudamiento" name="capacidad_endeudamiento" class="form-control"
           value="{{ old('capacidad_endeudamiento', optional($empleado)->capacidad_endeudamiento) }}">
    <div class="form-text">
        Se calcula automáticamente como el 50% del salario. Puedes ajustarla manualmente si tienes un dato más preciso.
    </div>
</div>

<script>
    (function () {
        const salario = document.getElementById('salario');
        const capacidad = document.getElementById('capacidad_endeudamiento');
        let tocadaManualmente = capacidad.value !== '';

        capacidad.addEventListener('input', function () {
            tocadaManualmente = true;
        });

        salario.addEventListener('input', function () {
            if (tocadaManualmente) {
                return;
            }

            const valor = parseFloat(salario.value);
            capacidad.value = isNaN(valor) ? '' : (valor * 0.5).toFixed(2);
        });
    })();
</script>
