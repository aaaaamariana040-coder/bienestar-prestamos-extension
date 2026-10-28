# Plan de Pruebas
Aplicación de Préstamos de Bienestar Social

Este plan cubre las funcionalidades construidas hasta el Sprint 4 (según `plan-sprints-interno.md`). Los casos de retiro y liquidación del empleado quedan marcados como **pendientes**, a la espera de la próxima entrevista con el área de Bienestar Social para confirmar el proceso.

**Leyenda de "Resultado obtenido":** ⬜ Sin ejecutar · ✅ Pasó · ❌ Falló · ⚠️ Pasó con observación

## 1. Autenticación y usuarios (RF-001)

| ID | Caso de prueba | Precondición | Pasos | Resultado esperado | Resultado obtenido |
|---|---|---|---|---|---|
| PP-001 | Login con credenciales válidas | Usuario habilitado existente | Ingresar cédula y contraseña correctas | Redirige a empleados.index, sesión iniciada | ⬜ |
| PP-002 | Login con contraseña incorrecta | Usuario existente | Ingresar cédula correcta, contraseña incorrecta | Muestra error "La cédula o la contraseña no son correctas", no autentica | ⬜ |
| PP-003 | Login con usuario deshabilitado | Usuario con `habilitado = false` | Ingresar credenciales correctas | Login rechazado (por la validación `habilitado => true` en `Auth::attempt`) | ⬜ |
| PP-004 | Logout | Sesión iniciada | Cerrar sesión | Sesión invalidada, redirige a login | ⬜ |
| PP-005 | Crear usuario — rol Administrador | Autenticado como Administrador | Crear usuario para un empleado existente | Usuario creado, contraseña hasheada | ⬜ |
| PP-006 | Crear usuario — rol Funcionario (debe fallar) | Autenticado como Funcionario Bienestar | Intentar acceder a "Crear usuario" | Respuesta 403, enlace ni siquiera visible en el menú | ⬜ |
| PP-007 | Consultar/listar usuarios | Autenticado como Administrador | Ir al listado de usuarios | Muestra todos los usuarios registrados | ⬜ |
| PP-008 | Editar usuario | Autenticado como Administrador | Editar nombre/rol de un usuario | Cambios guardados correctamente | ⬜ |
| PP-009 | Deshabilitar usuario | Autenticado como Administrador | Deshabilitar una cuenta activa | Usuario ya no puede iniciar sesión (ver PP-003) | ⬜ |
| PP-010 | Cambio de contraseña propia | Cualquier usuario autenticado | Cambiar su propia contraseña | Contraseña actualizada, login funciona con la nueva | ⬜ |

## 2. Gestión de empleados (RF-002)

| ID | Caso de prueba | Precondición | Pasos | Resultado esperado | Resultado obtenido |
|---|---|---|---|---|---|
| PP-011 | Crear empleado | Autenticado | Registrar empleado con datos válidos | Empleado creado; `capacidad_endeudamiento` autocalculado al 50% del salario | ⬜ |
| PP-012 | Crear empleado con capacidad manual | Autenticado | Ingresar `capacidad_endeudamiento` manualmente distinto al 50% | Se respeta el valor manual, no se sobreescribe | ⬜ |
| PP-013 | Editar empleado | Empleado existente | Actualizar nombre/cargo/salario | Cambios guardados; si `capacidad_endeudamiento` no se toca, se recalcula | ⬜ |
| PP-014 | Eliminar empleado | Empleado existente (los usuarios son independientes de los empleados) | Eliminar y confirmar en el modal | Empleado eliminado correctamente | ⬜ |
| PP-015 | ~~Eliminar empleado con usuario asociado (debe fallar)~~ | — | — | **Retirado:** los usuarios ya no dependen de los empleados, por lo que no existe este bloqueo | ➖ |
| PP-016 | Listar/buscar empleados | Varios empleados registrados | Buscar por cédula o nombre | Resultados filtrados correctamente | ⬜ |
| PP-017 | Consultar historial crediticio de un empleado | Empleado con préstamos | Ver detalle del empleado | Muestra préstamos vigentes y finalizados | ⬜ |
| PP-018 | Validar campos excluidos del formulario | — | Enviar `fecha_solicitud_credito`/`fecha_autorizacion_credito` manualmente en el POST | El sistema los ignora (no pertenecen a `empleado`) | ⬜ |

## 3. Modalidades y parámetros (RF-003)

| ID | Caso de prueba | Precondición | Pasos | Resultado esperado | Resultado obtenido |
|---|---|---|---|---|---|
| PP-019 | Crear modalidad de crédito | Autenticado | Registrar modalidad con monto máximo, plazo y tasa | Modalidad creada; plazo almacenado en quincenas (el formulario no pide unidad) | ⬜ |
| PP-020 | Tope de tasa (≤5% EA vivienda) | Modalidad vivienda con IPC registrado de 5,20 % | Registrar el IPC y consultar la tasa en una solicitud nueva | El IPC se registra tal cual (5,20 %) y la tasa aplicada es 5 % con la nota del Acuerdo 11/2023; ver PP-120 a PP-129 | ⬜ |
| PP-021 | Visualización del plazo en quincenas | Modalidad vivienda con 480 quincenas | Ver el plazo en la interfaz | Se muestra "480 quincenas (≈ 20 años)"; educación muestra "72 quincenas (≈ 36 meses)" (meses hasta 120 quincenas, años desde ahí) | ⬜ |
| PP-022 | Tasa especial de vehículo | Modalidad vehículo | Registrar solicitante con primera vs. segunda solicitud, distintos plazos | Tasa calculada según tabla `tasa_vehiculo` (IPC+0 a IPC+5 según corresponda) | ⬜ |
| PP-023 | Deshabilitar modalidad | Modalidad con presupuesto agotado | Deshabilitar | Modalidad ya no aparece disponible para nuevas solicitudes | ⬜ |

## 4. Presupuesto del Fondo (RF-004)

| ID | Caso de prueba | Precondición | Pasos | Resultado esperado | Resultado obtenido |
|---|---|---|---|---|---|
| PP-024 | Registrar presupuesto anual por modalidad | Autenticado | Asignar monto a una modalidad para un año | Presupuesto creado, disponible = asignado - comprometido | ⬜ |
| PP-025 | Consultar disponibilidad presupuestal | Presupuesto con préstamos aprobados | Ver el presupuesto de una modalidad | Muestra asignado, comprometido y disponible actualizados | ⬜ |
| PP-026 | Historial de modificaciones presupuestales | Presupuesto modificado al menos una vez | Consultar historial | Muestra monto anterior, nuevo, motivo, usuario y fecha | ⬜ |

## 5. Solicitudes de crédito (RF-005)

| ID | Caso de prueba | Precondición | Pasos | Resultado esperado | Resultado obtenido |
|---|---|---|---|---|---|
| PP-027 | Registrar solicitud válida | Empleado activo, modalidad habilitada | Registrar solicitud dentro de los topes | Solicitud creada en estado "Radicada" | ⬜ |
| PP-028 | Validar monto superior al máximo de la modalidad | — | Solicitar monto mayor al permitido | Rechazado con mensaje de validación | ⬜ |
| PP-029 | Validar capacidad de endeudamiento excedida | Empleado con capacidad baja | Solicitar cuota que exceda su capacidad | Rechazado o marcado como no viable | ⬜ |
| PP-030 | Validar disponibilidad presupuestal | Presupuesto agotado en la modalidad | Registrar solicitud | Rechazado por falta de disponibilidad presupuestal | ⬜ |
| PP-031 | Consultar/filtrar solicitudes | Varias solicitudes registradas | Filtrar por estado, modalidad, empleado | Resultados correctos | ⬜ |

## 6. Préstamos y desembolsos (RF-006)

| ID | Caso de prueba | Precondición | Pasos | Resultado esperado | Resultado obtenido |
|---|---|---|---|---|---|
| PP-032 | Aprobar solicitud y generar préstamo | Solicitud radicada | Marcar como aprobada | Préstamo creado, vinculado a la solicitud | ⬜ |
| PP-033 | Desembolso único | Préstamo de modalidad distinta a construcción/mejoras | Registrar desembolso | Un solo registro, porcentaje 100% | ⬜ |
| PP-034 | Desembolso en dos cuotas (construcción/mejoras) | Préstamo de construcción o mejoras | Registrar primer desembolso | Se crea con 50% (no 80/20 — verificar corrección aplicada) | ⬜ |
| PP-035 | Segundo desembolso sujeto a inspección | Primer desembolso registrado | Confirmar inspección con avance del 80% de la primera cuota | Segundo desembolso (50% restante) se habilita solo tras la inspección aprobada | ⬜ |
| PP-036 | Desembolso a proveedor (deshipoteca) | Modalidad deshipoteca | Registrar desembolso con proveedor (entidad financiera acreedora) | Desembolso asociado al proveedor, no al empleado directamente | ⬜ |

## 7. Amortización y pagos (RF-007)

| ID | Caso de prueba | Precondición | Pasos | Resultado esperado | Resultado obtenido |
|---|---|---|---|---|---|
| PP-037 | Generación de tabla de amortización | Préstamo aprobado | Consultar tabla de cuotas | Cuotas generadas con capital, interés, total y fecha de vencimiento | ⬜ |
| PP-038 | Registrar pago regular sobre una cuota | Cuota pendiente | Registrar pago | Cuota marcada como pagada; pago vinculado a `id_cuota` | ⬜ |
| PP-039 | Registrar pago directo a capital (abono extraordinario) | Préstamo vigente | Registrar pago sin asociarlo a una cuota específica | Pago marcado explícitamente como "a capital", `id_cuota` nulo | ⬜ |
| PP-040 | Consultar estado de cuenta | Préstamo con pagos parciales | Consultar detalle | Muestra saldo, cuotas pendientes, cuotas pagadas | ⬜ |
| PP-041 | Identificar cuotas vencidas | Cuota con fecha de vencimiento pasada, sin pago | Consultar vencimientos | Cuota marcada como "Vencida" | ⬜ |

## 8. Situaciones especiales del préstamo (RF-008)

| ID | Caso de prueba | Precondición | Pasos | Resultado esperado | Resultado obtenido |
|---|---|---|---|---|---|
| PP-042 | Reliquidación por abono extraordinario | Abono superior a 10 cuotas quincenales, en cualquier modalidad | Registrar abono grande y elegir bajar la cuota o el plazo | Saldo recalculado, cuotas pendientes recalculadas con sus fechas; sin elección no se guarda nada | ⬜ |
| PP-042a | Abono menor o igual al umbral (reliquidación automática) | Préstamo vigente; abono de hasta 10 cuotas (probar 8 cuotas y exactamente 10) | Registrar el abono sin elegir opción | Saldo de capital baja, cuota igual, menos cuotas pendientes con la última menor, fechas conservadas; historial «Bajó el plazo (automática: abono bajo el umbral)» | ⬜ |
| PP-042b | Abono menor que una cuota | Préstamo vigente | Abonar media cuota | El saldo baja aunque el número de cuotas no cambie; la última cuota queda menor | ⬜ |
| PP-042c | Abono que cancela el saldo | Abono igual o mayor al saldo de capital | Registrar el abono | Rechazado con aviso de «Cancelación total»; nada se guarda | ⬜ |
| PP-043 | Registro de abono de cesantías | Préstamo de vivienda, febrero de año bianual correspondiente | Registrar abono de cesantías (50%) | Abono registrado y aplicado al saldo | ⬜ |
| PP-044 *(pendiente)* | Retiro del empleado — liquidación de saldo | *(pendiente de confirmar proceso con el área)* | — | — | ⬜ |
| PP-045 *(pendiente)* | Retiro del empleado — reliquidación a tasa DTF | *(pendiente de confirmar proceso con el área)* | — | — | ⬜ |

## 9. Reportes e indicadores (RF-009)

| ID | Caso de prueba | Precondición | Pasos | Resultado esperado | Resultado obtenido |
|---|---|---|---|---|---|
| PP-046 | Reporte de cartera vigente | Préstamos activos registrados | Generar reporte | Muestra saldo pendiente por empleado/préstamo | ⬜ |
| PP-047 | Reporte de pagos por período | Pagos registrados en un rango de fechas | Filtrar por período | Muestra pagos correctos del rango | ⬜ |
| PP-048 | Reporte de ejecución presupuestal por modalidad | Presupuesto con compromisos | Generar reporte | Muestra asignado/comprometido/disponible por modalidad | ⬜ |
| PP-049 | Dashboard de indicadores | Datos de prueba cargados | Ver dashboard | Muestra totales de préstamos, monto prestado y cartera | ⬜ |

## 10. Auditoría y trazabilidad (RF-010)

| ID | Caso de prueba | Precondición | Pasos | Resultado esperado | Resultado obtenido |
|---|---|---|---|---|---|
| PP-050 | Registro automático de auditoría | Cualquier operación crítica (crear/editar/eliminar) | Ejecutar la operación | Se crea un registro en `auditoria` con usuario, función y fecha/hora | ⬜ |
| PP-051 | Consultar historial de auditoría | Varios registros de auditoría existentes | Consultar por usuario o fecha | Resultados filtrados correctamente | ⬜ |
| PP-052 | Trazabilidad completa de un préstamo | Préstamo con solicitud, desembolso y pagos | Consultar trazabilidad | Se puede reconstruir la línea de tiempo completa del préstamo | ⬜ |

## 11. Confirmación de la quincena (RF-007)

Detalle del diseño en `docs/quincena-automatica.md`. Automatizados en `tests/Feature/ConfirmarQuincenaTest.php`; la columna final queda ⬜ hasta la prueba manual en el navegador.

| ID | Caso de prueba | Precondición | Pasos | Resultado esperado | Resultado obtenido |
|---|---|---|---|---|---|
| PP-059 | Vista previa sin escribir | Cuotas que vencen en una quincena ya iniciada | Abrir `Pagos › Quincenas › Revisar y confirmar` | Lista las cuotas con casillas de exclusión y el total; no se crean pagos, procesos ni auditoría | ⬜ |
| PP-060 | Aviso en Inicio y menú | Quincena iniciada, sin proceso confirmado y con cuotas candidatas | Entrar a Inicio | Banner con las quincenas pendientes e insignia en el menú; desaparece al confirmar y no se muestra si no hay cuotas candidatas ni a cuentas que no operan | ⬜ |
| PP-061 | Listar todas las quincenas iniciadas sin confirmar | Cuotas en varias quincenas anteriores | Abrir `Quincenas` | Aparecen todas, no solo la actual; las futuras no | ⬜ |
| PP-062 | Confirmar la quincena | Vista previa con cuotas (incluye un préstamo En mora) | Pulsar «Confirmar quincena» | Una sola transacción: proceso «Completado» a nombre de quien confirma; pagos Regular, origen Automático, autor = quien confirma, con `id_proceso_quincena`; cuotas Pagadas | ⬜ |
| PP-063 | Fecha del pago | Quincena cuyo corte ya pasó / quincena en curso | Confirmar cada una | Fecha de pago = corte; en la quincena en curso = hoy | ⬜ |
| PP-064 | Auditoría de la confirmación | Confirmación con una cuota excluida | Consultar `Auditoría` | Una «Pago de cuota» por cada cuota pagada y una «Confirmación de quincena» con quién, cuántas cuotas, total y excluidas con motivo | ⬜ |
| PP-065 | Excluir una cuota con motivo | Vista previa con 2 cuotas | Marcar una, escribir el motivo, confirmar | La excluida queda Pendiente y su motivo se guarda; en la quincena siguiente aparece como vencida anterior | ⬜ |
| PP-066 | Excluir sin motivo | Vista previa con casilla marcada | Dejar el motivo vacío y confirmar | Se rechaza con el mensaje del motivo; no se crea proceso ni pagos | ⬜ |
| PP-067 | Dos cuotas del mismo préstamo | Préstamo con 2 cuotas en la misma quincena | Abrir la vista previa | Filas resaltadas y advertencia; se puede excluir una | ⬜ |
| PP-068 | Última cuota que cierra el préstamo | Préstamo con una sola cuota pendiente en la quincena | Confirmar | El préstamo pasa a Cancelado | ⬜ |
| PP-069 | Doble confirmación | Quincena ya confirmada | Confirmar de nuevo (otra pestaña u otra persona) | «La quincena N de AAAA ya fue confirmada por X el dd/mm/aaaa hh:mm»; sin pagos nuevos | ⬜ |
| PP-070 | Doble confirmación concurrente | Dos personas confirman a la vez | Enviar ambas confirmaciones | Una gana; la otra la rechaza la restricción única y no paga nada | ⬜ |
| PP-071 | Quincena no iniciada | Quincena futura | Abrir su vista previa o enviar la confirmación | Se rechaza («todavía no empieza»); no se escribe nada | ⬜ |
| PP-072 | Pago manual entre la vista previa y la confirmación | Vista previa abierta | En otra pestaña pagar una cuota de la lista; confirmar | Se rechaza, se recarga la vista previa y se muestra la diferencia; no se paga nada | ⬜ |
| PP-073 | Reliquidación entre la vista previa y la confirmación | Vista previa abierta | En otra pestaña reliquidar un préstamo de la lista; confirmar | Se rechaza y se muestra la diferencia (las cuotas se regeneraron) | ⬜ |
| PP-074 | Cancelación entre la vista previa y la confirmación | Vista previa abierta | En otra pestaña cancelar un préstamo de la lista; confirmar | Se rechaza y se muestra la diferencia | ⬜ |
| PP-075 | Atomicidad | Falla al pagar la 2.ª cuota | Confirmar | No queda proceso, ni pagos, ni auditoría de la confirmación; todas las cuotas siguen Pendientes | ⬜ |
| PP-076 | Confirmar fuera de orden | Dos quincenas iniciadas sin confirmar | Confirmar la más reciente primero | Se permite | ⬜ |
| PP-077 | Permisos | Administrador, Funcionario, invitado y cuenta deshabilitada | Abrir `Quincenas` y confirmar | Administrador y Funcionario pueden; el invitado y la cuenta deshabilitada son enviados al inicio de sesión | ⬜ |
| PP-078 | Detalle de un proceso confirmado | Proceso con cuotas pagadas y excluidas | Abrir «Ver» en Quincenas confirmadas | Muestra quién, cuándo, cuotas pagadas y excluidas con su motivo | ⬜ |
| PP-079 | Comando en modo confirmar | `QUINCENA_MODO_MARCADO=confirmar` | `php artisan quincena:procesar --anio=… --quincena=… --ejecutar` | Muestra la vista previa y no crea proceso ni pagos | ⬜ |
## 12. Envío de solicitudes al comité

Automatizados en `tests/Feature/EnvioComiteTest.php` (con `Mail::fake` y `Storage::fake`; nunca se envía correo real). La columna final queda ⬜ hasta la prueba manual en el navegador.

| ID | Caso de prueba | Precondición | Pasos | Resultado esperado | Resultado obtenido |
|---|---|---|---|---|---|
| PP-080 | Botón y estado | Solicitudes Radicada, Aprobada y Rechazada | Abrir cada una | «Enviar al comité» solo en la Radicada; en otro estado el servidor rechaza el envío | ⬜ |
| PP-081 | Permiso | Administrador, Funcionario, invitado, cuenta deshabilitada y rol sin el permiso | Abrir la vista previa y enviar | Administrador y Funcionario pueden; invitado y deshabilitada van al login; sin permiso, 403 y sin botón | ⬜ |
| PP-082 | Vista previa | Solicitud con documentos | Pulsar «Enviar al comité» | Muestra Para, Con copia, Asunto, cuerpo y adjuntos; no envía ni guarda nada | ⬜ |
| PP-083 | Contenido del cuerpo | Solicitud con tasa y capacidad | Ver la vista previa | Empleado, cédula, modalidad, monto, plazo, tasa, cuota y capacidad; **sin salario**; observaciones si hay incumplimientos y el interruptor está activo | ⬜ |
| PP-084 | Envío | Vista previa sin bloqueos | Pulsar «Enviar» | Un correo con los adjuntos marcados; registro (quién, cuándo, destinatarios, adjuntos, cifras); auditoría; marca «Enviada al comité»; el estado sigue Radicada y aprobar/rechazar funcionan | ⬜ |
| PP-085 | Simulado en desarrollo | `MAIL_MAILER=log` | Enviar | Pantalla y registro dicen «simulado (no se envió)»; el log tiene solo destinatarios, asunto y nombres/tamaños de adjuntos | ⬜ |
| PP-086 | Producción sin correo real | Entorno production con `log`/`array` o direcciones `.test` | Enviar | Se rechaza con «correo no configurado»; no hay registro ni marca | ⬜ |
| PP-087 | Sin destinatarios | `COMITE_DESTINATARIOS` vacío | Abrir la vista previa | Mensaje claro y botón deshabilitado; el servidor también rechaza | ⬜ |
| PP-088 | Reenvío | Solicitud ya enviada | Reenviar sin y con «Confirmo el reenvío» | Sin confirmar se rechaza; confirmado, sale otro correo marcado como reenvío con su auditoría | ⬜ |
| PP-089 | Doble clic | Solicitud recién enviada | Enviar de nuevo de inmediato | El botón se deshabilita al enviar y el servidor rechaza un segundo envío en menos de 60 s | ⬜ |
| PP-090 | Fallo del correo | Servidor de correo que falla | Enviar | Mensaje claro; sin registro «Enviado» ni marca; el intento fallido queda en la auditoría | ⬜ |
| PP-091 | Adjuntos | Documentos de distinto tamaño, uno sin archivo | Marcar/desmarcar y enviar | Solo van los marcados; el archivo faltante y el exceso de 15 MB bloquean el envío; sin documentos se permite con aviso (o se exige con el interruptor) | ⬜ |

## 13. Permisos por cuenta (RF-001, RNF-001)

Automatizados en `tests/Feature/PermisosCaracterizacionTest.php`, `PermisosCatalogoTest.php`, `PermisosPorCuentaTest.php` y `UsuarioSalvaguardasTest.php`. La columna final queda ⬜ hasta la prueba manual en el navegador.

| ID | Caso de prueba | Precondición | Pasos | Resultado esperado | Resultado obtenido |
|---|---|---|---|---|---|
| PP-092 | El Funcionario conserva lo que hacía | Cuenta Funcionario con los permisos por defecto | Recorrer todas las rutas de la aplicación | Solo recibe 403 en Usuarios y Auditoría; en todo lo demás pasa la autorización. El Administrador pasa en todas | ⬜ |
| PP-093 | Defaults del perfil | Catálogo de `config/permisos.php` | Comparar los defaults | Funcionario = catálogo − Usuarios − Auditoría; Administrador = todos; cambiar los defaults no altera las cuentas existentes | ⬜ |
| PP-094 | Ninguna ruta sin permiso | Rutas con nombre | Revisar el middleware de cada ruta | Todas llevan `can:` salvo inicio y contraseña propia; cada permiso del catálogo protege alguna ruta | ⬜ |
| PP-095 | Sin el permiso, 403 | Cuenta con todos los permisos menos uno | Abrir cada ruta de ese permiso, también escribiendo la dirección a mano | 403; con solo ese permiso la ruta pasa la autorización | ⬜ |
| PP-096 | Menú y botones por permiso | Cuentas con permisos recortados | Entrar con cada una | El menú y los botones (aprobar, rechazar, documentos, pagos, abonos, cancelación, empleados) solo aparecen con su permiso; una cuenta sin permisos ve solo el inicio | ⬜ |
| PP-097 | Crear usuario con casillas | Administrador en «Nuevo usuario» | Elegir perfil y marcar/desmarcar casillas | Las casillas parten del perfil; «gestionar» marca «ver»; Usuarios y Auditoría solo con rol Administrador (el servidor lo rechaza si no) | ⬜ |
| PP-098 | Editar sin sobrescribir | Cuenta con permisos personalizados | Cambiar el rol y guardar | Las casillas no cambian; aparece «Personalizado»; «Restablecer a los permisos del perfil» las devuelve a las del perfil | ⬜ |
| PP-099 | Último administrador activo | Un solo administrador activo | Intentar quitarle permisos o cambiarle el rol | Se rechaza con un mensaje claro; puede cambiar su nombre y recibir más permisos | ⬜ |
| PP-100 | Auto-bloqueo y gestión de usuarios | Dos administradores | Quitarse a sí mismo Usuarios; dejar al sistema sin cuenta que gestione usuarios | Se rechaza en ambos casos; sí puede quitarle Usuarios a otro mientras quede quien gestione | ⬜ |
| PP-101 | Auditoría de cuentas | Administrador con sesión | Crear usuario, cambiar rol, cambiar permisos, habilitar y deshabilitar | Cada acción deja su registro: quién, a quién y, en permisos, qué se agregó y qué se quitó; sin cambios o con un cambio rechazado no se registra nada | ⬜ |
| PP-102 | Migración de cuentas existentes | Cuentas anteriores a los permisos por cuenta | Aplicar la migración | Administrador recibe todos; Funcionario, todos menos Usuarios y Auditoría; «Sistema», ninguno | ⬜ |

## 14. Formato de montos y mensajes en español (RNF-007)

Automatizados en `tests/Feature/MontosFormatoTest.php`. Los montos se normalizan con `NormalizaMontos` (que usa `MontoColombiano::normalizar()`) y se validan con la regla `App\Rules\MontoColombiano`. Un punto o una coma seguidos de exactamente tres dígitos son separador de miles (`150.000` = 150000, `7.500` = 7500), porque los pesos llevan máximo dos decimales en el abono extraordinario (y su vista previa), el abono de cesantías, la solicitud, el simulador (monto y retención) y el SMMLV. Los mensajes salen de `lang/es/validation.php` (`APP_LOCALE=es`).

| ID | Caso de prueba | Precondición | Pasos | Resultado esperado | Resultado obtenido |
|---|---|---|---|---|---|
| PP-103 | Formatos de monto aceptados | Cualquiera de los campos de monto | Escribir `7.520.507,28`, `7520507.28`, `7520507`, `7.520.507`, `7,520,507.28`, `$ 7.520.507,28`, `150.000`, `7.500` | Todos se aceptan y se guardan como `7520507.28`, `7520507.00`, `150000.00` o `7500.00` | ⬜ |
| PP-104 | Más de dos decimales | Campos de monto | Escribir `7.520,50728`, `7,520.50728`, `7520507.28123`, `7.520.507,281`, `7520.507`, `0.500` | Un solo error en español: «El monto debe escribirse con máximo dos decimales (por ejemplo 7.520.507,28).»; no se guarda nada | ⬜ |
| PP-105 | Formato irreconocible | Campos de monto | Escribir `abc`, `7.50.000`, `12,5,5`, `7.520,` | Un solo error: «Escribe el monto solo con números, puntos de miles y coma decimal (por ejemplo 7.520.507,28).» | ⬜ |
| PP-106 | Sin errores duplicados | Monto inválido | Enviar el formulario | No aparecen a la vez los errores de «debe ser un número» y «al menos 1000» | ⬜ |
| PP-107 | Mensajes de validación en español | Formularios con campos vacíos o fecha inválida | Enviar el formulario | «El campo monto es obligatorio.», «El campo fecha de pago debe ser una fecha válida.», etc.; ningún texto en inglés | ⬜ |
| PP-108 | Vista previa del abono con monto inválido | Préstamo vigente, pantalla del préstamo | Escribir `7.520,50728` en «Monto» | El error en español aparece debajo del campo y se ocultan las opciones y la tabla | ⬜ |
| PP-109 | Monto igual o mayor al saldo | Préstamo de 100 millones | Escribir `752050728` (o `100.000.000`) | Aviso «…use «Cancelación total»» en la vista previa, sin opciones de elegir cuota o plazo; al enviar se rechaza y no se guarda nada | ⬜ |
| PP-110 | Un punto con tres dígitos son miles; con uno o dos, decimales | Campos de monto | Escribir `150.000` y `7.500`; luego `7.5` y `7.52` | `150.000` = 150000 y `7.500` = 7500 se aceptan; `7.5` y `7.52` se leen como decimales y se rechazan por ser menores al mínimo; nunca se pide aclarar | ⬜ |

**Pendiente (no se tocó en este paso):** los campos `type="number"` (salario del empleado, presupuestos, cuantías) no usan este formato: el navegador no acepta separadores de miles y su comportamiento depende de la configuración regional.
## 15. Vencimientos por calendario y cierre provisional de novedades (RF-007)

Automatizados en `tests/Feature/VencimientosCalendarioTest.php` (y `PlanPruebasSeederTest` para el seeder). Las cuotas vencen el día 15 y el último día real de cada mes; el cierre de novedades de nómina es un parámetro provisional (`QUINCENA_CIERRE_NOVEDADES_DIAS_ANTES`, por defecto 2) y solo informativo.

| ID | Caso de prueba | Precondición | Pasos | Resultado esperado | Resultado obtenido |
|---|---|---|---|---|---|
| PP-111 | Fechas de vencimiento por calendario | Solicitud aprobada | Aprobar y abrir la tabla de cuotas del préstamo | Todas las fechas son día 15 o último día del mes (28/29 en febrero según el año, 30 o 31 en los demás); se alternan sin saltos ni corrimientos | ⬜ |
| PP-112 | Primera cuota según la fecha base | Fecha base (autorización + 15 días) el día 14, 15, 16 y el último día del mes | Aprobar en cada fecha | La primera cuota vence el 15 o el último día, el primero que sea igual o posterior a la fecha base | ⬜ |
| PP-113 | Cambio de año | Préstamo que cruza diciembre y enero | Ver la tabla de cuotas | …, 15 dic, 31 dic, 15 ene, 31 ene… | ⬜ |
| PP-114 | Simulador igual a las cuotas reales | Simulación con una modalidad y plazo | Comparar las fechas de la tabla del simulador con las del préstamo aprobado el mismo día | Coinciden cuota por cuota | ⬜ |
| PP-115 | La reliquidación conserva la secuencia | Préstamo con cuotas pagadas | Registrar un abono (automático, bajar cuota y bajar plazo) | Las cuotas pendientes que quedan mantienen sus fechas; siguen siendo 15 y fin de mes y se eliminan las últimas | ⬜ |
| PP-116 | Cierre de novedades en la vista previa | Quincena iniciada | Abrir `Pagos › Quincenas › Revisar y confirmar` | Aparece «Cierre de novedades de nómina: <fecha>» con la etiqueta «provisional», 2 días antes del vencimiento de la quincena | ⬜ |
| PP-117 | El cierre sigue la configuración | Cambiar `QUINCENA_CIERRE_NOVEDADES_DIAS_ANTES` | Recargar la vista previa | La fecha del cierre cambia (también en febrero, cruces de mes y de año) | ⬜ |
| PP-118 | El cierre no bloquea nada | Cualquier valor del cierre (0, 2, 30…) | Aprobar, desembolsar, pagar una cuota y confirmar la quincena | Mismo resultado en cuotas, pagos, desembolso y proceso; ninguna operación se rechaza por el cierre | ⬜ |
| PP-119 | Datos de prueba por calendario | Base vacía | Correr `PlanPruebasSeeder` | El préstamo de estado de cuenta tiene sus 3 primeras cuotas en los 3 últimos vencimientos ya pasados y ninguna en mora; el de cuota vencida tiene las 2 últimas sin pagar | ⬜ |

## 16. Tope de la tasa de vivienda (RF-004, Acuerdo 11 de 2023)

Automatizados en `tests/Feature/TopeTasaViviendaTest.php`. El tope (5 %) y la fecha de vigencia (15 de noviembre de 2023) están en `config/prestamos.php`; el IPC de `tasas_int` no se modifica.

| ID | Caso de prueba | Precondición | Pasos | Resultado esperado | Resultado obtenido |
|---|---|---|---|---|---|
| PP-120 | IPC por encima del tope | Vivienda con IPC registrado de 5,20 % | Simular y radicar una solicitud | Tasa 5 %; el IPC sigue en 5,20 % en la pantalla de la modalidad | ⬜ |
| PP-121 | IPC por debajo o igual al tope | IPC de 4,80 % y de 5,00 % | Simular | Quedan en 4,80 % y 5,00 %; no aparece la nota | ⬜ |
| PP-122 | Fecha de la solicitud | IPC de 5,20 % para 2023 | Solicitud del 14 y del 15 de noviembre de 2023 | La del 14 conserva 5,20 %; la del 15 queda en 5 % | ⬜ |
| PP-123 | Las cinco modalidades de vivienda | IPC de 6,30 % | Simular compra, construcción, deshipoteca, reparaciones o mejoras y cambio de vivienda | Las cinco quedan en 5 % | ⬜ |
| PP-124 | Modalidades sin tope | IPC de 6,30 % en educación, calamidad; vehículo con IPC 6 % y puntos | Simular | Educación y calamidad en 6,30 %; vehículo en IPC más puntos, sin límite | ⬜ |
| PP-125 | Préstamo aprobado | Solicitud de vivienda con IPC de 5,20 % | Aprobar y abrir el préstamo | Guarda la tasa de 5 %; la cuota corresponde a 5 %; la reliquidación usa esa tasa | ⬜ |
| PP-126 | Tasa escrita a mano en el simulador | Vivienda | Escribir 5,2 % (o 4,5 % más 1 punto) | Rechazado con «En vivienda la tasa no puede superar el 5 % (Acuerdo 11 de 2023)…»; 5 % o menos se permite | ⬜ |
| PP-127 | Tasa escrita a mano fuera de vivienda | Educación o vehículo | Escribir 8,2 % | Se permite, sin tope | ⬜ |
| PP-128 | Nota visible | Tasa limitada | Ver el simulador, el detalle de la solicitud, el del préstamo y la hoja impresa | «Tasa limitada al 5 % según el Acuerdo 11 de 2023; IPC registrado: 5,20 %.» en los cuatro lugares | ⬜ |
| PP-129 | Cambiar el tope | `TASA_VIVIENDA_TOPE=0.045` | Simular | La tasa queda en 4,5 % y la nota lo dice | ⬜ |
| PP-130 | Elegir la modalidad muestra la tasa limitada | Vivienda — compra con IPC de 6,50 % | En el simulador, elegir el empleado y la modalidad, sin calcular | Los campos se llenan con 5 y 0 (nunca 6,5); aparece la nota «Tasa limitada al 5 % … IPC registrado: 6,50 %.» y la ruta de modalidad trae la tasa limitada y la nota | ⬜ |
| PP-131 | Calcular con lo prellenado | Lo anterior | Escribir monto y plazo, y calcular sin tocar la tasa | Calcula al 5 %, no se marca como editada y el resultado muestra la nota | ⬜ |
| PP-132 | Restablecer tasa oficial | Lo anterior, con la tasa escrita a mano en 4,2 | Pulsar «Restablecer tasa oficial» | Vuelve a 5 y 0 (la limitada) y la nota sigue visible | ⬜ |
| PP-133 | Corrección manual por encima del tope | IPC de 6,50 % | Escribir 6,5 (o 5 más 1,5 de puntos) y calcular | Rechazado con el mensaje en español; 4,2 se permite y se marca como editada | ⬜ |
| PP-134 | Educación con 6,5 % | Educación con IPC de 6,50 % | Elegir la modalidad y calcular | Se prellena 6,5, calcula al 6,5 % y no aparece la nota | ⬜ |

## 17. Bloqueo por salario mínimo (RF-005, Bienestar)

Automatizados en `tests/Feature/BloqueoSalarioMinimoTest.php`. La regla compara `empleados.salario` con el SMMLV del año de la solicitud; ver `docs/reglas-negocio.md`, sección 2.

| ID | Caso de prueba | Precondición | Pasos | Resultado esperado | Resultado obtenido |
|---|---|---|---|---|---|
| PP-135 | Salario menor al mínimo | SMMLV 2026 registrado; empleado con salario menor | Registrar una solicitud | Se rechaza con «No se puede registrar: el salario del empleado es igual o menor al salario mínimo (SMMLV)…»; no se crea la solicitud | ⬜ |
| PP-136 | Salario igual al mínimo (borde) | Salario exactamente igual al SMMLV del año | Registrar una solicitud | Se rechaza con el mismo mensaje | ⬜ |
| PP-137 | Salario apenas por encima | Salario = SMMLV + 1 peso (y + 1 centavo) | Registrar, aprobar y simular | Todo funciona; no aparece el bloqueo | ⬜ |
| PP-138 | SMMLV del año de la solicitud | SMMLV 2025 de 1.423.500 y 2026 de 1.750.905; salario de 1.600.000 | Registrar con fecha de 2025 y con fecha de 2026 | La de 2025 se registra; la de 2026 se rechaza | ⬜ |
| PP-139 | Solicitud preexistente | Solicitud ya radicada de un empleado con salario igual al mínimo | Intentar aprobarla | Se rechaza por el incumplimiento `salario_minimo`; no se crea el préstamo | ⬜ |
| PP-140 | Simulador: no apto | Empleado con salario igual o menor al mínimo | Elegir la modalidad y calcular | La referencia dice que no puede y calcular se rechaza con el mismo mensaje, sin resultado ni botón «Crear solicitud» | ⬜ |
| PP-141 | Empleado sin salario | Salario en cero | Registrar y simular | Se registra (esta regla no bloquea); el simulador conserva «El salario del empleado debe ser mayor que cero.» | ⬜ |
| PP-142 | Año sin SMMLV | Solicitud de un año sin SMMLV | Registrar, validar y simular | Se registra; la validación y el simulador conservan el aviso `sin_smmlv`; no aparece `salario_minimo` | ⬜ |

## 18. Registro y edición de tasas en la modalidad (RF-004)

Automatizados en `tests/Feature/TasaIntEdicionTest.php` y `tests/Feature/TasaIntTest.php`. Pantalla: Modalidades, abrir una modalidad, tarjeta «Tasas de interés por año».

| ID | Caso de prueba | Precondición | Pasos | Resultado esperado | Resultado obtenido |
|---|---|---|---|---|---|
| PP-143 | Año duplicado | La modalidad ya tiene tasa de 2026 | Registrar otra de 2026 | Alerta roja con «Ya hay una tasa registrada para ese año en esta modalidad…»; el año queda marcado en rojo y lo digitado se conserva; no se crea nada | ⬜ |
| PP-144 | Tasa fuera de rango o no numérica | Cualquier modalidad | Registrar 101, -1 o «abc» | Alerta con el mensaje en español, campo en rojo y valor conservado | ⬜ |
| PP-145 | Editar la tasa de un año | Tasa de 2026 en 5 % | Cambiar a 6,5 en su fila y pulsar «Guardar» | Mensaje «Tasa de 2026 actualizada correctamente.»; la fila muestra 6,5; el año, la modalidad y la fuente no cambian | ⬜ |
| PP-146 | Auditoría de la edición | Lo anterior | Abrir Auditoría | «Edición de tasa de interés» con la modalidad, el año, «tasa anterior 5,0000 % → nueva 6,5000 %» y el usuario | ⬜ |
| PP-147 | Coma decimal | — | Registrar y editar con «6,5» y «6,25» | Se guardan 6,5 y 6,25; «5.250» se lee como 5,25 (no como miles) | ⬜ |
| PP-148 | Error al editar | Tasa de 2026 | Escribir 150 en su fila y guardar | Alerta, esa fila en rojo con el 150 conservado; la tasa no cambia | ⬜ |
| PP-149 | Sin cambios | Tasa de 2026 en 5 % | Guardar 5,00 | Mensaje «ya tenía ese valor; no hubo cambios»; sin registro en Auditoría | ⬜ |
| PP-150 | Permisos | Cuenta con solo `modalidades.ver`, o sin permisos | Abrir la modalidad e intentar editar | Con «ver» no aparecen campos ni botones de edición; el PUT directo da 403; la ruta nueva está cubierta por la prueba de rutas con `can:` | ⬜ |
| PP-151 | Nota del tope en vivienda | Modalidad de vivienda y otra que no lo sea | Abrir cada una | Solo en vivienda aparece la nota del tope del 5 % y la fecha 15/11/2023 | ⬜ |
| PP-152 | El tope sigue aplicándose | Vivienda con IPC de 4,8 % | Editarlo a 6,5 y simular | La pantalla guarda y muestra 6,5 (IPC real); el simulador usa 5 % y muestra la nota del tope | ⬜ |

## 19. Primas de seguros y estimación en el simulador (RF-004, Art. 15)

Automatizados en `tests/Feature/PrimaSeguroTest.php` y `tests/Feature/SimuladorSegurosTest.php`; la ruta nueva entra en la prueba de cobertura de rutas con `can:`. Pantalla: Modalidades y presupuesto, «Seguros».

| ID | Caso de prueba | Precondición | Pasos | Resultado esperado | Resultado obtenido |
|---|---|---|---|---|---|
| PP-153 | Primas iniciales | Base recién migrada | Abrir «Seguros» | Vida de deudores 0,0003231 e incendio y terremoto 0,0000000 para el año en curso y el anterior | ⬜ |
| PP-154 | Registrar una prima | Administrador | Elegir seguro, año y prima (con coma, «0,00045») y registrar | Mensaje de éxito; la prima aparece; Auditoría: «Registro de prima de seguro … tasa anterior sin registro → nueva 0,0004500» | ⬜ |
| PP-155 | Duplicado concepto-año | Prima de vida de deudores del año X | Registrar otra del mismo seguro y año | Alerta «Ya hay una prima registrada para ese concepto y año…», campo en rojo y lo digitado conservado; el mismo año con otro seguro sí se permite | ⬜ |
| PP-156 | Tasa inválida | — | Registrar «abc», -0,0001, 1,5 o más de 8 decimales | Alerta en español con el motivo; no se crea nada | ⬜ |
| PP-157 | Editar una prima | Prima de 0,0003231 | Cambiarla a 0,0004 en su fila y pulsar «Guardar» | Mensaje de éxito; Auditoría: «Edición de prima de seguro … tasa anterior 0,0003231 → nueva 0,0004000»; el seguro y el año no cambian; guardar el mismo valor no audita | ⬜ |
| PP-158 | Sin borrado | Administrador | Intentar borrar una prima | No hay botón ni ruta de borrado (405) | ⬜ |
| PP-159 | Permisos | Funcionario, cuenta con solo `empleados.ver`, y Administrador | Abrir «Seguros»; intentar registrar y editar | El Administrador ve y gestiona; el Funcionario ve la tabla sin campos de edición y recibe 403 al registrar o editar; sin `seguros.ver` recibe 403 y el menú no muestra «Seguros» | ⬜ |
| PP-160 | Cuentas existentes | Cuentas anteriores a la migración | Correr las migraciones | Administradores con `seguros.ver` y `seguros.gestionar`; Funcionarios solo con `seguros.ver` | ⬜ |
| PP-161 | Prima del año en el simulador | Prima de vida de deudores del año = 0,001 | Simular 50.000.000 en educación | Seguro estimado de 50.000 aparte de la cuota; el texto «Estimación; el valor real lo calcula el área de seguros» | ⬜ |
| PP-162 | Falta la prima del año | Solo hay primas de años anteriores | Simular | Usa la del año anterior más reciente y avisa «No hay prima de {año}: se usó la de {año anterior}.» | ⬜ |
| PP-163 | Sin ninguna prima | Tabla de primas vacía | Simular | Usa el respaldo (0,0003231) y muestra un aviso discreto de respaldo | ⬜ |
| PP-164 | Incendio y terremoto | Vivienda con prima de incendio en 0 y luego mayor que 0 | Simular | En 0 no suma y el desglose dice «sin prima definida»; mayor que 0 suma monto × prima y el desglose muestra vida e incendio; fuera de vivienda no se suma | ⬜ |
| PP-165 | Rótulo en vivienda | Modalidad de vivienda y otra | Simular en cada una | Vivienda: «Cuota estimada con seguros»; las demás: «Cuota + seguro»; la hoja impresa igual | ⬜ |
| PP-166 | La cuota no cambia | Cambiar las primas | Simular y aprobar un préstamo | La cuota, la tabla de amortización y el total a pagar son los mismos; el préstamo guarda la cuota sin seguro y las cuotas no llevan seguro | ⬜ |

## 20. Capacidad de endeudamiento unificada (RF-005, Bienestar)

Automatizados en `tests/Feature/CapacidadUnificadaTest.php`; los de la aprobación y el simulador que suponían la capacidad guardada ahora fijan `capacidad.fuente = guardada`. Pantallas: Empleados (ficha), Simulador y Solicitudes (aprobar).

| ID | Caso de prueba | Precondición | Pasos | Resultado esperado | Resultado obtenido |
|---|---|---|---|---|---|
| PP-167 | Mismo cálculo en simulador y aprobación | Empleados con salario de 3, 6, 22 y 30 millones (tramos 0 %, 1 %, 1,2 % y 2 % del fondo) y un préstamo vigente | Simular varios montos y validar solicitudes por los mismos montos | Misma capacidad disponible y mismo veredicto en ambos; la capacidad es 50 % de (salario − 4 % − 4 % − fondo) menos lo comprometido | ⬜ |
| PP-168 | Retención manual | Empleado de 6 millones | Simular con retención 0 y con 400.000 | Con retención 0 coincide con la aprobación; con retención la capacidad baja en la mitad de la retención | ⬜ |
| PP-169 | Fuente calculada manda | Salario 8.000.000, capacidad guardada 1.000.000, fuente `calculada` | Aprobar una solicitud cuya cuota mensual supera 1.000.000 pero cabe en la calculada | La solicitud no tiene `capacidad_excedida`; el simulador dice que la cuota cabe | ⬜ |
| PP-170 | Fuente guardada manda | Mismo empleado, `CAPACIDAD_FUENTE=guardada` | Validar la misma solicitud y simular | `capacidad_excedida`; el simulador avisa que los veredictos son distintos | ⬜ |
| PP-171 | Guardada nula | Fuente `guardada`, capacidad guardada vacía | Validar una solicitud | Cuenta como 0: `capacidad_excedida` (comportamiento actual) | ⬜ |
| PP-172 | Salario 0 | Fuente `calculada`, salario 0 | Validar y aprobar una solicitud; abrir el simulador | `capacidad_no_calculable` con «No se puede calcular la capacidad de endeudamiento…»; la aprobación se bloquea; el simulador no evalúa la modalidad. Con fuente `guardada` no aparece | ⬜ |
| PP-173 | Vivienda con seguros, justo en el límite | Empleado de 8.000.000, prima de incendio 0,0002 | Pedir el monto máximo que ofrece el simulador y 2.000 más | El máximo se aprueba; 2.000 más da `capacidad_excedida` | ⬜ |
| PP-174 | Mismo monto en otra modalidad | El monto que no cabe en vivienda | Pedirlo en educación | No hay `capacidad_excedida`: fuera de vivienda solo se compara la cuota | ⬜ |
| PP-175 | Incendio en 0 | Prima de incendio 0 y luego mayor que 0 | Comparar la cuota mensual en vivienda | En 0 solo suma vida; mayor que 0 suma también incendio | ⬜ |
| PP-176 | Mensaje con desglose | Solicitud de vivienda que no cabe | Validar | El mensaje trae la cuota, los seguros estimados y la capacidad total, comprometida y disponible; fuera de vivienda no menciona seguros | ⬜ |
| PP-177 | Ficha del empleado | Capacidad guardada distinta de la calculada | Abrir la ficha | «Capacidad calculada (referencia: la guardada es X)»; si coinciden, solo «Capacidad calculada»; sin salario, «No se puede calcular»; la columna guardada no cambia | ⬜ |

## 21. Primera cuota desde el desembolso (RF-006, Bienestar)

Automatizados en `tests/Feature/PrimeraCuotaDesembolsoTest.php`. Pantallas: Préstamos (ver préstamo, registrar desembolso), Simulador y Quincena.

| ID | Caso de prueba | Precondición | Pasos | Resultado esperado | Resultado obtenido |
|---|---|---|---|---|---|
| PP-178 | Desembolso a mitad de quincena | Préstamo aprobado sin desembolsar | Desembolsar el 3 de un mes y luego otro el 20 | El 3 → primera cuota el 15; el 20 → último día del mes | ⬜ |
| PP-179 | Desembolso justo en un vencimiento | Ídem | Desembolsar el 15 exacto y el último día exacto | El 15 → último día del mes; el último día → 15 del mes siguiente | ⬜ |
| PP-180 | Febrero y meses de 30/31 días | Ídem | Desembolsar el 15 y el 28 de febrero (y en bisiesto el 15 y el 29); el 15 y el 30 de abril; el 16 de mayo | Febrero cierra el 28 (29 en bisiesto); abril el 30; mayo el 31; el último día de febrero → 15 de marzo | ⬜ |
| PP-181 | Fin de año | Ídem | Desembolsar el 15, el 20 y el 31 de diciembre | 31 de diciembre las dos primeras; el 31 → 15 de enero del año siguiente | ⬜ |
| PP-182 | Cuotas siguientes | Préstamo de 24 cuotas desembolsado el 3 de marzo | Ver la tabla del préstamo | 15-mar, 31-mar, 15-abr, 30-abr… sin saltos hasta el 28 de febrero del año siguiente; los valores no cambian | ⬜ |
| PP-183 | Sin desembolsar no se cobra | Préstamo aprobado sin desembolso | Abrir el proceso/informe de la quincena de su fecha provisional y la cartera vencida | No aparece en la vista previa, en pendientes, en vencidas anteriores, en el informe de créditos ni en la cartera vencida; al desembolsarlo sí | ⬜ |
| PP-184 | Capacidad | Préstamo aprobado sin desembolsar | Consultar la capacidad del empleado | Su cuota sigue restando de la capacidad disponible | ⬜ |
| PP-185 | Préstamos anteriores | Préstamo aprobado con la regla vieja | Registrarle un desembolso | Sus cuotas y fechas no cambian; la auditoría no menciona primera cuota | ⬜ |
| PP-186 | Segundo tramo | Vivienda — construcción con el primer tramo desembolsado | Desembolsar el segundo tramo | La primera cuota no se mueve; la auditoría del segundo tramo no trae fecha | ⬜ |
| PP-187 | Auditoría | Préstamo sin desembolsar | Desembolsar | «Registro de desembolso» con «primera cuota: AAAA-MM-DD» | ⬜ |
| PP-188 | Pantalla del préstamo | Préstamo antes y después del desembolso | Abrir el préstamo | Antes: «Las cuotas empiezan a descontarse a partir del desembolso.»; después: «Primera cuota: <fecha>»; un préstamo anterior muestra la fecha de su cuota 1 | ⬜ |
| PP-189 | Simulador | Cualquier empleado y modalidad | Simular | La tabla conserva sus fechas estimadas y trae «La fecha real de la primera cuota se define al desembolsar» (también en la hoja impresa) | ⬜ |
| PP-190 | Interruptor | `QUINCENA_INICIO_PRIMERA_CUOTA=desde_aprobacion` | Aprobar y desembolsar | Se conserva la regla anterior: el desembolso no mueve las cuotas | ⬜ |
| PP-191 | Simulador sin fecha de desembolso | Cualquier empleado y modalidad | Simular dejando «Fecha estimada de desembolso» vacía | Igual que antes: fechas estimadas desde hoy y la nota «La fecha real de la primera cuota se define al desembolsar»; sin línea de primera cuota estimada | ⬜ |
| PP-192 | Simulador con fecha de desembolso | Ídem | Escribir el 3, el 15 exacto, el 20 y el último día exacto de un mes | Primera cuota estimada: el 15; el último día; el último día; el 15 del mes siguiente. Con la nota «Estimación según la fecha de desembolso indicada…» | ⬜ |
| PP-193 | Febrero, fin de año y fechas pasadas | Ídem | Escribir 15 y 28 de febrero (y bisiesto), 20 y 31 de diciembre, y una fecha pasada | Febrero 28/29; diciembre → 31 de diciembre o 15 de enero; la fecha pasada se acepta; las demás cuotas siguen el calendario | ⬜ |
| PP-194 | Fecha inválida | Ídem | Enviar una fecha inexistente (30 de febrero) o con otro formato | Mensaje en español «La fecha estimada de desembolso no es una fecha válida…»; el campo conserva lo digitado | ⬜ |
| PP-195 | Los valores no cambian | Ídem | Simular el mismo crédito con y sin fecha | Cuota, intereses, capital, saldo, seguro, totales y capacidad idénticos; solo cambian las fechas de la tabla | ⬜ |
| PP-196 | Hoja impresa | Ídem | Imprimir con y sin fecha | La línea «Primera cuota estimada: <fecha>» y su nota salen solo si se diligenció la fecha | ⬜ |

## 22. Aviso de cuota excluida del descuento por nómina (RF-006, Bienestar)

Automatizados en `tests/Feature/AvisoCuotaExcluidaTest.php`. Pantallas: Préstamos (ver préstamo) y Quincenas (vista previa y detalle de la quincena confirmada).

| ID | Caso de prueba | Precondición | Pasos | Resultado esperado | Resultado obtenido |
|---|---|---|---|---|---|
| PP-197 | Aviso en el préstamo | Quincena confirmada con una cuota excluida (con motivo) y otra pagada | Abrir el préstamo | El aviso aparece una sola vez, junto al estado de la cuota excluida, con su motivo; la cuota pagada no lo lleva | ⬜ |
| PP-198 | Sin excluidas | Préstamo sin cuotas excluidas | Abrir el préstamo | No aparece ningún aviso | ⬜ |
| PP-199 | Cuota excluida que se paga | Cuota excluida que luego se paga | Abrir el préstamo | El aviso desaparece | ⬜ |
| PP-200 | Valor por defecto | Sin variables en `.env` | Abrir el préstamo | «Esta cuota no se descontó por nómina. Debe pagarse por ventanilla: consigne en la cuenta del Fondo de Bienestar Social el valor de la cuota, con la factura que genera Costeo y Facturación (puede solicitarla allí o a Bienestar Social), y entregue el soporte de pago a Bienestar Social para legalizarlo en Tesorería.» | ⬜ |
| PP-201 | Texto y lugar configurables | Cambiar `QUINCENA_AVISO_CUOTA_EXCLUIDA` o `QUINCENA_AVISO_CUOTA_EXCLUIDA_LUGAR` y limpiar la configuración | Abrir el préstamo | El aviso usa el texto y el lugar nuevos; no queda el valor anterior | ⬜ |
| PP-202 | Vista previa | Quincena con cuotas por confirmar | Abrir la vista previa; marcar y desmarcar «Excluir» | El aviso sale solo en la fila marcada y se quita al desmarcar; las demás filas no lo llevan | ⬜ |
| PP-203 | Detalle de la quincena confirmada | Proceso con cuotas excluidas / sin ellas | Abrir «Ver» en Quincenas confirmadas | Con excluidas, el aviso sobre la tabla «Cuotas excluidas»; sin ellas, ninguno | ⬜ |
| PP-204 | Nada financiero cambia | Préstamo con cuota excluida | Abrir préstamo, detalle y lista de quincenas | Estados, valores, pagos, mora, saldo y resumen del proceso idénticos | ⬜ |

## 23. Tipos de vinculación y docentes ocasionales (Bienestar)

Automatizados en `tests/Feature/TipoVinculacionTest.php`. Pantallas: Empleados (lista, formulario y ficha), Solicitudes, Simulador y correo al comité.

| ID | Caso de prueba | Precondición | Pasos | Resultado esperado | Resultado obtenido |
|---|---|---|---|---|---|
| PP-205 | Enum y etiquetas | — | Consultar los tipos | vinculado, docente, docente_ocasional, jubilado, mostrados como Vinculado, Docente, Docente ocasional, Jubilado | ⬜ |
| PP-206 | Migración convierte los datos | Empleados con «Empleado», «Docente» y un valor desconocido | Ejecutar la migración | Empleado → vinculado, Docente → docente, desconocido → vinculado (reportado en el log); no se pierde ninguna fila ni otro dato; de_carrera queda en falso | ⬜ |
| PP-207 | Migración reversible | Empleados ya convertidos | Revertir la migración | Vuelven «Empleado» y «Docente» y desaparece de_carrera | ⬜ |
| PP-208 | Formulario | — | Abrir Nuevo empleado | Tipo de vinculación con los cuatro nombres y la casilla «Empleado de carrera» | ⬜ |
| PP-209 | Crear y editar | — | Crear un empleado de cada tipo, con y sin «Empleado de carrera»; editarlo | Se guardan el tipo y de_carrera; sin marcar queda en falso | ⬜ |
| PP-210 | Valor inválido | — | Enviar «Empleado», «Docente» (texto antiguo), uno inventado o vacío | Se rechaza con error en tipo_vinculacion | ⬜ |
| PP-211 | Nombre en lista y ficha | Empleado Docente ocasional de carrera | Abrir lista, ficha y edición | Se ve «Docente ocasional» y «Empleado de carrera: Sí»; nunca `docente_ocasional` en la tabla o ficha; el formulario lo trae seleccionado | ⬜ |
| PP-212 | Filtro por tipo | Un empleado de cada tipo | Filtrar la lista por cada tipo y con un valor inválido | Solo salen los del tipo elegido; el valor inválido se ignora | ⬜ |
| PP-213 | Registro bloqueado | Docente ocasional | Registrar una solicitud | «No se puede registrar: los docentes ocasionales no son beneficiarios de crédito del Fondo.»; no se crea | ⬜ |
| PP-214 | Solicitud preexistente | Solicitud de un docente ocasional ya guardada | Validar la solicitud | Incumplimiento `tipo_vinculacion` con el mensaje; vinculado, docente y jubilado no lo tienen | ⬜ |
| PP-215 | Simulador no apto | Docente ocasional | Evaluar la modalidad y simular | «no apto» con «Los docentes ocasionales no son beneficiarios de crédito del Fondo.»; no se arma resultado ni hoja | ⬜ |
| PP-216 | Los demás siguen igual | Vinculado, docente y jubilado | Registrar solicitud y simular | Funciona igual que antes | ⬜ |
| PP-217 | Hoja impresa y correo | Jubilado en el simulador; docente en una solicitud | Imprimir la hoja; ver el correo al comité | Se ve «Jubilado» / «Docente» como nombre | ⬜ |
| PP-218 | de_carrera no cambia reglas | Dos empleados iguales, uno de carrera | Validar, evaluar y simular | Resultados idénticos | ⬜ |

## 24. DTF para la liquidación por retiro (Bienestar, pregunta 39)

Automatizados en `tests/Feature/DtfTest.php`. Pantalla: Modalidades y presupuesto → DTF.

| ID | Caso de prueba | Precondición | Pasos | Resultado esperado | Resultado obtenido |
|---|---|---|---|---|---|
| PP-219 | Cálculo 9,81 → 10,44 | — | Registrar nominal 9,81 | Efectivo anual 10,44 (se calcula, no se digita); otros valores de control (0 → 0, 4 → 4,10, 8 → 8,42, 12 → 12,96) | ⬜ |
| PP-220 | Listado | Dos DTF | Abrir la pantalla | Orden por vigencia descendente, nominal y efectivo con 2 decimales y coma, fuente | ⬜ |
| PP-221 | Registrar | Permiso de gestionar | Registrar fecha, nominal (con coma o punto) y fuente opcional | Se guarda con el efectivo calculado; Auditoría con el valor anterior (ninguno) y el nuevo | ⬜ |
| PP-222 | Nominal inválido | — | Enviar −1, 100, mayor que 100, texto, vacío o más de 4 decimales | Se rechaza con mensaje en español, campo con is-invalid y valor conservado; nada se guarda. El 0 se acepta | ⬜ |
| PP-223 | Fecha | Un DTF ya registrado | Repetir la fecha, enviar una inexistente (30 de febrero), otro formato o vacía | Se rechaza con el mensaje correspondiente | ⬜ |
| PP-224 | Editar | Un DTF | Cambiar fecha, nominal y fuente | Se recalcula el efectivo; Auditoría con anterior y nuevo; sin cambios no escribe ni audita; no se puede tomar la fecha de otro | ⬜ |
| PP-225 | Sin borrado | Un DTF | Intentar borrar | No existe la ruta (405) | ⬜ |
| PP-226 | Permisos | Cuentas sin permiso, solo ver y gestor | Abrir, registrar y editar | Sin permiso 403; solo ver consulta sin formularios ni botones; gestor puede todo; invitado va al login; el menú muestra «DTF» solo con modalidades.ver | ⬜ |
| PP-227 | vigenteEn | DTF en 2025-01-01, 2025-07-01 y 2026-01-01 | Consultar antes del primero, en cada fecha, entre registros y después del último | Antes: null; el más reciente con vigencia ≤ la fecha; sin registros: null | ⬜ |
| PP-228 | delCierreDeAnio | DTF de 2025-06-01, 2025-12-31 y 2026-01-01 | Consultar 2025, 2026 y 2027 | El vigente al 31 de diciembre anterior (2025 → null; 2026 → el del 31-dic; 2027 → el del 1-ene-2026) | ⬜ |
| PP-229 | Criterio configurable | `DTF_CRITERIO` | Consultar dtfParaRetiro con cada criterio | `vigente_a_fecha` (por defecto) usa el vigente a la fecha; `cierre_anio_anterior` el del cierre del año anterior; otro valor da error | ⬜ |
| PP-230 | Nada más cambia | Un DTF registrado | Registrar un retiro | El retiro se comporta igual que antes: no consume el DTF | ⬜ |

## 25. Informe de quincena por cuota (Q de Informática)

Automatizados en `tests/Feature/InformeQuincenaTest.php`. Pantallas: Quincenas (vista previa y detalle) e Historial de pagos.

| ID | Caso de prueba | Precondición | Pasos | Resultado esperado | Resultado obtenido |
|---|---|---|---|---|---|
| PP-231 | Descarga de la vista previa | Quincena con cuotas | Pulsar «Descargar Excel (CSV)» | Archivo `informe-quincena-AAAA-Qn-FECHA-vista-previa.csv` (UTF-8 con BOM, `;`) que abre en Excel; encabezado con «Informe de quincena», vencimiento, generación y usuario | ⬜ |
| PP-232 | Columnas | Ídem | Abrir el archivo | Cédula, Nombre, Tipo de vinculación, Modalidad, N.º de préstamo, Monto inicial, Plazo total, Cuota n.º, Quincenas pagadas, Saldo inicial, Valor de la cuota, Capital, Intereses, Nuevo saldo, Estado, Motivo de exclusión | ⬜ |
| PP-233 | Saldos | Préstamo de 600.000 en 3 cuotas (capital 100.000, 200.000, 300.000), la 1 pagada, la 2 en la quincena | Ver la fila de la cuota 2 | Saldo inicial 500.000, nuevo saldo 300.000, 1 quincena pagada | ⬜ |
| PP-234 | Valores numéricos | Ídem | Abrir en Excel | Montos y conteos se reconocen como números (coma decimal), las fechas como AAAA-MM-DD | ⬜ |
| PP-235 | Totales y resumen | Varias modalidades y una cuota excluida en la vista previa | Marcar la exclusión con motivo y descargar | La excluida sale como Excluida con su motivo y no suma en «A descontar»; los subtotales por modalidad y el total coinciden con la suma de las cuotas | ⬜ |
| PP-236 | Préstamo sin desembolsar | Préstamo pendiente de desembolso con cuota en la quincena | Descargar | No aparece | ⬜ |
| PP-237 | Quincena sin cuotas | Quincena sin cuotas | Descargar | Archivo válido con el aviso «La quincena no tiene cuotas.» | ⬜ |
| PP-238 | Quincena confirmada | Quincena confirmada con una cuota excluida con motivo | Abrir «Ver» y descargar | Descontadas y Excluida con su motivo, igual a lo guardado | ⬜ |
| PP-239 | Confirmada antigua sin motivo | Proceso confirmado sin motivo guardado | Descargar | No falla; el motivo queda vacío | ⬜ |
| PP-240 | Permisos | Cuentas sin permiso, solo `quincenas.ver` y con `quincenas.confirmar` | Descargar de la vista previa y de la confirmada | Sin permiso 403; solo ver descarga la confirmada pero no la vista previa; invitado va al login | ⬜ |
| PP-241 | Auditoría | Cualquier descarga | Consultar Auditoría | «Descarga de informe de quincena» con la quincena, el tipo y el conteo | ⬜ |
| PP-242 | Enlace desde el historial | Historial de pagos con una quincena elegida | Abrir «Reportes y descargas» y pulsar «Descargar informe de la quincena (CSV)» | Descarga el informe confirmado si existe y, si no, el de la vista previa | ⬜ |
| PP-243 | Nada cambia | Cualquier descarga | Revisar cuotas, pagos y procesos | Sin cambios; texto con `=` en un motivo sale neutralizado | ⬜ |
| PP-244 | Menú «Reportes y descargas» | Historial de pagos | Mirar los controles junto a los filtros y abrir el menú con el mouse y con el teclado (Tab, Enter, flechas, Esc); probarlo en una pantalla angosta | «Buscar» queda a la vista y el menú a su derecha, con «Resumen de préstamos por modalidad y estado», «Imprimir o guardar la Q en PDF» (nueva pestaña, conserva año, quincena y cédula) y «Descargar informe de la quincena (CSV)»; sin permisos de quincenas no aparece el informe; el menú no se corta ni desborda | ⬜ |

## 26. Pagos y abonos solo después del desembolso (Bienestar)

Automatizados en `tests/Feature/PagosSinDesembolsoTest.php`. Pantalla: Préstamos (ver préstamo).

| ID | Caso de prueba | Precondición | Pasos | Resultado esperado | Resultado obtenido |
|---|---|---|---|---|---|
| PP-245 | Bloqueo en el servicio | Préstamo aprobado, pendiente de desembolso | Registrar pago de cuota, abono, cancelación total, reliquidación o abono de cesantías (por la pantalla o directo en el servicio) | Se rechaza con «No se puede registrar: el préstamo aún no se ha desembolsado. Los pagos y abonos se registran después del desembolso.»; no se crea ningún pago ni reliquidación y las cuotas siguen pendientes | ⬜ |
| PP-246 | Botones deshabilitados | Mismo préstamo | Abrir el préstamo | «Pagar», «Confirmar abono», «Cancelar préstamo» y «Registrar abono de cesantías» (vivienda) salen deshabilitados, con el motivo «Disponible después del desembolso» | ⬜ |
| PP-247 | Después del desembolso | Mismo préstamo | Registrar el desembolso y repetir los movimientos | Los botones se habilitan y los movimientos se registran con normalidad | ⬜ |
| PP-248 | Préstamos anteriores | Préstamo con `pendiente_desembolso = false` | Pagar, abonar y cancelar | Sin cambios: se registran como siempre | ⬜ |
| PP-249 | Aviso de cuota excluida | Sin variables en `.env` / con ellas | Abrir un préstamo con cuota excluida | Texto «…Debe pagarse por ventanilla: consigne en la cuenta del Fondo de Bienestar Social…»; configurable por `.env` | ⬜ |

## 27. Tasa de vehículo por tramo de plazo (Bienestar, Acuerdo 19, art. 38)

Automatizados en `tests/Feature/TasaVehiculoPorPlazoTest.php`, `SimuladorCreditoServiceTest.php` y `SimuladorPantallaTest.php`. Pantallas: Simulador, Solicitudes y Préstamos.

| ID | Caso de prueba | Precondición | Pasos | Resultado esperado | Resultado obtenido |
|---|---|---|---|---|---|
| PP-250 | Plazos exactos de cada tramo | Matriz de puntos cargada | Simular vehículo a 72, 96, 120 y 168 quincenas (primera y segunda solicitud) | Primera: IPC, +1, +2, +3; segunda: +2, +3, +4, +5 | ⬜ |
| PP-251 | Plazos intermedios | Igual | 84, 108, 132, 144 y 150 quincenas | Tramo anterior: 84 → 3 años; 108 → 4; 132, 144 y 150 → 5 | ⬜ |
| PP-252 | Plazo menor al primer tramo | Igual | 24 y 48 quincenas | Usa el tramo de 3 años | ⬜ |
| PP-253 | Plazo mayor al máximo | Igual | 169 quincenas en el simulador y al aprobar una solicitud | Se rechaza: «El plazo máximo del crédito de vehículo es de 7 años (168 quincenas).» | ⬜ |
| PP-254 | Nota del tramo | Simulación de vehículo a 144 quincenas | Mirar el resumen y la hoja impresa | «Tramo de 5 años: IPC + 2 puntos (plazo solicitado: 6 años)» junto a la tasa | ⬜ |
| PP-255 | Solicitud y préstamo | Solicitud de vehículo a 144 quincenas | Aprobar y abrir el préstamo | La tasa del préstamo es la del tramo de 5 años, igual que en el simulador | ⬜ |
| PP-256 | Otras modalidades | Educación, calamidad, vivienda | Simular y solicitar | Sin cambios en tasas ni notas | ⬜ |

## 28. Primera cuota según el cierre de novedades (Bienestar)

Automatizados en `tests/Feature/PrimeraCuotaConCierreTest.php` y `SimuladorFechaDesembolsoTest.php`. Pantallas: Préstamos (registrar desembolso, ver préstamo) y Simulador.

| ID | Caso de prueba | Precondición | Pasos | Resultado esperado | Resultado obtenido |
|---|---|---|---|---|---|
| PP-257 | Antes, el día y después del cierre | Cierre a 2 días; préstamo pendiente de desembolso | Desembolsar el 28, el 29 y el 30 de octubre (en préstamos distintos) | 31/10, 15/11 y 15/11 (el día del cierre no entra por defecto) | ⬜ |
| PP-258 | El día del cierre entra | `QUINCENA_DESEMBOLSO_DIA_CIERRE_ENTRA=true` | Desembolsar el 29 de octubre | Primera cuota el 31/10 | ⬜ |
| PP-259 | Cierres de 1 y 3 días y vencimiento 15 | Cambiar `QUINCENA_CIERRE_NOVEDADES_DIAS_ANTES` a 1 y a 3 | Desembolsar alrededor del cierre del 15 (13, 14 y 12 de noviembre) | La primera cuota pasa a la quincena siguiente al llegar el cierre | ⬜ |
| PP-260 | Fin de mes y fin de año | Febrero de 28 y 29 días, meses de 30 y 31, diciembre | Desembolsar justo antes y el día del cierre | Regla correcta en cada fin de mes y 15/01 para el 29 y 31 de diciembre | ⬜ |
| PP-261 | Propuesta en pantalla | Préstamo pendiente | Abrir el préstamo y cambiar la fecha del desembolso | «Primera cuota propuesta: 15/11/2026. Cierre de novedades de esa quincena: 13/11/2026.» se actualiza con la fecha | ⬜ |
| PP-262 | Elegir otra quincena | Desembolso del 30 de octubre | Elegir la anterior (31/10) o la siguiente (30/11); primero sin motivo, luego con motivo | Sin motivo se rechaza y no se guarda; con motivo se registra y el préstamo muestra «Quincena elegida por el funcionario: <motivo>» | ⬜ |
| PP-263 | Anterior no permitida | Desembolso del 31 de octubre | Mirar las opciones | No hay quincena anterior (su vencimiento no es posterior al desembolso) | ⬜ |
| PP-264 | Auditoría | Desembolso con quincena elegida | Consultar Auditoría | «Registro de desembolso» trae la primera cuota, la propuesta, el cierre, el criterio y el motivo | ⬜ |
| PP-265 | Simulador | Fecha estimada de desembolso 30/10 | Simular | Primera cuota estimada 15/11, igual que el desembolso real | ⬜ |
| PP-266 | Sin cambios | Préstamos ya desembolsados; segundo tramo de construcción | Abrir el préstamo y desembolsar el segundo tramo | Sus fechas no cambian; el segundo tramo no mueve la primera cuota | ⬜ |

## 29. Administrador principal, Acción en la auditoría y datos del empleado (jefatura)

Automatizados en `tests/Feature/AdministradorPrincipalTest.php` (y los ajustes a `PermisosCatalogoTest`, `PermisosPorCuentaTest`, `UsuarioSalvaguardasTest`). Pantallas: Usuarios, Auditoría y Empleados (nuevo y editar).

| ID | Caso de prueba | Precondición | Pasos | Resultado esperado | Resultado obtenido |
|---|---|---|---|---|---|
| PP-267 | Permisos del principal | Entrar como administrador principal | Editar su propia cuenta | «Gestionar usuarios» y «Consultar la auditoría» salen marcados y bloqueados; el rol no se puede cambiar | ⬜ |
| PP-268 | Principal en el servidor | Cuenta principal | Enviar la edición sin esas casillas (petición manipulada) | Siguen asignadas y el principal conserva el acceso | ⬜ |
| PP-269 | Administrador que no es el principal | Otro administrador | Abrir su edición como principal; entrar con esa cuenta | Casillas deshabilitadas y vacías con la nota «Solo el administrador principal gestiona usuarios y auditoría.»; Usuarios y Auditoría dan 403 y no están en el menú | ⬜ |
| PP-270 | Ignorar lo que llegue | Petición con `usuarios.gestionar` para un administrador no principal | Enviar | Se ignora sin error | ⬜ |
| PP-271 | Principal protegido | Cuenta principal | Intentar deshabilitarla o pasarla a Funcionario | Botón deshabilitado / rechazo con mensaje; siempre queda un principal activo | ⬜ |
| PP-272 | Migración | Varias cuentas | Correr la migración | Una sola cuenta principal: el administrador activo más antiguo que no es Sistema | ⬜ |
| PP-273 | «Acción» | — | Abrir Auditoría | La columna y el filtro dicen «Acción»; no aparece «Función» | ⬜ |
| PP-274 | Cédula del empleado | Formulario de empleado nuevo | Escribir o pegar letras y símbolos; saltarse el navegador | El campo borra lo no numérico; el servidor rechaza con mensaje en español | ⬜ |
| PP-275 | Nombre del empleado | Formulario nuevo y edición | Escribir o pegar números y símbolos; probar «María José», «Begoña Muñoz», «O'Hara», «Ana-María», «De la Cruz Jr.» | Los números se borran / el servidor los rechaza; los nombres válidos se aceptan; con otros errores se conserva lo digitado | ⬜ |

## 30. Lista de préstamos, hoja impresa y quincena en curso (jefatura)

Automatizados en `tests/Feature/AjustesJefe2Test.php`. Pantallas: Préstamos (lista y detalle), Quincenas y menú lateral.

| ID | Caso de prueba | Precondición | Pasos | Resultado esperado | Resultado obtenido |
|---|---|---|---|---|---|
| PP-276 | Búsqueda por nombre | Varios préstamos | Escribir parte del nombre con otras mayúsculas o sin tildes («jose angel», «PÉREZ», «munoz») | La lista se filtra mientras se escribe, sin recargar | ⬜ |
| PP-277 | Búsqueda por cédula | Varios préstamos | Escribir una cédula parcial y luego completa | Solo los empleados cuya cédula empieza por lo digitado | ⬜ |
| PP-278 | Vacía y sin resultados | — | Borrar el texto; escribir algo inexistente | Lista normal; «No hay préstamos que coincidan con la búsqueda» | ⬜ |
| PP-279 | Parámetro en la URL | — | Abrir `/prestamos?q=ana` | La búsqueda queda aplicada y el campo la muestra | ⬜ |
| PP-280 | Menú y ayuda | — | Mirar el menú Pagos y la lista | «Pagos y abonos» (misma ruta) y la línea de ayuda arriba de la lista | ⬜ |
| PP-281 | Imprimir el préstamo | Préstamo con cuotas, pagos y desembolso | Pulsar «Imprimir» y mirar la vista previa | Hoja A4 con logo, «Información del préstamo», empleado, préstamo, cuotas, pagos, desembolsos y aviso si aplica; sin botones; pie con fecha, hora y usuario; encabezado de tabla repetido | ⬜ |
| PP-282 | Permiso de imprimir | Cuenta sin `prestamos.ver` / con ella | Abrir el préstamo | Sin permiso 403; con permiso, botón y hoja | ⬜ |
| PP-283 | Quincena en curso | Hoy dentro de una quincena (y tras su cierre) | Abrir Quincenas | El selector trae la en curso seleccionada (la siguiente si hoy ya pasó el cierre) | ⬜ |
| PP-284 | Etiquetas | Quincenas pendientes y confirmadas | Mirar las listas y el selector | «En curso», «Pendiente anterior» y «Confirmada»; las demás siguen accesibles | ⬜ |

## 31. Resumen de pagos: todas las modalidades y detalle (jefatura)

Automatizados en `tests/Feature/ResumenPagosDetalleTest.php`. Pantallas: Reportes → Resumen de pagos y su detalle por modalidad.

| ID | Caso de prueba | Precondición | Pasos | Resultado esperado | Resultado obtenido |
|---|---|---|---|---|---|
| PP-285 | Todas las modalidades con pagos | Pagos en varias modalidades | Abrir el resumen sin fechas | Aparece cada modalidad con pagos (cuotas, abonos y cancelaciones), no las que no tienen; dice «Periodo: todos los pagos registrados» | ⬜ |
| PP-286 | Filtro de fechas | Pagos en distintos meses | Pagado desde / hasta | Solo las modalidades con pagos en el rango; el periodo se muestra | ⬜ |
| PP-287 | Ver detalle | Modalidad con varios préstamos | Pulsar «Ver detalle» | Línea con modalidad y periodo; columnas Empleado, Cédula, N.º de préstamo, Estado, Monto solicitado, N.º de cuotas, Valor de la cuota, Cuotas pagadas y Total pagado; orden por nombre; totales que coinciden con el resumen | ⬜ |
| PP-288 | Sin datos y periodo | Periodo sin pagos de la modalidad | Abrir el detalle con fechas | «No hay pagos de esta modalidad en el periodo seleccionado.» | ⬜ |
| PP-289 | Búsqueda del detalle | Modalidad con más de 10 préstamos | Escribir nombre o cédula | Filtra en tiempo real; sin coincidencias, «No hay préstamos que coincidan con la búsqueda» | ⬜ |
| PP-290 | Imprimir y volver | Detalle abierto | «Imprimir»; «Volver al resumen» | Hoja con logo, título, modalidad y periodo, tabla, totales y pie con fecha, hora y usuario, sin botones; el enlace conserva las fechas | ⬜ |
| PP-291 | Permisos | Cuenta sin / con `reportes.ver` | Abrir el detalle | 403 / pantalla; invitado va al login | ⬜ |

## Pruebas no funcionales (verificación transversal)

| ID | RNF relacionado | Caso de prueba | Resultado obtenido |
|---|---|---|---|
| PP-053 | RNF-001 | Un Funcionario Bienestar (o un administrador que no es el principal) no puede acceder a Usuarios ni a Auditoría | ⬜ |
| PP-054 | RNF-002 | Las contraseñas nunca aparecen en texto plano en base de datos ni en logs | ⬜ |
| PP-055 | RNF-003 | Eliminar un empleado con préstamos activos no deja registros huérfanos | ⬜ |
| PP-056 | RNF-004 | Los totales de la tabla de amortización cuadran exactamente con el monto aprobado | ⬜ |
| PP-057 | RNF-006 | Listados grandes (empleados, préstamos) cargan paginados, no todos de una vez | ⬜ |
| PP-058 | RNF-007 | Formularios muestran mensajes de validación claros ante datos inválidos | ⬜ |

## Resumen de cobertura

| Sprint | Estado de las pruebas |
|---|---|
| Sprint 1 (empleados, usuarios, login) | Cubierto — PP-001 a PP-018 |
| Sprint 2 (modalidades, presupuesto, solicitudes, préstamos/desembolsos, pagos) | Cubierto — PP-019 a PP-041 |
| Sprint 3 (situaciones especiales) | **Parcial** — reliquidación y cesantías cubiertas; retiro/liquidación pendiente (PP-044, PP-045) |
| Sprint 4 (reportes, auditoría) | Cubierto — PP-046 a PP-052 |
| Confirmación de la quincena (posterior al Sprint 4) | Automatizada — PP-059 a PP-079; falta la prueba manual en el navegador |
| Envío de solicitudes al comité (posterior) | Automatizada — PP-080 a PP-091; falta la prueba manual en el navegador |
| Permisos por cuenta (posterior) | Automatizada — PP-092 a PP-102; falta la prueba manual en el navegador |
| Formato de montos y mensajes en español (posterior) | Automatizada — PP-103 a PP-110; falta la prueba manual en el navegador |
| Vencimientos por calendario y cierre provisional (posterior) | Automatizada — PP-111 a PP-119; falta la prueba manual en el navegador |
| Tope de la tasa de vivienda (posterior) | Automatizada — PP-120 a PP-134; falta la prueba manual en el navegador |
| Bloqueo por salario mínimo (posterior) | Automatizada — PP-135 a PP-142; falta la prueba manual en el navegador |
| Registro y edición de tasas (posterior) | Automatizada — PP-143 a PP-152; falta la prueba manual en el navegador |
| Primas de seguros (posterior) | Automatizada — PP-153 a PP-166; falta la prueba manual en el navegador |
| Capacidad de endeudamiento unificada (posterior) | Automatizada — PP-167 a PP-177; falta la prueba manual en el navegador |
| Primera cuota desde el desembolso (posterior) | Automatizada — PP-178 a PP-196; falta la prueba manual en el navegador |
| Aviso de cuota excluida del descuento por nómina (posterior) | Automatizada — PP-197 a PP-204; falta la prueba manual en el navegador |
| Tipos de vinculación y docentes ocasionales (posterior) | Automatizada — PP-205 a PP-218; falta la prueba manual en el navegador |
| DTF para la liquidación por retiro (posterior) | Automatizada — PP-219 a PP-230; falta la prueba manual en el navegador |
| Informe de quincena por cuota (posterior) | Automatizada — PP-231 a PP-244; falta la prueba manual en el navegador |
| Pagos y abonos solo después del desembolso (posterior) | Automatizada — PP-245 a PP-249; falta la prueba manual en el navegador |
| Tasa de vehículo por tramo de plazo (posterior) | Automatizada — PP-250 a PP-256; falta la prueba manual en el navegador |
| Primera cuota según el cierre de novedades (posterior) | Automatizada — PP-257 a PP-266; falta la prueba manual en el navegador |
| Administrador principal, Acción y datos del empleado (posterior) | Automatizada — PP-267 a PP-275; falta la prueba manual en el navegador |
| Lista de préstamos, hoja impresa y quincena en curso (posterior) | Automatizada — PP-276 a PP-284; falta la prueba manual en el navegador |
| Resumen de pagos: todas las modalidades y detalle (posterior) | Automatizada — PP-285 a PP-291; falta la prueba manual en el navegador |
