@extends('layouts.app')

@section('content')
    <h1 class="h3 mb-4">Nuevo empleado</h1>

    <form method="POST" action="{{ route('empleados.store') }}">
        @csrf
        @include('empleados._form')

        <button type="submit" class="btn btn-primary">Guardar</button>
        <a href="{{ route('empleados.index') }}" class="btn btn-outline-secondary">Cancelar</a>
    </form>
@endsection
