{{--
    ÚNICO lugar donde se referencia el logo de la aplicación (portada, menú, login).
    Hoy apunta a un MARCADOR DE POSICIÓN; no se usa el escudo oficial hasta contar con la
    autorización de la Oficina de Comunicaciones. Para reemplazarlo basta cambiar el archivo
    public/images/logo-placeholder.svg o la ruta de este `src`.
--}}
@props(['alto' => 40])

<img src="{{ asset('images/logo-placeholder.svg') }}" alt="Logo de Bienestar Préstamos (marcador de posición)"
     height="{{ $alto }}" width="{{ $alto }}" {{ $attributes->merge(['class' => 'logo-aplicacion']) }}>
