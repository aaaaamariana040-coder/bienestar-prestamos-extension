@extends('layouts.app')

@section('content')
    @if (! empty($quincenasPendientes))
        <div class="alert alert-warning d-flex justify-content-between align-items-center gap-3" role="alert">
            <div>
                <strong>Quincenas pendientes de confirmar:</strong>
                @foreach ($quincenasPendientes as $quincena)
                    quincena {{ $quincena['numero'] }} de {{ $quincena['anio'] }} ({{ $quincena['cuotas'] }} cuotas){{ $loop->last ? '.' : ',' }}
                @endforeach
            </div>
            <a href="{{ route('quincenas.index') }}" class="btn btn-sm btn-warning text-nowrap">Ir a Quincenas</a>
        </div>
    @endif

    <section class="portada text-center">
        <div class="portada-marca">
            <x-logo :alto="160" class="portada-logo" />

            <h1 class="portada-titulo">Bienestar Préstamos</h1>
        </div>
        <p class="portada-subtitulo">
            Gestión de préstamos de Bienestar Social<br>
            Politécnico Colombiano Jaime Isaza Cadavid
        </p>

        <p class="portada-saludo">Hola, <strong>{{ auth()->user()->nombre_completo }}</strong>. Elige una opción del menú para comenzar.</p>
    </section>
@endsection
