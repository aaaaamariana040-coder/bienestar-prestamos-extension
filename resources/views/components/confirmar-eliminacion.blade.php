{{--
    Modal único de confirmación de eliminación. Se incluye una vez por página y se
    abre desde cualquier botón con:
        data-bs-toggle="modal" data-bs-target="#modal-confirmar-eliminacion"
        data-eliminar-action="{{ route(...) }}" data-eliminar-nombre="Nombre o identificador"
--}}
<div class="modal fade" id="modal-confirmar-eliminacion" tabindex="-1" aria-labelledby="modal-confirmar-eliminacion-titulo" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="#" class="modal-content" id="form-confirmar-eliminacion">
            @csrf
            @method('DELETE')
            <div class="modal-header">
                <h5 class="modal-title" id="modal-confirmar-eliminacion-titulo">Confirmar eliminación</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">¿Confirmas que deseas eliminar <strong id="modal-confirmar-eliminacion-nombre"></strong>? Esta acción no se puede deshacer.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-danger">Eliminar</button>
            </div>
        </form>
    </div>
</div>

<script>
    document.getElementById('modal-confirmar-eliminacion').addEventListener('show.bs.modal', function (evento) {
        const disparador = evento.relatedTarget;

        document.getElementById('form-confirmar-eliminacion').action = disparador.dataset.eliminarAction;
        document.getElementById('modal-confirmar-eliminacion-nombre').textContent = disparador.dataset.eliminarNombre;
    });
</script>
