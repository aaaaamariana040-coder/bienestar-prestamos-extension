@extends('layouts.app')

@section('content')
    <h1 class="h3 mb-4">Editar modalidad: {{ $tipoPrestamo->nombre }}</h1>

    <form method="POST" action="{{ route('tipos-prestamo.update', $tipoPrestamo) }}">
        @csrf
        @method('PUT')

        @include('tipos-prestamo._form')

        <button type="submit" class="btn btn-primary">Guardar cambios</button>
        <a href="{{ route('tipos-prestamo.index') }}" class="btn btn-outline-secondary">Cancelar</a>
    </form>
@endsection
