@props([
    'url',
    'id' => 'cedula_empleado',
    'name' => null,
    'valor' => '',
    'requerido' => false,
    'resultado' => 'resultado-empleado',
])

{{--
    Campo de cédula con búsqueda en el servidor: solo números, máximo 10 dígitos
    y una espera de 250 ms después de escribir. No pinta el resultado; avisa con
    eventos sobre el campo para que cada pantalla lo muestre a su manera:
      cedula:vacia      - el campo quedó vacío
      cedula:invalida   - detail.mensaje: no cumple el formato
      cedula:encontrada - detail: JSON devuelto por la URL (respuesta correcta)
      cedula:error      - detail: JSON devuelto (404, 422…) o { mensaje } si falló la red
--}}
<input type="text" id="{{ $id }}" @if ($name) name="{{ $name }}" @endif class="form-control"
       value="{{ $valor }}" inputmode="numeric" maxlength="10" pattern="[0-9]{1,10}"
       title="Solo números, máximo 10 dígitos" autocomplete="off" placeholder="Escribe la cédula" @required($requerido)>
<div id="{{ $resultado }}" class="mt-2" aria-live="polite"></div>

<script>
    (function () {
        const campo = document.getElementById(@json($id));
        const urlBase = @json($url);
        let temporizador = null;
        let peticion = null;

        function avisar(tipo, detalle) {
            campo.dispatchEvent(new CustomEvent('cedula:' + tipo, { detail: detalle }));
        }

        function buscar() {
            const cedula = campo.value.replace(/\s+/g, '');

            if (peticion) {
                peticion.abort();
            }

            if (cedula === '') {
                avisar('vacia', {});

                return;
            }

            if (! /^[0-9]{1,10}$/.test(cedula)) {
                avisar('invalida', { mensaje: 'La cédula debe contener solo números, máximo 10 dígitos.' });

                return;
            }

            peticion = new AbortController();

            fetch(urlBase + '/' + cedula, { signal: peticion.signal, headers: { 'Accept': 'application/json' } })
                .then(function (respuesta) {
                    return respuesta.json().then(function (datos) {
                        return { ok: respuesta.ok, datos: datos };
                    });
                })
                .then(function (r) {
                    avisar(r.ok ? 'encontrada' : 'error', r.datos);
                })
                .catch(function (error) {
                    if (error.name !== 'AbortError') {
                        avisar('error', { mensaje: 'No se pudo consultar el empleado. Inténtalo de nuevo.' });
                    }
                });
        }

        campo.addEventListener('input', function () {
            clearTimeout(temporizador);
            avisar('escribiendo', {});
            temporizador = setTimeout(buscar, 250);
        });

        campo.buscarAhora = buscar;
    })();
</script>
