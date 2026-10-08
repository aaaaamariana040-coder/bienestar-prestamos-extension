{{-- Íconos de línea para el menú lateral (SVG en línea, sin dependencias nuevas). --}}
@props(['nombre'])

<svg {{ $attributes->merge(['class' => 'icono-menu']) }} width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
     stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    @switch($nombre)
        @case('inicio')
            <path d="M3 11l9-8 9 8v9a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/>
            @break
        @case('administrador')
            <path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/>
            @break
        @case('empleados')
            <circle cx="9" cy="8" r="3.5"/>
            <path d="M2.5 20c0-3.5 3-6 6.5-6s6.5 2.5 6.5 6"/>
            <path d="M16 5.2a3.5 3.5 0 0 1 0 6.6"/>
            <path d="M18 14.5c2.2.8 3.5 2.8 3.5 5.5"/>
            @break
        @case('modalidades')
            <path d="M12 3l9 5-9 5-9-5z"/>
            <path d="M3 13l9 5 9-5"/>
            @break
        @case('solicitudes')
            <path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/>
            <path d="M14 3v5h5"/>
            <path d="M9 13h6M9 17h6"/>
            @break
        @case('pagos')
            <rect x="2.5" y="5" width="19" height="14" rx="2"/>
            <path d="M2.5 10h19"/>
            <path d="M6 15h4"/>
            @break
        @case('reportes')
            <path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>
            @break
        @case('menu')
            <path d="M4 6h16M4 12h16M4 18h16"/>
            @break
    @endswitch
</svg>
