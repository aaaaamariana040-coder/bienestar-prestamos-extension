@extends('layouts.app')

@section('content')
    <h1 class="h3 mb-4">Proveedores</h1>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <table class="table table-striped bg-white align-middle mb-4">
        <thead>
            <tr>
                <th>Id</th>
                <th>NIT</th>
                <th>Nombre</th>
                <th>Dirección</th>
                <th>Cuenta bancaria</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($proveedores as $proveedor)
                <tr>
                    <td>{{ $proveedor->id_proveedor }}</td>
                    <td>{{ $proveedor->nit }}</td>
                    <td>{{ $proveedor->nombre_empresa }}</td>
                    <td>{{ $proveedor->direccion }}</td>
                    <td>{{ $proveedor->num_cuenta_bancaria }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="text-center text-muted">No hay proveedores registrados.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @can('create', \App\Models\Proveedor::class)
        <div class="card">
            <div class="card-body">
                <h2 class="h5 mb-3">Registrar proveedor</h2>

                @if ($errors->any())
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            @foreach ($errors->all() as $mensaje)
                                <li>{{ $mensaje }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form method="POST" action="{{ route('proveedores.store') }}" class="row g-2 align-items-end">
                    @csrf
                    <div class="col-auto">
                        <label for="id_proveedor" class="form-label">Id</label>
                        <input type="text" id="id_proveedor" name="id_proveedor" class="form-control" value="{{ old('id_proveedor') }}" required>
                    </div>
                    <div class="col-auto">
                        <label for="nit" class="form-label">NIT</label>
                        <input type="text" id="nit" name="nit" class="form-control" value="{{ old('nit') }}" required>
                    </div>
                    <div class="col-auto">
                        <label for="nombre_empresa" class="form-label">Nombre</label>
                        <input type="text" id="nombre_empresa" name="nombre_empresa" class="form-control" value="{{ old('nombre_empresa') }}" required>
                    </div>
                    <div class="col-auto">
                        <label for="direccion" class="form-label">Dirección</label>
                        <input type="text" id="direccion" name="direccion" class="form-control" value="{{ old('direccion') }}">
                    </div>
                    <div class="col-auto">
                        <label for="num_cuenta_bancaria" class="form-label">Cuenta bancaria</label>
                        <input type="text" id="num_cuenta_bancaria" name="num_cuenta_bancaria" class="form-control" value="{{ old('num_cuenta_bancaria') }}">
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-primary">Registrar</button>
                    </div>
                </form>
            </div>
        </div>
    @endcan
@endsection
