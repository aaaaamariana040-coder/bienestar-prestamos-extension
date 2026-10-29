# Guía de práctica

Datos de práctica **completamente ficticios** para instalar el aplicativo en otro equipo (Informática) y que una funcionaria de Bienestar pruebe el flujo completo. Ningún nombre ni cédula es real: los empleados se llaman «… Prueba Alfa/Beta/…» y sus cédulas están entre 1000000101 y 1000000120.

## Qué datos trae

**Referencia 2026** (los trae `PracticaSeeder`; modalidades, cuantías, tabla de puntos de vehículo y tipos de documento salen de los seeders del aplicativo):

| Dato | Contenido |
|---|---|
| Modalidades | Las 8 (vivienda en sus 5 modalidades, educación, calamidad, vehículo) con plazo máximo (vivienda 480 quincenas, vehículo 168, educación y calamidad 72) y cuantía máxima por tramo salarial |
| SMMLV 2026 | 1.750.905 |
| Tasas IPC | 2025: 5,20 % y 2026: 5,10 % para cada modalidad. Como el IPC supera el 5 %, **vivienda se liquida con el tope del 5 %** (Acuerdo 11/2023); las demás usan el IPC |
| Vehículo | Tabla de puntos adicionales sobre el IPC por plazo (primera y segunda solicitud) |
| Presupuesto 2026 | Total ficticio de $3.000.000.000: vivienda 70 % (repartido con la sugerencia del aplicativo entre sus 5 modalidades) y 30 % para educación, calamidad y vehículo en partes iguales |
| Seguros | Años anterior y actual: vida deudores 0,0003231; incendio y terremoto 0 |
| DTF | 9,81 % nominal con vigencia 2026-07-01 (el efectivo anual se calcula) |
| Comité | Envío **simulado**: con `MAIL_MAILER=log` no sale ningún correo, solo un resumen en el log (ver paso 3) |

**Empleados (20):** Vinculado (9), Docente (6), Docente ocasional (2, bloqueados para crédito), Jubilado (2); de carrera y no de carrera; casi todos con salarios muy por encima del mínimo, y uno (cédula 1000000120) con el salario mínimo. Uno de ellos (1000000109) tiene menos de un año de servicio. Doce tienen préstamos vigentes y ocho no.

**Préstamos vigentes (14):** `docs/practica/prestamos-practica.csv`, en el formato de «Cargar préstamos vigentes», con todas las modalidades, distintas fechas de desembolso, plazos y cuotas pagadas. Sus próximas cuotas vencen el 31 de octubre y el 15 y 30 de noviembre y el 15 y 31 de diciembre de 2026, para que la quincena en curso y la siguiente muestren varios empleados. Se cargan con el mismo servicio de la pantalla de carga: quedan con `origen = carga_inicial` y un lote, y no consumen presupuesto. Los pagos anteriores no existen como pagos (las cuotas pagadas están marcadas, sin registro de pago).

**Solicitudes radicadas (4):**

| Empleado | Solicitud | Qué debe pasar |
|---|---|---|
| 1000000105 (Liliana Prueba Épsilon) | Educación, $3.000.000, 24 quincenas | Cumple todo: **se puede aprobar** |
| 1000000108 (Rodrigo Prueba Theta) | Vivienda — compra, $150.000.000, 120 quincenas | **No cumple la capacidad de endeudamiento** |
| 1000000120 (Julián Prueba Ípsilon) | Educación, $2.000.000, 24 quincenas | **Bloqueada**: devenga el salario mínimo |
| 1000000116 (Fabián Prueba Pi) | Educación, $3.000.000, 24 quincenas | **Bloqueada**: docente ocasional |

> Las fechas de las cuotas pagadas del CSV están pensadas para practicar entre finales de octubre y diciembre de 2026. En otras fechas todo funciona, pero las quincenas en curso mostrarán menos empleados.

## Usuarios

| Cédula | Perfil | Contraseña temporal |
|---|---|---|
| 1000000000 | Administrador principal (Usuarios, Auditoría, cargar préstamos vigentes) | `Practica#Admin2026` |
| 1000000001 | Funcionario de Bienestar | `Practica#Func2026` |

**Cambie las contraseñas en el primer ingreso** (Perfil → Cambiar contraseña, y el administrador puede cambiar la del funcionario en Usuarios). Son temporales y están escritas en este documento: no las use en un equipo con datos reales.

## Cómo cargar los datos en una instalación nueva

Requisitos: PHP 8.3, Composer, Node y una base de datos MariaDB (o SQLite) vacía.

1. Copie el proyecto, abra una terminal en su carpeta e instale: `composer run setup` (instala dependencias, crea el `.env`, genera la llave, migra y compila el frontend).
2. Configure la base de datos en el `.env` (`DB_CONNECTION`, `DB_DATABASE`, usuario y clave).
3. En el `.env` ponga:
   ```
   APP_ENV=local
   PRACTICA_HABILITADA=true
   MAIL_MAILER=log
   ```
   `PRACTICA_HABILITADA` viene en `false` por defecto; sin `true` la carga y el comando de reinicio se niegan a correr, y con `APP_ENV=production` nunca corren. `MAIL_MAILER=log` deja el envío al comité simulado.
4. Limpie la configuración: `php artisan config:clear`
5. Cree las tablas vacías y cargue la práctica:
   ```
   php artisan migrate:fresh
   php artisan db:seed --class=PracticaSeeder
   ```
   `migrate:fresh` borra todo lo que hubiera en esa base: úselo solo en una base de práctica.
6. Arranque: `php artisan serve --port=8000` y entre a `http://127.0.0.1:8000` con los usuarios de arriba.

## Cómo reiniciar la práctica

Para volver al punto de partida (borra todo lo hecho y recarga los datos de práctica):

```
php artisan practica:reiniciar
```

El comando está protegido: solo funciona con `PRACTICA_HABILITADA=true` en el `.env`, se niega con `APP_ENV=production` y pide escribir `REINICIAR` para continuar. **Borra toda la base** a la que apunte el `.env`: nunca lo apunte a una base con datos reales. No borra archivos subidos (documentos de solicitudes) de `storage/`.

## Escenarios de práctica de la funcionaria

1. Entrar con la cuenta de la funcionaria y revisar el inicio, el menú y los permisos (no ve Usuarios, Auditoría ni «Cargar préstamos vigentes»).
2. Consultar empleados: buscar por cédula y por nombre; ver los tipos de vinculación y los de carrera.
3. Consultar modalidades, tasas (el tope del 5 % en vivienda), SMMLV, seguros, DTF y presupuesto 2026.
4. Usar el simulador de crédito con un empleado sin préstamos y con uno con préstamos; ver cómo cambia la capacidad.
5. Aprobar la solicitud de Liliana Prueba Épsilon; ver el préstamo generado (pendiente de desembolso) y el presupuesto comprometido.
6. Intentar aprobar la solicitud sin capacidad (Rodrigo Prueba Theta) y leer el motivo.
7. Intentar aprobar las solicitudes de salario mínimo y de docente ocasional y leer el motivo; intentar registrar una nueva para esos empleados.
8. Registrar el desembolso del préstamo aprobado, elegir la quincena de la primera cuota y ver el calendario de cuotas.
9. Registrar el pago de una cuota, un abono extraordinario y una cancelación total en préstamos vigentes de la cartera cargada.
10. Abrir Quincenas: la quincena en curso y la siguiente con varios empleados; ver la vista previa, descargar el informe y confirmar una quincena.
11. Ver el Resumen de pagos y sus detalles, el historial de pagos de un empleado y la hoja impresa de un préstamo.
12. Enviar una solicitud al comité (envío simulado: revisar el resumen en `storage/logs`).
13. Registrar un empleado nuevo, un proveedor y una solicitud nueva con sus documentos.
14. (Administrador) Cargar un lote propio con «Cargar préstamos vigentes», ver la vista previa con errores y advertencias, y revertirlo; revisar la Auditoría.
15. Reiniciar la práctica con `php artisan practica:reiniciar` y comprobar que todo vuelve al inicio.
