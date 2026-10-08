@extends('layouts.app')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">Modalidades de crédito</h1>
        <div>
            <a href="{{ route('tasa-vehiculo.index') }}" class="btn btn-outline-secondary">Tasa de vehículo</a>
            @can('create', \App\Models\TipoPrestamo::class)
                <a href="{{ route('tipos-prestamo.create') }}" class="btn btn-primary">Nueva modalidad</a>
            @endcan
        </div>
    </div>

    <table class="table table-striped bg-white">
        <thead>
            <tr>
                <th>Nombre</th>
                <th>Plazo máximo</th>
                <th>Disponible {{ $anio }}</th>
                <th>Estado</th>
                <th class="text-end">Acciones</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($tiposPrestamo as $tipoPrestamo)
                <tr>
                    <td>{{ $tipoPrestamo->nombre }}</td>
                    <td>{{ $tipoPrestamo->plazo_maximo_legible }}</td>
                    <td><x-disponible-modalidad :tipo-prestamo="$tipoPrestamo" :presupuesto="$presupuestos->get($tipoPrestamo->id_tipo)" /></td>
                    <td>{{ $tipoPrestamo->habilitada ? 'Habilitada' : 'Deshabilitada' }}</td>
                    <td class="text-end">
                        <a href="{{ route('tipos-prestamo.show', $tipoPrestamo) }}" class="btn btn-sm btn-outline-secondary">Ver</a>

                        @can('update', $tipoPrestamo)
                            <a href="{{ route('tipos-prestamo.edit', $tipoPrestamo) }}" class="btn btn-sm btn-outline-secondary">Editar</a>
                        @endcan

                        @can('deshabilitar', $tipoPrestamo)
                            @if ($tipoPrestamo->habilitada)
                                <form method="POST" action="{{ route('tipos-prestamo.deshabilitar', $tipoPrestamo) }}" class="d-inline">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Deshabilitar</button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('tipos-prestamo.habilitar', $tipoPrestamo) }}" class="d-inline">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="btn btn-sm btn-outline-success">Habilitar</button>
                                </form>
                            @endif
                        @endcan
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="text-center text-muted">No hay modalidades registradas.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    {{ $tiposPrestamo->links() }}
@endsection
