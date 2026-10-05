@extends('layouts.app')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">Salario Mínimo Mensual Legal Vigente (SMMLV)</h1>
    </div>

    <p class="text-muted">
        Valor anual del SMMLV, usado como base para calcular las cuantías máximas de crédito por modalidad
        (docs/reglas-negocio.md, sección 4).
    </p>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <table class="table table-striped bg-white align-middle">
        <thead>
            <tr>
                <th>Año</th>
                <th>Valor</th>
                <th>Fuente normativa</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($valoresSmmlv as $valorSmmlv)
                <tr>
                    <td>{{ $valorSmmlv->anio }}</td>
                    <td>{{ number_format($valorSmmlv->valor, 2) }}</td>
                    <td>{{ $valorSmmlv->fuente_normativa }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="3" class="text-center text-muted">No hay valores de SMMLV registrados.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @can('create', \App\Models\SmmlvAnual::class)
        <div class="card">
            <div class="card-body">
                <h2 class="h5 mb-3">Registrar valor del SMMLV</h2>

                @if ($errors->any())
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form method="POST" action="{{ route('smmlv.store') }}" class="row g-2 align-items-end">
                    @csrf
                    <div class="col-auto">
                        <label for="anio" class="form-label">Año</label>
                        <input type="number" id="anio" name="anio" class="form-control" value="{{ old('anio') }}">
                    </div>
                    <div class="col-auto">
                        <label for="valor" class="form-label">Valor</label>
                        <input type="text" inputmode="decimal" id="valor" name="valor" class="form-control" value="{{ old('valor') }}">
                    </div>
                    <div class="col-auto">
                        <label for="fuente_normativa" class="form-label">Fuente normativa</label>
                        <input type="text" id="fuente_normativa" name="fuente_normativa" class="form-control" value="{{ old('fuente_normativa') }}">
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-primary">Registrar</button>
                    </div>
                </form>
            </div>
        </div>
    @endcan
@endsection
