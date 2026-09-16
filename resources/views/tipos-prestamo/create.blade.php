@extends('layouts.app')

@section('content')
    <h1 class="h3 mb-4">Nueva modalidad de crédito</h1>

    <form method="POST" action="{{ route('tipos-prestamo.store') }}">
        @csrf

        @include('tipos-prestamo._form')

        <button type="submit" class="btn btn-primary">Crear modalidad</button>
        <a href="{{ route('tipos-prestamo.index') }}" class="btn btn-outline-secondary">Cancelar</a>
    </form>
@endsection
