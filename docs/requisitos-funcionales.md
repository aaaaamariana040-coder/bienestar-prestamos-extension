# Requisitos Funcionales
Aplicación de Préstamos de Bienestar Social

| ID | Funcionalidad general | Descripción del requisito |
|---|---|---|
| RF-001 | Autenticación de usuarios | El sistema debe permitir a los usuarios autorizados ingresar mediante credenciales y restringir el acceso a las funcionalidades según los permisos asignados. |
| RF-002 | Gestión de usuarios | El sistema debe permitir al administrador registrar, consultar, modificar, habilitar y deshabilitar las cuentas de los usuarios del sistema. |
| RF-003 | Gestión de roles y permisos | El sistema debe permitir asignar roles y permisos a los usuarios para controlar el acceso a las diferentes funcionalidades del módulo de Bienestar Social. |
| RF-004 | Cambio de contraseña | El sistema debe permitir a los usuarios autenticados cambiar su contraseña de acceso. |
| RF-005 | Registro de empleados | El sistema debe permitir registrar la información necesaria de los empleados que soliciten o tengan un préstamo administrado por Bienestar Social. |
| RF-006 | Consulta de empleados | El sistema debe permitir consultar y buscar empleados mediante criterios de identificación y visualizar la información relacionada con sus préstamos. |
| RF-007 | Actualización de empleados | El sistema debe permitir actualizar la información del empleado que sea administrable desde el módulo de Bienestar Social. |
| RF-008 | Historial crediticio del empleado | El sistema debe permitir consultar los préstamos asociados a un empleado, incluyendo vigentes y finalizados. |
| RF-009 | Gestión de modalidades de crédito | El sistema debe permitir administrar las modalidades de crédito disponibles, contemplando como mínimo vivienda compra, vivienda mejora, vehículo, educación, calamidad, libre inversión y otras definidas por la institución. |
| RF-010 | Parámetros por modalidad | El sistema debe permitir configurar para cada modalidad el monto máximo, plazo, tasa de interés y demás condiciones establecidas por la normativa aplicable. |
| RF-011 | Estado de modalidades | El sistema debe permitir habilitar o deshabilitar modalidades de crédito para controlar cuáles ya agotaron el presupuesto disponible del fondo. |
| RF-012 | Validación de tasa institucional | El sistema debe validar que la tasa de interés definida para un préstamo cumpla con el límite establecido por la normativa institucional aplicable. |
| RF-013 | Gestión del presupuesto | El sistema debe permitir registrar y administrar el presupuesto anual destinado a cada modalidad de crédito. |
| RF-014 | Control presupuestal | El sistema debe calcular y mostrar, para cada modalidad, los valores asignados, comprometidos y disponibles del presupuesto. |
| RF-015 | Historial presupuestal | El sistema debe conservar el historial de modificaciones realizadas sobre el presupuesto, por modalidad y período. |
| RF-016 | Control de cuotas según SMMLV | El sistema debe permitir administrar los parámetros usados para determinar el monto máximo de cuota mensual permitido según modalidad y las reglas establecidas. |
| RF-017 | Registro de solicitudes | El sistema debe permitir registrar solicitudes de préstamo indicando empleado, modalidad, monto solicitado, plazo y demás información requerida. |
| RF-018 | Validación de solicitudes | El sistema debe validar las condiciones del solicitante y del préstamo antes de permitir avanzar con la solicitud. |
| RF-019 | Validación de disponibilidad presupuestal | El sistema debe verificar la disponibilidad presupuestal de la modalidad antes de permitir la aprobación de una solicitud. |
| RF-020 | Consulta de solicitudes | El sistema debe permitir consultar las solicitudes registradas y filtrarlas por empleado, modalidad, estado, fecha y otros criterios disponibles. |
| RF-021 | Gestión de desembolsos | El sistema debe permitir registrar y controlar el desembolso de los préstamos aprobados. |
| RF-022 | Desembolso por tramos | El sistema debe permitir gestionar el desembolso de acuerdo con los tramos establecidos para cada modalidad, incluyendo el porcentaje inicial y el sujeto a condiciones posteriores. |
| RF-023 | Control del segundo desembolso | El sistema debe permitir registrar las condiciones necesarias para efectuar el desembolso posterior cuando esté sujeto a inspección o validación. |
| RF-024 | Generación de tabla de amortización | El sistema debe generar la tabla de amortización de cada préstamo, indicando capital, intereses, saldo y fecha de vencimiento de cada cuota. |
| RF-025 | Consulta de amortización | El sistema debe permitir consultar el detalle de la tabla de amortización de un préstamo. |
| RF-026 | Registro de pagos | El sistema debe permitir registrar los pagos realizados sobre cada préstamo. |
| RF-027 | Tipos de pago | El sistema debe permitir identificar el tipo de pago realizado: regular, abono extraordinario o cancelación. |
| RF-028 | Aplicación de pagos | El sistema debe aplicar los pagos al préstamo correspondiente siguiendo el orden establecido, diferenciando los valores destinados a intereses y capital. |
| RF-029 | Consulta del estado de cuenta | El sistema debe permitir consultar los pagos realizados, cuotas pendientes, saldo de capital y demás valores relacionados con un préstamo. |
| RF-030 | Control de vencimientos | El sistema debe identificar las cuotas cuyo vencimiento haya ocurrido y que permanezcan pendientes de pago. |
| RF-031 | Reliquidación de préstamos | El sistema debe permitir realizar la reliquidación de un préstamo cuando se presente una modificación autorizada en sus condiciones. |
| RF-032 | Recálculo del préstamo | Al reliquidar, el sistema debe recalcular saldo, cuotas, intereses y tabla de amortización cuando corresponda. |
| RF-033 | Registro de modificaciones | El sistema debe conservar el registro de las modificaciones realizadas sobre las condiciones originales del préstamo. |
| RF-034 | Gestión de abono de cesantías | El sistema debe permitir registrar los abonos de cesantías aplicables a los préstamos, de acuerdo con las condiciones y normativa correspondiente. |
| RF-035 | Retiro del empleado | El sistema debe permitir registrar el retiro de un empleado y determinar las acciones necesarias sobre los préstamos vigentes asociados. |
| RF-036 | Liquidación por retiro | El sistema debe permitir calcular y registrar la liquidación del saldo del préstamo cuando el empleado se retire, aplicando los parámetros establecidos. |
| RF-037 | Indicadores de préstamos | El dashboard debe permitir visualizar información resumida sobre préstamos activos, montos prestados, saldos pendientes y ejecución del presupuesto. |
| RF-038 | Indicadores por modalidad | El sistema debe permitir consultar indicadores agrupados por modalidad de crédito. |
| RF-039 | Reporte de préstamos | El sistema debe permitir generar reportes de los préstamos registrados y sus principales condiciones. |
| RF-040 | Reporte de pagos | El sistema debe permitir generar reportes de pagos realizados durante un período determinado. |
| RF-041 | Reporte de cartera | El sistema debe permitir generar reportes de cartera, incluyendo saldos pendientes, cuotas vencidas y valores de mora. |
| RF-042 | Reporte presupuestal | El sistema debe permitir consultar y generar información sobre el presupuesto asignado, comprometido y disponible por modalidad. |
| RF-043 | Registro de auditoría | El sistema debe registrar las operaciones relevantes realizadas por los usuarios sobre empleados, solicitudes, préstamos, pagos, presupuesto y demás información crítica. |
| RF-044 | Consulta de auditoría | El sistema debe permitir a los usuarios autorizados consultar el historial de operaciones realizadas en el sistema. |
| RF-045 | Trazabilidad de préstamos | El sistema debe permitir consultar la trazabilidad de un préstamo desde su solicitud hasta su desembolso, pagos, modificaciones y finalización. |
