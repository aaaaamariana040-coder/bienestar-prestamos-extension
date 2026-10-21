# Requisitos No Funcionales
Aplicación de Préstamos de Bienestar Social

| ID | Categoría | Descripción del requisito |
|---|---|---|
| RNF-001 | Seguridad y acceso | El sistema debe requerir autenticación y manejar roles y permisos para impedir el acceso y las operaciones no autorizadas. Los permisos son por cuenta (`usuarios.permisos`, catálogo en `config/permisos.php`); toda ruta de la aplicación exige un permiso (middleware `can:`) salvo el inicio y el cambio de la propia contraseña, el menú solo muestra lo que la cuenta puede usar y el servidor responde 403 aunque se escriba la dirección a mano. Una cuenta deshabilitada no conserva ningún permiso. |
| RNF-002 | Protección de información | El sistema debe proteger la información personal, laboral y financiera de los empleados, incluyendo el almacenamiento seguro de las credenciales y la transmisión segura de la información mediante protocolos cifrados (HTTPS). |
| RNF-003 | Integridad de datos | El sistema debe garantizar la consistencia de la información relacionada con empleados, solicitudes, préstamos, cuotas y pagos durante las operaciones realizadas. |
| RNF-004 | Integridad y precisión financiera | El sistema debe garantizar que los cálculos de tasas, cuotas, amortización, saldos y mora sean consistentes con las condiciones y parámetros establecidos para cada préstamo. |
| RNF-005 | Auditoría y trazabilidad | El sistema debe registrar las operaciones y modificaciones relevantes realizadas sobre solicitudes, préstamos y pagos, identificando el usuario, la fecha y la operación realizada. |
| RNF-006 | Rendimiento | El sistema debe responder adecuadamente a las operaciones habituales de consulta, registro y actualización, utilizando filtros y paginación cuando sea necesario. |
| RNF-007 | Usabilidad y validación | La interfaz debe ser clara y facilitar el registro y consulta de información, validando los datos ingresados e informando al usuario cuando no cumplan las condiciones requeridas. |
| RNF-008 | Disponibilidad y respaldo | El sistema debe estar disponible durante el horario de operación establecido y contar con mecanismos de respaldo que permitan recuperar la información ante fallos. |
| RNF-009 | Mantenibilidad | El sistema debe utilizar una arquitectura modular que facilite el mantenimiento, corrección de errores y evolución de sus funcionalidades, contando con documentación técnica y funcional suficiente para su mantenimiento y configuración. |
| RNF-010 | Configurabilidad | Los parámetros sujetos a cambios normativos o institucionales deben poder administrarse sin modificar directamente el código fuente, cuando sea técnicamente viable. |
