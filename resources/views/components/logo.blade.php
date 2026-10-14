{{--
    ÚNICO lugar donde se referencia el logo de la aplicación (portada, menú, login).
    El PNG oficial del Politécnico trae fondo verde sólido #009140: el color de fondo del
    contenedor se fija en esa misma tonalidad (clase .logo-aplicacion) para que se funda.
    La variante «barra» usa una copia recoloreada a --color-primario (#00AB44) para la barra superior.
--}}
@props(['alto' => 44, 'barra' => false])

<img src="{{ asset($barra ? 'images/logo-politecnico-barra.png' : 'images/logo-politecnico.png') }}"
     alt="Politécnico Colombiano Jaime Isaza Cadavid" height="{{ $alto }}"
     {{ $attributes->merge(['class' => 'logo-aplicacion'.($barra ? ' logo-barra' : '')]) }}>
