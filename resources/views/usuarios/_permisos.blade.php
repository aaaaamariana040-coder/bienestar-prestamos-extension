{{--
    Casillas de permisos de la cuenta, agrupadas por módulo.
    Variables: $grupos, $seleccionados, $defaultsPorRol, $exigidos, $dependientes, $soloAdministrador,
    $esPrincipal, $modo ('crear' | 'editar') y $personalizado (solo al editar).

    Al crear, cambiar el rol marca los permisos por defecto de ese perfil. Al editar, cambiar el rol NO
    toca las casillas: el botón «Restablecer a los permisos del perfil» las lleva a los del perfil.
    Usuarios y Auditoría son exclusivos del administrador principal: en su cuenta salen marcados y
    bloqueados; en las demás, deshabilitados. «gestionar» marca también los permisos que exige («ver»).
    El servidor aplica las mismas reglas (ignora lo que llegue en esas casillas).
--}}
<fieldset class="border rounded p-3 mb-3" id="permisos-cuenta">
    <legend class="float-none w-auto px-2 fs-6">
        Permisos de la cuenta
        <span id="etiqueta-personalizado" class="badge text-bg-warning ms-2 {{ ($personalizado ?? false) ? '' : 'd-none' }}">Personalizado</span>
    </legend>

    <input type="hidden" name="permisos_enviados" value="1">

    <p class="small text-muted">
        Marca lo que podrá hacer esta cuenta. Las casillas parten de los permisos del perfil elegido; «gestionar» marca también «ver».
        Solo el administrador principal gestiona usuarios y auditoría.
    </p>

    @if ($modo === 'editar')
        <button type="button" id="restablecer-permisos" class="btn btn-sm btn-outline-secondary mb-3">Restablecer a los permisos del perfil</button>
    @endif

    <div class="row">
        @foreach ($grupos as $grupo => $permisos)
            <div class="col-md-6 col-xl-4 mb-3">
                <h3 class="h6">{{ $grupo }}</h3>
                @foreach ($permisos as $permiso => $datos)
                    <div class="form-check mb-1">
                        <input class="form-check-input permiso" type="checkbox" name="permisos[]" value="{{ $permiso }}"
                               id="permiso-{{ str_replace('.', '-', $permiso) }}"
                               data-exige="{{ json_encode($exigidos[$permiso] ?? []) }}"
                               data-exigido-por="{{ json_encode($dependientes[$permiso] ?? []) }}"
                               data-solo-administrador="{{ in_array($permiso, $soloAdministrador, true) ? '1' : '0' }}"
                               @if (in_array($permiso, $soloAdministrador, true))
                                   @checked($esPrincipal) disabled
                               @else
                                   @checked(in_array($permiso, $seleccionados, true))
                               @endif>
                        <label class="form-check-label" for="permiso-{{ str_replace('.', '-', $permiso) }}">
                            {{ $datos['nombre'] }}
                            <small class="text-muted d-block">{{ $datos['descripcion'] }}</small>
                            @if (in_array($permiso, $soloAdministrador, true))
                                <small class="text-muted d-block fst-italic">{{ $esPrincipal ? 'Fijo: pertenece al administrador principal.' : 'Solo el administrador principal gestiona usuarios y auditoría.' }}</small>
                            @endif
                        </label>
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>
</fieldset>

<script>
    (function () {
        const rol = document.getElementById('rol');
        const cajas = Array.from(document.querySelectorAll('#permisos-cuenta input.permiso'));
        const defaults = @json($defaultsPorRol);
        const modo = @json($modo);
        const etiqueta = document.getElementById('etiqueta-personalizado');
        const esPrincipal = @json($esPrincipal);
        const porNombre = {};

        cajas.forEach(function (caja) {
            porNombre[caja.value] = caja;
        });

        function exigirDependencias(caja) {
            JSON.parse(caja.dataset.exige).forEach(function (nombre) {
                const otra = porNombre[nombre];

                if (otra && ! otra.checked && ! otra.disabled) {
                    otra.checked = true;
                    exigirDependencias(otra);
                }
            });
        }

        function quitarDependientes(caja) {
            JSON.parse(caja.dataset.exigidoPor).forEach(function (nombre) {
                const otra = porNombre[nombre];

                if (otra && otra.checked) {
                    otra.checked = false;
                    quitarDependientes(otra);
                }
            });
        }

        // Usuarios y Auditoría: marcadas y bloqueadas en el principal; deshabilitadas y vacías en las demás.
        function aplicarRol() {
            cajas.forEach(function (caja) {
                if (caja.dataset.soloAdministrador === '1') {
                    caja.disabled = true;
                    caja.checked = esPrincipal;
                }
            });
        }

        function marcadas() {
            return cajas.filter(function (caja) { return caja.checked; }).map(function (caja) { return caja.value; }).sort();
        }

        function actualizarEtiqueta() {
            const esperadas = (defaults[rol.value] || []).slice().sort();

            etiqueta.classList.toggle('d-none', JSON.stringify(marcadas()) === JSON.stringify(esperadas));
        }

        function aplicarDefaults() {
            const porDefecto = defaults[rol.value] || [];

            cajas.forEach(function (caja) {
                if (caja.dataset.soloAdministrador !== '1') {
                    caja.checked = porDefecto.indexOf(caja.value) !== -1;
                }
            });
        }

        cajas.forEach(function (caja) {
            caja.addEventListener('change', function () {
                if (caja.checked) {
                    exigirDependencias(caja);
                } else {
                    quitarDependientes(caja);
                }

                actualizarEtiqueta();
            });
        });

        rol.addEventListener('change', function () {
            aplicarRol();

            if (modo === 'crear') {
                aplicarDefaults();
            }

            actualizarEtiqueta();
        });

        const restablecer = document.getElementById('restablecer-permisos');

        if (restablecer) {
            restablecer.addEventListener('click', function () {
                aplicarRol();
                aplicarDefaults();
                actualizarEtiqueta();
            });
        }

        aplicarRol();
        actualizarEtiqueta();
    })();
</script>
