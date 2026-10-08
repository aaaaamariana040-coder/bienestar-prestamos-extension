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
| PP-019 | Crear modalidad de crédito | Autenticado | Registrar modalidad con monto máximo, plazo y tasa | Modalidad creada; plazo almacenado en quincenas | ⬜ |
| PP-020 | Validar tope de tasa (≤5% EA vivienda) | Modalidad vivienda | Intentar registrar tasa superior a 5% | Rechazado con mensaje de validación (Acuerdo 11/2023) | ⬜ |
| PP-021 | Visualización del plazo según Acuerdo | Modalidad vivienda con 480 quincenas | Ver el plazo en la interfaz | Se muestra "20 años", no "480 quincenas" | ⬜ |
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
