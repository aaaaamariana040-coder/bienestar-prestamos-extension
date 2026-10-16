@extends('layouts.app')

@section('content')
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
