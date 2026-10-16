@props([
    'url',
    'id' => 'cedula_empleado',
    'name' => null,
    'valor' => '',
    'requerido' => false,
    'resultado' => 'resultado-empleado',
    'modo' => null,
    'objetivo' => 'resultados',
])

{{--
    Campo de cédula con búsqueda en el servidor: solo números, máximo 10 dígitos
    y una espera de 250 ms después de escribir. No pinta el resultado; avisa con
    eventos sobre el campo para que cada pantalla lo muestre a su manera:
      cedula:vacia      - el campo quedó vacío
      cedula:invalida   - detail.mensaje: no cumple el formato
      cedula:encontrada - detail: JSON devuelto por la URL (respuesta correcta)
      cedula:error      - detail: JSON devuelto (404, 422…) o { mensaje } si falló la red

    Con modo="filtro" el campo filtra un listado de la propia página: debe ir dentro de un
    <form method="GET"> (que sigue funcionando sin JavaScript), usa name="cedula" por defecto y,
    tras 250 ms, pide la página con los campos del formulario (?cedula=123&…) y reemplaza solo
    el elemento con id = «objetivo». Si la petición falla, envía el formulario normalmente.
--}}
@if ($modo === 'filtro')
<input type="text" id="{{ $id }}" name="{{ $name ?? 'cedula' }}" class="form-control"
       value="{{ $valor }}" inputmode="numeric" maxlength="10" pattern="[0-9]{1,10}"
       title="Solo números, máximo 10 dígitos" autocomplete="off" placeholder="Escribe la cédula">

<script>
    (function () {
        const campo = document.getElementById(@json($id));
        const formulario = campo.form;
        const idObjetivo = @json($objetivo);
        let temporizador = null;
        let peticion = null;

        function filtrar() {
            const parametros = new URLSearchParams(new FormData(formulario));

            for (const [clave, valor] of Array.from(parametros)) {
                if (valor === '') {
                    parametros.delete(clave);
                }
            }

            const url = formulario.action + (parametros.toString() ? '?' + parametros.toString() : '');

            if (peticion) {
                peticion.abort();
            }
            peticion = new AbortController();

            fetch(url, { signal: peticion.signal, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (respuesta) {
                    if (! respuesta.ok) {
                        throw new Error('Respuesta inesperada');
                    }

                    return respuesta.text();
                })
                .then(function (html) {
                    const nuevo = new DOMParser().parseFromString(html, 'text/html').getElementById(idObjetivo);

                    document.getElementById(idObjetivo).innerHTML = nuevo.innerHTML;
                    history.replaceState(null, '', url);
                })
                .catch(function (error) {
                    if (error.name !== 'AbortError') {
                        formulario.submit();
                    }
                });
        }

        campo.addEventListener('input', function () {
            const soloNumeros = campo.value.replace(/\D+/g, '').slice(0, 10);

            if (campo.value !== soloNumeros) {
                campo.value = soloNumeros;
            }

            clearTimeout(temporizador);
            temporizador = setTimeout(filtrar, 250);
        });
    })();
</script>
@else
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
@endif
