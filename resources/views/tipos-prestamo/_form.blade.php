@php
    $tipoPrestamo = $tipoPrestamo ?? null;
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

<div class="mb-3">
    <label for="nombre" class="form-label">Nombre de la modalidad</label>
    <input type="text" id="nombre" name="nombre" class="form-control"
           value="{{ old('nombre', optional($tipoPrestamo)->nombre) }}" required>
</div>

<div class="mb-3">
    <label for="descripcion" class="form-label">Descripción</label>
    <textarea id="descripcion" name="descripcion" class="form-control" rows="3">{{ old('descripcion', optional($tipoPrestamo)->descripcion) }}</textarea>
</div>

<div class="row">
    <div class="col-md-4 mb-3">
        <label for="plazo_maximo" class="form-label">Plazo máximo (quincenas)</label>
        <input type="number" min="1" id="plazo_maximo" name="plazo_maximo" class="form-control"
               value="{{ old('plazo_maximo', optional($tipoPrestamo)->plazo_maximo) }}" required>
        <div class="form-text">
            Siempre en quincenas: 24 quincenas = 1 año (2 quincenas = 1 mes). Es la misma unidad que usan préstamo y cuota en todo el sistema.
        </div>
    </div>

    <div class="col-md-4 mb-3">
        <label for="fuente_normativa" class="form-label">Fuente normativa</label>
        <input type="text" id="fuente_normativa" name="fuente_normativa" class="form-control"
               value="{{ old('fuente_normativa', optional($tipoPrestamo)->fuente_normativa) }}">
    </div>
</div>
