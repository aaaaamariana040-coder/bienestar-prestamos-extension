@extends('layouts.app')

@section('content')
    <h1 class="h3 mb-4">Eliminar empleado</h1>

    <div class="card">
        <div class="card-body">
            <p>¿Confirmas que deseas eliminar a <strong>{{ $empleado->nombre_completo }}</strong> (cédula {{ $empleado->cedula }})?</p>

            @if ($empleado->usuario)
                <div class="alert alert-warning">
                    Este empleado tiene una cuenta de usuario asociada. No se puede eliminar mientras esa cuenta exista.
                </div>
            @else
                <form method="POST" action="{{ route('empleados.destroy', $empleado) }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-danger">Sí, eliminar</button>
                    <a href="{{ route('empleados.index') }}" class="btn btn-outline-secondary">Cancelar</a>
                </form>
            @endif
        </div>
    </div>
@endsection
