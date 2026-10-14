{{--
    ÚNICO lugar donde se referencia el logo de la aplicación (portada, menú, login).
    El PNG oficial del Politécnico trae fondo verde sólido #009140: el color de fondo del
    contenedor se fija en esa misma tonalidad (clase .logo-aplicacion) para que se funda.
--}}
@props(['alto' => 44])

<img src="{{ asset('images/logo-politecnico.png') }}" alt="Politécnico Colombiano Jaime Isaza Cadavid"
     height="{{ $alto }}" {{ $attributes->merge(['class' => 'logo-aplicacion']) }}>
