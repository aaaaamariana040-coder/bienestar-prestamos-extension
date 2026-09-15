# Requisitos No Funcionales
Aplicación de Préstamos de Bienestar Social

| ID | Categoría | Descripción del requisito |
|---|---|---|
| RNF-001 | Seguridad de acceso | El sistema debe requerir autenticación para acceder a las funcionalidades de Bienestar Social y debe impedir el acceso a usuarios no autorizados. |
| RNF-002 | Control de acceso | El sistema debe implementar un mecanismo de roles y permisos que limite las operaciones disponibles para cada tipo de usuario. |
| RNF-003 | Protección de credenciales | Las contraseñas deben almacenarse utilizando mecanismos seguros de hash y nunca deben conservarse en texto plano. |
| RNF-004 | Protección de información | El sistema debe proteger la información personal, laboral y financiera de los empleados frente a accesos o modificaciones no autorizadas. |
| RNF-005 | Integridad de datos | El sistema debe garantizar que las relaciones entre empleados, solicitudes, préstamos, cuotas y pagos mantengan consistencia durante las operaciones realizadas. |
| RNF-006 | Integridad financiera | El sistema no debe permitir que una operación produzca saldos, cuotas o valores financieros inconsistentes con las condiciones registradas del préstamo. |
| RNF-007 | Precisión de cálculos | Los cálculos relacionados con tasas, cuotas, amortización, capacidad de endeudamiento, saldos y mora deben realizarse de forma consistente y utilizando los parámetros vigentes configurados. |
| RNF-008 | Trazabilidad | Las modificaciones sobre información financiera o condiciones de los préstamos deben quedar registradas, permitiendo identificar usuario, fecha y operación realizada. |
| RNF-009 | Auditoría | El sistema debe conservar registros de las operaciones críticas realizadas sobre solicitudes, préstamos, pagos, reliquidaciones, presupuesto y demás información relevante. |
| RNF-010 | Disponibilidad | El sistema debe estar disponible durante el horario de operación establecido por la institución, exceptuando mantenimientos programados. |
| RNF-011 | Rendimiento | El sistema debe responder en tiempos adecuados a las operaciones habituales de consulta, registro y actualización, sin afectar el flujo de trabajo de los usuarios. |
| RNF-012 | Rendimiento de consultas | Las consultas que puedan retornar grandes cantidades de registros deben usar paginación y filtros para evitar cargas innecesarias. |
| RNF-013 | Usabilidad | La interfaz debe presentar una estructura clara y consistente que facilite el registro, consulta y seguimiento de las operaciones de préstamos. |
| RNF-014 | Validación | El sistema debe validar la información ingresada antes de almacenarla e informar al usuario cuando los datos no cumplan las condiciones requeridas. |
| RNF-015 | Mensajes del sistema | Los mensajes de error, advertencia y confirmación deben ser comprensibles y explicar claramente el resultado de la operación realizada. |
| RNF-016 | Mantenibilidad | El sistema debe desarrollarse mediante una arquitectura modular que facilite el mantenimiento, corrección de errores y evolución de sus funcionalidades. |
| RNF-017 | Escalabilidad | La arquitectura debe permitir incorporar nuevas modalidades, reglas, reportes y funcionalidades sin requerir una reconstrucción completa del sistema. |
| RNF-018 | Configurabilidad | Los parámetros que puedan cambiar por normativa institucional deben poder administrarse sin modificar directamente el código fuente, cuando sea técnicamente viable. |
| RNF-019 | Compatibilidad | La aplicación web debe funcionar correctamente en los navegadores utilizados por la institución, priorizando versiones actuales de uso general. |
| RNF-020 | Diseño responsivo | La interfaz debe adaptarse correctamente a las diferentes resoluciones de pantalla utilizadas para acceder al sistema. |
| RNF-021 | Respaldo | La información almacenada debe contar con mecanismos periódicos de respaldo que permitan recuperar los datos ante fallos o pérdida de información. |
| RNF-022 | Recuperación | El sistema debe contar con mecanismos que permitan restaurar la información respaldada y recuperar la operación después de una falla técnica. |
| RNF-023 | Seguridad de comunicaciones | La comunicación entre el cliente y el servidor debe realizarse mediante protocolos seguros (HTTPS) para proteger la información transmitida. |
| RNF-024 | Separación de responsabilidades | La aplicación debe mantener separadas las responsabilidades de presentación, lógica de negocio y acceso a datos, para facilitar su mantenimiento y evolución. |
| RNF-025 | Documentación | El sistema debe contar con documentación técnica y funcional suficiente para facilitar su mantenimiento, configuración y utilización. |
