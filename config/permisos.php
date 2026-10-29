<?php

/*
 * Permisos por cuenta.
 *
 * Cada cuenta guarda su propia lista de permisos (usuarios.permisos, JSON). Esta
 * configuración define:
 *  - el catálogo de permisos (los que se muestran como casillas al crear/editar
 *    un usuario) y a qué grupo pertenecen;
 *  - qué permisos exigen otros (gestionar implica ver);
 *  - cuáles solo puede tener un Administrador;
 *  - los permisos POR DEFECTO de cada perfil (rol).
 *
 * Cada permiso es un Gate con su mismo nombre: las rutas usan el middleware
 * «can:nombre», las vistas @can('nombre') y las policies Usuario::tienePermiso().
 * Un permiso nuevo exige agregarlo aquí, protegerlo en rutas/policies y, si las
 * cuentas existentes deben tenerlo, una migración que se lo asigne (ver
 * 2026_10_22_100000_agrega_permisos_a_usuarios_table).
 */

return [

    /*
     * Catálogo de grano medio: ver / gestionar por módulo, más permisos propios
     * para acciones irreversibles, financieras o salientes.
     */
    'catalogo' => [
        // Administración
        'usuarios.gestionar' => ['grupo' => 'Administración', 'nombre' => 'Gestionar usuarios', 'descripcion' => 'Ver, crear y editar cuentas, sus permisos, y habilitarlas o deshabilitarlas.'],
        'auditoria.ver' => ['grupo' => 'Administración', 'nombre' => 'Consultar la auditoría', 'descripcion' => 'Ver el registro de acciones del sistema.'],

        // Empleados
        'empleados.ver' => ['grupo' => 'Empleados', 'nombre' => 'Ver empleados', 'descripcion' => 'Listar y consultar empleados.'],
        'empleados.gestionar' => ['grupo' => 'Empleados', 'nombre' => 'Crear y editar empleados', 'descripcion' => 'Registrar empleados y modificar sus datos.'],
        'empleados.eliminar' => ['grupo' => 'Empleados', 'nombre' => 'Eliminar empleados', 'descripcion' => 'Borrar un empleado (acción irreversible).'],
        'empleados.registrar-retiro' => ['grupo' => 'Empleados', 'nombre' => 'Registrar el retiro de un empleado', 'descripcion' => 'Retirar a un empleado y liquidar sus préstamos.'],

        // Modalidades y presupuesto
        'modalidades.ver' => ['grupo' => 'Modalidades y presupuesto', 'nombre' => 'Ver modalidades y tasas', 'descripcion' => 'Consultar modalidades, tasas, cuantías y tasa de vehículo.'],
        'modalidades.gestionar' => ['grupo' => 'Modalidades y presupuesto', 'nombre' => 'Gestionar modalidades y tasas', 'descripcion' => 'Crear y editar modalidades, habilitarlas, y registrar tasas, cuantías y tasa de vehículo.'],
        'presupuesto.ver' => ['grupo' => 'Modalidades y presupuesto', 'nombre' => 'Ver el presupuesto', 'descripcion' => 'Consultar el presupuesto anual por modalidad.'],
        'presupuesto.gestionar' => ['grupo' => 'Modalidades y presupuesto', 'nombre' => 'Gestionar el presupuesto', 'descripcion' => 'Crear y editar presupuestos anuales.'],
        'smmlv.ver' => ['grupo' => 'Modalidades y presupuesto', 'nombre' => 'Ver el salario mínimo (SMMLV)', 'descripcion' => 'Consultar el SMMLV de cada año.'],
        'smmlv.gestionar' => ['grupo' => 'Modalidades y presupuesto', 'nombre' => 'Registrar el salario mínimo (SMMLV)', 'descripcion' => 'Registrar el SMMLV de un año.'],
        'seguros.ver' => ['grupo' => 'Modalidades y presupuesto', 'nombre' => 'Ver las primas de seguros', 'descripcion' => 'Consultar la prima de cada seguro por año.'],
        'seguros.gestionar' => ['grupo' => 'Modalidades y presupuesto', 'nombre' => 'Gestionar las primas de seguros', 'descripcion' => 'Registrar la prima de un año y editar las ya registradas.'],

        // Solicitudes
        'solicitudes.ver' => ['grupo' => 'Solicitudes', 'nombre' => 'Ver solicitudes', 'descripcion' => 'Listar y consultar solicitudes y sus documentos.'],
        'solicitudes.registrar' => ['grupo' => 'Solicitudes', 'nombre' => 'Registrar solicitudes y subir documentos', 'descripcion' => 'Crear solicitudes y adjuntarles documentos.'],
        'solicitudes.eliminar-documentos' => ['grupo' => 'Solicitudes', 'nombre' => 'Borrar documentos de una solicitud', 'descripcion' => 'Eliminar un documento adjunto (acción irreversible).'],
        'solicitudes.aprobar' => ['grupo' => 'Solicitudes', 'nombre' => 'Aprobar solicitudes', 'descripcion' => 'Aprobar una solicitud radicada: genera el préstamo y su tabla de amortización.'],
        'solicitudes.rechazar' => ['grupo' => 'Solicitudes', 'nombre' => 'Rechazar solicitudes', 'descripcion' => 'Rechazar una solicitud radicada indicando el motivo.'],
        'solicitudes.enviar-comite' => ['grupo' => 'Solicitudes', 'nombre' => 'Enviar solicitudes al comité', 'descripcion' => 'Enviar una solicitud radicada por correo al comité.'],
        'simulador.usar' => ['grupo' => 'Solicitudes', 'nombre' => 'Usar el simulador de crédito', 'descripcion' => 'Simular cuota, tabla de amortización y capacidad de endeudamiento.'],
        'proveedores.ver' => ['grupo' => 'Solicitudes', 'nombre' => 'Ver proveedores', 'descripcion' => 'Consultar los proveedores (terceros a quienes se paga).'],
        'proveedores.gestionar' => ['grupo' => 'Solicitudes', 'nombre' => 'Registrar proveedores', 'descripcion' => 'Crear proveedores.'],

        // Préstamos y pagos
        'prestamos.ver' => ['grupo' => 'Préstamos y pagos', 'nombre' => 'Ver préstamos', 'descripcion' => 'Listar y consultar préstamos, cuotas y pagos.'],
        'prestamos.cargar_vigentes' => ['grupo' => 'Préstamos y pagos', 'nombre' => 'Cargar préstamos vigentes', 'descripcion' => 'Cargar por archivo la cartera de préstamos ya vigentes y revertir un lote cargado.'],
        'desembolsos.registrar' => ['grupo' => 'Préstamos y pagos', 'nombre' => 'Registrar desembolsos', 'descripcion' => 'Registrar los tramos desembolsados de un préstamo.'],
        'pagos.registrar-cuota' => ['grupo' => 'Préstamos y pagos', 'nombre' => 'Registrar el pago de una cuota', 'descripcion' => 'Marcar una cuota como pagada.'],
        'pagos.registrar-abono' => ['grupo' => 'Préstamos y pagos', 'nombre' => 'Registrar abonos', 'descripcion' => 'Abonos extraordinarios y de cesantías, con reliquidación.'],
        'pagos.cancelar-prestamo' => ['grupo' => 'Préstamos y pagos', 'nombre' => 'Cancelar un préstamo (cancelación total)', 'descripcion' => 'Pagar de una vez todo el saldo y cerrar el préstamo.'],
        'quincenas.ver' => ['grupo' => 'Préstamos y pagos', 'nombre' => 'Ver las quincenas', 'descripcion' => 'Consultar la vista previa y los procesos de quincena.'],
        'quincenas.confirmar' => ['grupo' => 'Préstamos y pagos', 'nombre' => 'Confirmar la quincena', 'descripcion' => 'Pagar de una vez las cuotas de una quincena.'],

        // Reportes
        'reportes.ver' => ['grupo' => 'Reportes', 'nombre' => 'Ver reportes e informes', 'descripcion' => 'Reportes de préstamos, pagos, cartera y presupuesto, e informes guardados.'],
        'informes.guardar' => ['grupo' => 'Reportes', 'nombre' => 'Guardar informes', 'descripcion' => 'Guardar una copia de un informe.'],
    ],

    /*
     * Permisos que exige otro: marcar el de la izquierda marca también los de la
     * derecha («gestionar» implica «ver»). Se aplica en el formulario y en el servidor.
     */
    'dependencias' => [
        'empleados.gestionar' => ['empleados.ver'],
        'empleados.eliminar' => ['empleados.ver'],
        'empleados.registrar-retiro' => ['empleados.ver'],
        'modalidades.gestionar' => ['modalidades.ver'],
        'presupuesto.gestionar' => ['presupuesto.ver'],
        'smmlv.gestionar' => ['smmlv.ver'],
        'seguros.gestionar' => ['seguros.ver'],
        'solicitudes.registrar' => ['solicitudes.ver'],
        'solicitudes.eliminar-documentos' => ['solicitudes.ver'],
        'solicitudes.aprobar' => ['solicitudes.ver'],
        'solicitudes.rechazar' => ['solicitudes.ver'],
        'solicitudes.enviar-comite' => ['solicitudes.ver'],
        'proveedores.gestionar' => ['proveedores.ver'],
        'prestamos.cargar_vigentes' => ['prestamos.ver'],
        'desembolsos.registrar' => ['prestamos.ver'],
        'pagos.registrar-cuota' => ['prestamos.ver'],
        'pagos.registrar-abono' => ['prestamos.ver'],
        'pagos.cancelar-prestamo' => ['prestamos.ver'],
        'quincenas.confirmar' => ['quincenas.ver'],
        'informes.guardar' => ['reportes.ver'],
    ],

    /*
     * Solo el administrador principal (usuarios.es_principal) tiene estos permisos, y
     * siempre los tiene: quien gestiona usuarios podría darse cualquier otro permiso, y
     * la auditoría es el control sobre todo lo demás. Los demás administradores y el
     * Funcionario no los tienen aunque la lista guardada los traiga. Se aplica en el
     * formulario y en el servidor. (El nombre de la clave es anterior a «principal».)
     */
    'solo_administrador' => [
        'usuarios.gestionar',
        'auditoria.ver',
    ],

    /*
     * PERMISOS POR DEFECTO DE CADA PERFIL — PENDIENTES DE CONFIRMACIÓN DE BIENESTAR.
     *
     * Este es el único lugar donde se cambian. Sintaxis de cada lista:
     *   '*'        todos los permisos del catálogo;
     *   'nombre'   ese permiso;
     *   '!nombre'  quita ese permiso (se aplica después de los anteriores).
     *
     * Valen para las cuentas NUEVAS (casillas marcadas al elegir el perfil) y para el
     * botón «Restablecer a los permisos del perfil». No cambian las cuentas que ya
     * existen: cada una conserva su propia lista.
     *
     * Hoy el Funcionario puede hacer todo menos Usuarios, Auditoría, gestionar las
     * primas de seguros (solo las ve) y cargar préstamos vigentes: fuera de esos cuatro,
     * nada cambia respecto al sistema sin permisos por cuenta.
     */
    'defaults' => [
        'ADMINISTRADOR' => ['*'],
        'FUNCIONARIO_BIENESTAR' => ['*', '!usuarios.gestionar', '!auditoria.ver', '!seguros.gestionar', '!prestamos.cargar_vigentes'],
    ],

];
