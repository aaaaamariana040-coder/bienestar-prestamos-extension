@if ($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form method="POST" action="{{ route('informes.store') }}" class="mb-3">
    @csrf
    <input type="hidden" name="tipo" value="{{ $tipo }}">
    @foreach ($parametros as $nombre => $valor)
        @if ($valor !== null && $valor !== '')
            <input type="hidden" name="{{ $nombre }}" value="{{ $valor }}">
        @endif
    @endforeach
    <button type="submit" class="btn btn-primary">Generar y guardar informe</button>
    <a href="{{ route('informes.index') }}" class="btn btn-link">Ver informes guardados</a>
</form>
