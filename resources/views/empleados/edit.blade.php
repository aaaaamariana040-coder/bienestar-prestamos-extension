@extends('layouts.app')

@section('content')
    <h1 class="h3 mb-4">Editar empleado</h1>

    <form method="POST" action="{{ route('empleados.update', $empleado) }}">
        @csrf
        @method('PUT')
        @include('empleados._form')

        <button type="submit" class="btn btn-primary">Guardar cambios</button>
        <a href="{{ route('empleados.index') }}" class="btn btn-outline-secondary">Cancelar</a>
    </form>
@endsection
