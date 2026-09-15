# Arquitectura del Sistema
Aplicación de Préstamos de Bienestar Social — Politécnico Colombiano Jaime Isaza Cadavid

## 1. Estilo arquitectónico
Monolito modular, patrón Modelo-Vista-Controlador (MVC) nativo de Laravel. No microservicios: el volumen de usuarios y la complejidad no lo justifican. La modularidad interna permite evolucionar a servicios independientes a futuro si es necesario (RNF-016, RNF-017).

## 2. Capas del sistema
| Capa | Tecnología | Responsabilidad |
|---|---|---|
| Presentación | Blade, Bootstrap | Vistas, formularios, dashboard |
| Controladores | Laravel (App/Http/Controllers) | Reciben la solicitud, validan entrada, delegan a servicios |
| Lógica de negocio | Laravel (App/Services) | Reglas: tasa, capacidad de endeudamiento, aplicación de pagos, reliquidación |
| Acceso a datos | Eloquent ORM | Mapeo objeto-relacional; previene inyección SQL |
| Base de datos | MariaDB | Almacenamiento persistente |

> La separación entre presentación, lógica de negocio y acceso a datos responde a RNF-024.

## 3. Módulos funcionales
- Autenticación y usuarios (RF-001 a RF-004)
- Empleados (RF-005 a RF-008)
- Modalidades, tasas y presupuesto (RF-009 a RF-016)
- Solicitudes de crédito (RF-017 a RF-020)
- Desembolsos y proveedores (RF-021 a RF-023)
- Amortización y pagos (RF-024 a RF-030)
- Reliquidación, cesantías y retiro del empleado (RF-031 a RF-036)
- Reportes y dashboard (RF-037 a RF-042)
- Auditoría y trazabilidad (RF-043 a RF-045)

## 4. Flujo general (ejemplo: registro de un préstamo)
Empleado/Funcionario solicita → Controlador de Solicitudes valida entrada → Servicio de Solicitudes valida tope de tasa, capacidad de endeudamiento y disponibilidad presupuestal (consultando los servicios de Modalidades y Presupuesto) → si es aprobada, el Servicio de Préstamos genera el registro y delega al Servicio de Amortización la generación de la tabla de cuotas → el Servicio de Auditoría registra cada paso relevante.

## 5. Seguridad
- Autenticación con cédula y contraseña (hash) — RNF-001, RNF-003.
- Control de acceso por rol (Administrador / Funcionario Bienestar) — RNF-002.
- Eloquent ORM en todas las consultas, sin construir SQL con entradas de usuario sin parametrizar.
- Comunicación cliente-servidor mediante HTTPS — RNF-023.
- Registro de auditoría de operaciones críticas — RNF-008, RNF-009.

## 6. Disponibilidad y respaldo
- Disponibilidad durante el horario de operación institucional, salvo mantenimientos programados (RNF-010).
- Respaldo periódico de MariaDB (RNF-021) y procedimiento de restauración documentado (RNF-022) — a definir con Informática corporativa al desplegar.

## 7. Herramientas de desarrollo y entrega
| Componente | Herramienta |
|---|---|
| Lenguaje de programación | PHP |
| Framework | Laravel |
| Motor de plantillas (frontend) | Blade |
| Base de datos | MariaDB |
| Control de versiones | Git |
| Repositorio y entrega | GitHub (entregado a Informática corporativa) |
| Asistencia de desarrollo con IA | Claude Code |
| Editor de código | Visual Studio Code |
| Modelado | diagrams.net / Mermaid |

## 8. Continuidad
El código fuente se entrega documentado (RNF-025) como repositorio en GitHub al área de Informática corporativa, que ya cuenta con personal capacitado en este stack.
