# Arquitectura del Sistema
Aplicación de Préstamos de Bienestar Social — Politécnico Colombiano Jaime Isaza Cadavid

## 1. Estilo arquitectónico
Monolito modular, patrón Modelo-Vista-Controlador (MVC) nativo de Laravel. No microservicios: el volumen de usuarios y la complejidad no lo justifican. La modularidad interna permite evolucionar a servicios independientes a futuro si es necesario (RNF-009).

## 2. Capas del sistema
| Capa | Tecnología | Responsabilidad |
|---|---|---|
| Presentación | Blade, Bootstrap | Vistas, formularios, dashboard |
| Controladores | Laravel (App/Http/Controllers) | Reciben la solicitud, validan entrada, delegan a servicios |
| Lógica de negocio | Laravel (App/Services) | Reglas: tasa, capacidad de endeudamiento, aplicación de pagos, reliquidación |
| Acceso a datos | Eloquent ORM | Mapeo objeto-relacional; previene inyección SQL |
| Base de datos | MariaDB | Almacenamiento persistente |

> La separación entre presentación, lógica de negocio y acceso a datos responde a RNF-009 (Mantenibilidad).

## 3. Módulos funcionales
Organizados según los requisitos funcionales consolidados (`docs/requisitos-funcionales.md`):
- Gestión de usuarios y acceso (RF-001)
- Gestión de empleados (RF-002)
- Gestión de modalidades y parámetros (RF-003)
- Gestión y control presupuestal (RF-004)
- Gestión de solicitudes (RF-005)
- Gestión de préstamos y desembolsos (RF-006)
- Gestión de amortización y pagos (RF-007)
- Gestión de situaciones especiales del préstamo (RF-008)
- Reportes e indicadores (RF-009)
- Auditoría y trazabilidad (RF-010)

## 4. Flujo general (ejemplo: registro de un préstamo)
Empleado/Funcionario solicita → Controlador de Solicitudes valida entrada → Servicio de Solicitudes valida tope de tasa, capacidad de endeudamiento y disponibilidad presupuestal (consultando los servicios de Modalidades y Presupuesto) → si es aprobada, el Servicio de Préstamos genera el registro y delega al Servicio de Amortización la generación de la tabla de cuotas → el Servicio de Auditoría registra cada paso relevante.

## 5. Seguridad
- Autenticación con cédula y contraseña (hash) — RNF-001, RNF-002.
- Control de acceso por rol (Administrador / Funcionario Bienestar) — RNF-001.
- Eloquent ORM en todas las consultas, sin construir SQL con entradas de usuario sin parametrizar.
- Protección de la información personal, laboral y financiera de los empleados mediante credenciales con hash, control de acceso por rol y auditoría — RNF-002. Al instalarse de forma local (ver sección 6) no se exige HTTPS en esta entrega; queda como trabajo futuro si el aplicativo llega a publicarse en un servidor.
- Registro de auditoría de operaciones críticas — RNF-005.

## 6. Instalación, disponibilidad y respaldo
- **Instalación:** el aplicativo se instala de forma local en el equipo de la jefatura de Bienestar Social, con acceso opcional desde otros equipos por la red local. No se despliega en un servidor institucional ni se entrega a Informática corporativa.
- Disponibilidad durante el horario en que el equipo de la jefatura esté encendido y en operación, salvo mantenimientos programados — RNF-008.
- **Respaldo:** copia automática de la base de datos MariaDB mediante una tarea programada en el mismo equipo, con procedimiento de restauración documentado — RNF-008.
- **Trabajo futuro:** si el aplicativo se publica en un servidor, habrá que añadir HTTPS y revisar el esquema de respaldo, que hoy depende de un único equipo.

## 7. Herramientas de desarrollo y entrega
| Componente | Herramienta |
|---|---|
| Lenguaje de programación | PHP |
| Framework | Laravel |
| Motor de plantillas (frontend) | Blade |
| Frontend (estilos) | Bootstrap |
| Base de datos | MariaDB |
| Control de versiones | Git |
| Repositorio y entrega | GitHub, más la instalación en el equipo de la jefatura de Bienestar Social |
| Asistencia de desarrollo con IA | Claude Code |
| Editor de código | Visual Studio Code |
| Modelado | diagrams.net / Mermaid |

## 8. Continuidad y mantenibilidad
El código fuente se entrega documentado — arquitectura, modelo de datos, reglas de negocio y requisitos — como parte de la mantenibilidad del sistema (RNF-009), organizado como repositorio en GitHub. La entrega consiste en ese repositorio documentado más la instalación del aplicativo en el equipo de la jefatura de Bienestar Social.
