@php
    $usuario = auth()->user();

    // Cada grupo se muestra solo si el usuario puede ver al menos uno de sus submenús.
    // Los de Administrador dependen de las políticas de usuarios y auditoría (solo ese rol).
    $grupos = [
        [
            'id' => 'administrador',
            'titulo' => 'Administrador',
            'icono' => 'administrador',
            'items' => [
                ['texto' => 'Usuarios', 'ruta' => 'usuarios.index', 'activo' => 'usuarios.*', 'visible' => $usuario->can('viewAny', \App\Models\Usuario::class)],
                ['texto' => 'Auditoría', 'ruta' => 'auditoria.index', 'activo' => 'auditoria.*', 'visible' => $usuario->can('viewAny', \App\Models\Auditoria::class)],
            ],
        ],
        [
            'id' => 'empleados',
            'titulo' => 'Empleados',
            'icono' => 'empleados',
            'items' => [
                ['texto' => 'Lista de empleados', 'ruta' => 'empleados.index', 'activo' => ['empleados.index', 'empleados.show', 'empleados.edit', 'empleados.retiro'], 'visible' => true],
                ['texto' => 'Nuevo empleado', 'ruta' => 'empleados.create', 'activo' => 'empleados.create', 'visible' => true],
            ],
        ],
        [
            'id' => 'modalidades',
            'titulo' => 'Modalidades y presupuesto',
            'icono' => 'modalidades',
            'items' => [
                ['texto' => 'Modalidades', 'ruta' => 'tipos-prestamo.index', 'activo' => 'tipos-prestamo.*', 'visible' => $usuario->can('viewAny', \App\Models\TipoPrestamo::class)],
                ['texto' => 'Tasa de vehículo', 'ruta' => 'tasa-vehiculo.index', 'activo' => 'tasa-vehiculo.*', 'visible' => $usuario->can('viewAny', \App\Models\TipoPrestamo::class)],
                ['texto' => 'Presupuesto', 'ruta' => 'presupuestos.index', 'activo' => 'presupuestos.*', 'visible' => $usuario->can('viewAny', \App\Models\PresupuestoAnual::class)],
                ['texto' => 'Salario mínimo (SMMLV)', 'ruta' => 'smmlv.index', 'activo' => 'smmlv.*', 'visible' => $usuario->can('viewAny', \App\Models\SmmlvAnual::class)],
            ],
        ],
        [
            'id' => 'solicitudes-prestamos',
            'titulo' => 'Solicitudes y préstamos',
            'icono' => 'solicitudes',
            'items' => [
                ['texto' => 'Solicitudes', 'ruta' => 'solicitudes.index', 'activo' => ['solicitudes.index', 'solicitudes.show'], 'visible' => $usuario->can('viewAny', \App\Models\SolicitudCredito::class)],
                ['texto' => 'Registrar solicitud', 'ruta' => 'solicitudes.create', 'activo' => 'solicitudes.create', 'visible' => $usuario->can('create', \App\Models\SolicitudCredito::class)],
                ['texto' => 'Simulador de crédito', 'ruta' => 'simulador.index', 'activo' => 'simulador.*', 'visible' => $usuario->can('simulador.usar')],
                ['texto' => 'Préstamos', 'ruta' => 'prestamos.index', 'activo' => 'prestamos.*', 'visible' => $usuario->can('viewAny', \App\Models\Prestamo::class)],
                ['texto' => 'Proveedores', 'ruta' => 'proveedores.index', 'activo' => 'proveedores.*', 'visible' => $usuario->can('viewAny', \App\Models\Proveedor::class)],
            ],
        ],
        [
            'id' => 'pagos',
            'titulo' => 'Pagos',
            'icono' => 'pagos',
            'items' => [
                // Los pagos y abonos se registran desde el detalle de cada préstamo.
                ['texto' => 'Registrar pago o abono', 'ruta' => 'prestamos.index', 'activo' => null, 'visible' => $usuario->can('viewAny', \App\Models\Prestamo::class)],
                ['texto' => 'Historial de pagos', 'ruta' => 'reportes.pagos', 'activo' => 'reportes.pagos', 'visible' => $usuario->can('reportes.ver')],
            ],
        ],
        [
            'id' => 'reportes',
            'titulo' => 'Reportes',
            'icono' => 'reportes',
            'items' => [
                ['texto' => 'Historial de pagos', 'ruta' => 'reportes.historial-pagos', 'activo' => 'reportes.historial-pagos*', 'visible' => $usuario->can('reportes.ver')],
                ['texto' => 'Cartera', 'ruta' => 'reportes.cartera', 'activo' => 'reportes.cartera', 'visible' => $usuario->can('reportes.ver')],
                ['texto' => 'Presupuesto', 'ruta' => 'reportes.presupuesto', 'activo' => 'reportes.presupuesto', 'visible' => $usuario->can('reportes.ver')],
                ['texto' => 'Informes', 'ruta' => 'informes.index', 'activo' => 'informes.*', 'visible' => $usuario->can('reportes.ver')],
            ],
        ],
    ];
@endphp

<nav class="menu-lateral-contenido" aria-label="Menú principal">
    <a href="{{ route('inicio') }}" class="menu-grupo-titulo menu-inicio {{ request()->routeIs('inicio') ? 'activo' : '' }}" title="Inicio">
        <x-icono nombre="inicio" />
        <span class="menu-texto">Inicio</span>
    </a>

    @foreach ($grupos as $grupo)
        @php
            $items = array_values(array_filter($grupo['items'], fn (array $item): bool => $item['visible']));
            $grupoActivo = collect($items)->contains(fn (array $item): bool => $item['activo'] !== null && request()->routeIs($item['activo']));
        @endphp

        @if ($items !== [])
            <div class="menu-grupo" data-grupo="{{ $grupo['id'] }}">
                <button type="button" class="menu-grupo-titulo {{ $grupoActivo ? 'activo' : 'collapsed' }}"
                        data-bs-toggle="collapse" data-bs-target="#grupo-{{ $grupo['id'] }}"
                        aria-expanded="{{ $grupoActivo ? 'true' : 'false' }}" aria-controls="grupo-{{ $grupo['id'] }}"
                        title="{{ $grupo['titulo'] }}">
                    <x-icono :nombre="$grupo['icono']" />
                    <span class="menu-texto">{{ $grupo['titulo'] }}</span>
                    <span class="menu-flecha" aria-hidden="true">&rsaquo;</span>
                </button>

                <div class="collapse menu-submenu {{ $grupoActivo ? 'show' : '' }}" id="grupo-{{ $grupo['id'] }}">
                    @foreach ($items as $item)
                        @php
                            $enlaceActivo = $item['activo'] !== null && request()->routeIs($item['activo']);
                        @endphp
                        <a href="{{ route($item['ruta']) }}" class="menu-enlace {{ $enlaceActivo ? 'activo' : '' }}" @if ($enlaceActivo) aria-current="page" @endif>
                            {{ $item['texto'] }}
                        </a>
                    @endforeach
                </div>
            </div>
        @endif
    @endforeach

    <a href="{{ route('password.edit') }}" class="menu-grupo-titulo d-md-none {{ request()->routeIs('password.*') ? 'activo' : '' }}" title="Mi contraseña">
        <x-icono nombre="administrador" />
        <span class="menu-texto">Mi contraseña</span>
    </a>
</nav>
