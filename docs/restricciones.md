# Otras Restricciones y Decisiones del Proyecto
Aplicación de Préstamos de Bienestar Social — Politécnico Colombiano Jaime Isaza Cadavid

## 1. Alcance excluido explícitamente
- No hay integración ni migración de datos con el sistema AS/400.
- No hay integración directa con el módulo de nómina.
- No hay pasarela de pago en línea; los pagos se registran manualmente.
- La votación individual del Comité no se registra en el sistema; se gestiona por correo. El sistema solo registra la solicitud y su resultado final.
- El sistema de puntaje de convocatorias (Art. 17) no está automatizado hoy. Alcance por decidir; el detalle del puntaje está en `docs/reglas-negocio.md`, sección 15.
- La gestión de pólizas de seguro no tiene módulo propio más allá de las reglas de negocio documentadas.
- Los casos especiales del Art. 24 (desastre natural, amenaza de ruina, alteración de orden público, FOVIS, terrorismo) no tienen flujo automatizado; se gestionan como excepciones manuales del Comité.
- No se incluyen funcionalidades de IA de tipo predictivo dentro de la aplicación entregada.

## 2. Restricciones tecnológicas
- Backend: PHP con Laravel.
- Frontend: Blade, sin framework de frontend independiente.
- Base de datos: MariaDB.
- Control de versiones: Git, entrega final como repositorio en GitHub.
- Asistencia de desarrollo: Claude Code, durante la construcción del software — no como funcionalidad del sistema entregado.

## 3. Restricciones de entrega
- **Decisión pendiente: modalidad de instalación (local o servidor).** Por confirmar con Bienestar Social.
- La entrega es el repositorio de GitHub documentado más la instalación del aplicativo en el equipo o servidor que se defina.
- Instalación: escenario de trabajo actual, local en el equipo de la jefatura de Bienestar Social con acceso opcional por red local. Alternativa: servidor institucional. Quién recibe el código (Bienestar Social o Informática corporativa) depende de esa decisión.
- HTTPS se mantiene como requisito (RNF-002). En instalación local, el acceso es por localhost o red local y el HTTPS se configura cuando se publique en red.
- El respaldo de la base de datos es una copia automática mediante una tarea programada, válida en ambos escenarios.
- La entrega incluye documentación técnica y funcional (RNF-025).

## 4. Restricciones de datos
- No existe historial de pagos previo que migrar: el AS/400 no conserva histórico de nómina. El historial de la nueva aplicación inicia desde su puesta en funcionamiento.
- Roles fijos: Administrador y Funcionario Bienestar. El Funcionario tiene las mismas funciones que el Administrador, salvo dos exclusivas del Administrador: la gestión de cuentas de usuario y la consulta de auditoría. Los reportes e indicadores (RF-009) están disponibles para ambos roles.

## 5. Fuentes normativas
| Fuente | Uso en el proyecto |
|---|---|
| Acuerdo Directivo No. 19 del 12 de octubre de 2022 | Reglamento base: modalidades, cuantías, plazos, tasas, comité, presupuesto, seguros, garantías |
| Acuerdo Directivo No. 11 del 15 de noviembre de 2023 | Modifica la tasa de interés de vivienda |
| Ley 1527 de 2012 | Tope de capacidad de endeudamiento (libranza) |

## 6. Pendientes de validar con el área
- Cálculo exacto de mora según el Manual de Cartera institucional (no revisado).
- Proceso de reliquidación paso a paso: el cálculo del interés y del retiro está confirmado (`docs/reglas-negocio.md`, secciones 1.1 y 11); el resto, por confirmar (reunión 14-oct).
- Por confirmar (reunión 14-oct): tasa de 5,20 % en una solicitud de 2025 frente al tope del 5 %, umbral y aplicación de abonos menores a 10 cuotas, cobertura del seguro de vida deudor, conceptos 14, 31 y 34 del AS/400 y la columna TIPO.
- Cantidad aproximada de empleados y préstamos vigentes.
- Calendario académico real del trabajo de grado.
