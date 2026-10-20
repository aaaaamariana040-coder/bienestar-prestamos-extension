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
| PP-020 | Validar tope de tasa (≤5% EA vivienda) | Modalidad vivienda | Intentar registrar tasa superior a 5% | Rechazado con mensaje de validación (Acuerdo 11/2023) | ⬜ |
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
| PP-042 | Reliquidación por abono extraordinario | Abono superior a 10 cuotas quincenales | Registrar abono grande | Saldo recalculado, nueva tabla de cuotas generada | ⬜ |
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
## Pruebas no funcionales (verificación transversal)

| ID | RNF relacionado | Caso de prueba | Resultado obtenido |
|---|---|---|---|
| PP-053 | RNF-001 | Un Funcionario Bienestar no puede acceder a funciones exclusivas de Administrador | ⬜ |
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
