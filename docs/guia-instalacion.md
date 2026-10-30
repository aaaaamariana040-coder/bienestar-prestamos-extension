# Guía de instalación (Windows)

Para el área de Informática del Politécnico Colombiano Jaime Isaza Cadavid. Deja el aplicativo **Bienestar Préstamos** instalado en un equipo Windows y listo para uso real, sin datos de práctica ni datos reales dentro del código. Todos los comandos se ejecutan desde una terminal (PowerShell o `cmd`) abierta **en la carpeta del proyecto**, salvo que se indique otra cosa.

> Lo que NO debe hacerse en un equipo real: `php artisan migrate:fresh`, `php artisan practica:reiniciar`, `datos:convertir-q` ni cualquier seeder de práctica o demostración. Ver la sección 9.

## 1. Requisitos

| Componente | Versión | Notas |
|---|---|---|
| PHP | **8.3 o superior** (`composer.json` exige `^8.3`); probado con 8.3.33 | Extensiones: `mbstring`, `openssl`, `pdo_mysql`, `fileinfo`, `intl`, `gd`, `xml`, `ctype`, `json`, `tokenizer`, `curl`. La extensión `zip` es **recomendada** (el aplicativo hoy entrega sus informes en CSV y no la exige, pero conviene tenerla activa). |
| MariaDB o MySQL | Probado con **MariaDB 12.3** (en producción se apunta a MariaDB, `docs/arquitectura.md`) | Codificación `utf8mb4`. |
| Composer | 2.x | https://getcomposer.org |
| Git | Cualquiera reciente | Opcional: se puede recibir el código como carpeta comprimida. |
| Node.js | 20 o superior (probado con 22) | **Solo para compilar la interfaz** (sección 2.4). Puede hacerse en otro equipo. |
| Navegador | Edge, Chrome o Firefox actuales | |

### Opción recomendada: Laragon

Es la pila con la que se desarrolló y probó. Descargue Laragon Full desde https://laragon.org, instálelo y, desde su menú, inicie Apache/Nginx y MariaDB. Trae PHP, MariaDB, Composer, Git y Node.js; agregue la carpeta de `php` y de `mysql/bin` de Laragon al `PATH` de Windows (Laragon lo hace al abrir su terminal: use «Terminal» desde Laragon). Para activar `zip`: menú de Laragon → PHP → Extensiones → `zip`.

### Si Informática usa otra pila (XAMPP, IIS, servidor Linux, etc.)

Sirve cualquier pila que cumpla la tabla. Lo único que cambia:
- El **servidor web debe apuntar a la carpeta `public/`** del proyecto (nunca a la raíz).
- Si no usa Apache/Nginx, puede usar el servidor integrado (sección 4).
- Los scripts `scripts\respaldo-bd.bat` y `scripts\restaurar-bd.bat` necesitan `mysqldump.exe` y `mysql.exe` en el `PATH` (o las variables `MYSQLDUMP_EXE` y `MYSQL_EXE`, sección 6).

Compruebe lo instalado:

```
php -v
php -m
composer --version
mysql --version
node -v
```

`php -m` debe listar las extensiones de la tabla.

## 2. Instalación

### 2.1 Obtener el código

Con Git:
```
git clone https://github.com/aaaaamariana040-coder/bienestar-prestamos-extension.git bienestar-prestamos
cd bienestar-prestamos
```
O copie la carpeta del proyecto que entregue el equipo desarrollador y ábrala en la terminal. Use una ruta sin tildes ni espacios (por ejemplo `D:\laragon\www\bienestar-prestamos`).

### 2.2 Dependencias de PHP

```
composer install --no-dev --optimize-autoloader
```

### 2.3 Archivo `.env`

```
copy .env.example .env
php artisan key:generate
```

Abra `.env` con el Bloc de notas y cambie los valores de la tabla. Después de cualquier cambio en `.env`, ejecute `php artisan config:clear`.

| Variable | Valor en producción | Qué hace |
|---|---|---|
| `APP_NAME` | `"Bienestar Préstamos"` | Nombre que usa el aplicativo en correos y títulos. |
| `APP_ENV` | `production` | Modo de producción. **Además bloquea los comandos y seeders de práctica.** |
| `APP_DEBUG` | `false` | Con `true` los errores muestran rutas, consultas y datos internos. Nunca `true` en un equipo real. |
| `APP_URL` | La dirección con la que se entra, p. ej. `http://bienestar.politecnico.local` o `http://192.168.1.50:8000` | Se usa para generar enlaces. |
| `APP_LOCALE` | `es` (ya viene así) | Idioma. |
| `DB_CONNECTION` | `mariadb` (o `mysql`) | Motor. |
| `DB_HOST`, `DB_PORT` | `127.0.0.1`, `3306` | Servidor de base de datos. |
| `DB_DATABASE` | `bienestar_prestamos` | Nombre de la base (se crea en 2.5). |
| `DB_USERNAME`, `DB_PASSWORD` | Un usuario propio de la aplicación **con contraseña** | No use `root` sin contraseña. Ver 2.5. |
| `SESSION_LIFETIME` | `120` (minutos de inactividad) | Ajústelo si la política institucional lo pide. |
| `MAIL_MAILER` | `smtp` | Con `log` (valor por defecto) **el correo al Comité NO se envía**: solo se escribe en `storage/logs`. Con `smtp` salen correos reales. |
| `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_SCHEME` | Los del servidor de correo institucional | Informática los define. |
| `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | Dirección y nombre institucionales | Remitente de los correos. |
| `COMITE_REMITENTE_DIRECCION`, `COMITE_REMITENTE_NOMBRE` | Dirección y nombre reales de Bienestar | Quién firma el envío de la solicitud al Comité. Los `.test` de ejemplo no existen. |
| `COMITE_DESTINATARIOS`, `COMITE_COPIA` | Correos reales del Comité (separados por coma) y de Bienestar | A quién llega la solicitud. |
| `PRACTICA_HABILITADA` | `false` (ya viene así) | **No cambiar.** Solo se pone en `true` en un equipo de práctica. |

Variables `QUINCENA_*` (proceso de la quincena; detalle en `docs/quincena-automatica.md`). Los valores por defecto son los seguros: nada se paga solo y nada corre solo. No las cambie sin acuerdo con Bienestar Social:

| Variable | Por defecto | Qué decide |
|---|---|---|
| `QUINCENA_REGLA_VENCIMIENTO` | `calendario` | Cuándo vence una cuota: `calendario` (quincena 1-15 y 16-fin de mes) o `cada_15_dias` (desde la autorización). |
| `QUINCENA_MODO_MARCADO` | `confirmar` | `confirmar`: el proceso solo deja las cuotas pendientes de confirmar. `automatico`: crea los pagos a nombre del usuario Sistema. |
| `QUINCENA_INICIO_PRIMERA_CUOTA` | `desde_desembolso` | La primera cuota corre desde el desembolso (`desde_desembolso`) o desde la aprobación (`desde_aprobacion`). |
| `QUINCENA_CIERRE_NOVEDADES_DIAS_ANTES` | `2` | Días antes del vencimiento en que cierran las novedades de nómina. Solo informativo. |
| `QUINCENA_DESEMBOLSO_DIA_CIERRE_ENTRA` | `false` | Si un desembolso hecho el mismo día del cierre alcanza a entrar en esa quincena. |
| `QUINCENA_PROGRAMADOR_ACTIVO` | `false` | `true` hace que el programador de tareas ejecute el proceso real los días 15 y último de mes (sección 7.4). Déjelo en `false` mientras no esté decidido. |
| `QUINCENA_AVISO_CUOTA_EXCLUIDA`, `QUINCENA_AVISO_CUOTA_EXCLUIDA_LUGAR` | Texto institucional | Mensaje para cuotas no descontadas por nómina. |

Otras variables de `.env.example` (`CAPACIDAD_FUENTE`, `ABONO_*`, `TASA_VIVIENDA_*`, `DTF_CRITERIO`, `SEGURO_*`, `COMITE_*` opcionales) traen el valor acordado con Bienestar: déjelas como están.

Deje `SESSION_DRIVER=database`, `QUEUE_CONNECTION=database` y `CACHE_STORE=database`: el aplicativo no necesita ningún servicio adicional (no hay que dejar un «worker» de colas corriendo; el correo al Comité se envía en el momento).

### 2.4 Compilar la interfaz (obligatorio)

La carpeta `public/build` (estilos, scripts y tipografías) **no viene en el repositorio**. Sin ella, la pantalla de ingreso falla con «Unable to locate file in Vite manifest». Compílela una vez:

```
npm ci
npm run build
```

- Necesita **acceso a Internet durante la compilación** (descarga la tipografía Instrument Sans).
- Si el equipo del servidor no tiene Node.js o Internet, compile en otro equipo con el mismo código y copie la carpeta `public\build` completa al servidor. Después no se necesita Node.js en el servidor.
- Terminado el paso, no hace falta volver a ejecutarlo salvo que una actualización cambie la interfaz (sección 8).

### 2.5 Crear la base de datos y su usuario

Entre al cliente de MariaDB (`mysql -u root -p`) y ejecute (cambie la contraseña por una propia, larga y única):

```sql
CREATE DATABASE bienestar_prestamos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'bienestar'@'localhost' IDENTIFIED BY 'CAMBIE-ESTA-CLAVE';
GRANT ALL PRIVILEGES ON bienestar_prestamos.* TO 'bienestar'@'localhost';
FLUSH PRIVILEGES;
```

Ponga en `.env`: `DB_DATABASE=bienestar_prestamos`, `DB_USERNAME=bienestar` y `DB_PASSWORD=` la contraseña elegida. Si la contraseña tiene espacios, `#` o `$`, póngala entre comillas dobles.

### 2.6 Crear las tablas

```
php artisan config:clear
php artisan migrate --force
```

Debe terminar sin errores y mostrar cada migración como `DONE`.

## 3. Datos mínimos de arranque

Una instalación real necesita estos datos para funcionar; **ninguno es de práctica**:

| Dato | Origen | Por qué hace falta |
|---|---|---|
| Modalidades de préstamo (8) con su plazo máximo y sus cuantías por tramo salarial (Acuerdo Directivo 19/2022) | `TipoPrestamoSeeder` | Sin ellas no se puede solicitar ni simular un crédito. |
| Matriz de puntos adicionales sobre el IPC para vehículo (tabla 4.4 de `docs/reglas-negocio.md`) | `TasaVehiculoSeeder` | Tasa del crédito de vehículo. |
| Tipos de documento (Cédula, Certificado laboral, etc.) | `TipoDocumentoSeeder` | Soportes de la solicitud. El catálogo se puede ajustar luego. |
| Usuario «Sistema» (cédula `0000000000`, deshabilitado, sin contraseña conocida) | `SistemaUsuarioSeeder` | Las acciones automáticas (quincena) quedan a su nombre; no puede iniciar sesión. |
| Administrador principal | Se crea con el comando, pidiendo la contraseña | Única cuenta que gestiona usuarios y ve la auditoría. |

(Las primas de seguro del año actual y el anterior ya las crea la migración `create_primas_seguro_table`.)

Todo se carga con un solo comando, **idempotente** (se puede repetir sin duplicar ni cambiar nada), que nunca carga datos de práctica ni ficticios:

```
php artisan app:preparar-instalacion
```

Escriba la terminal directamente (no use redirección ni tuberías `|`): el comando le pedirá cédula, nombre, apellido y la **contraseña del administrador principal**, que no se ve al escribirla. Reglas de la contraseña: mínimo 8 caracteres, con una mayúscula, una minúscula y un número. No existe ninguna contraseña por defecto. También se puede indicar la identidad por opciones: `php artisan app:preparar-instalacion --cedula=1234567890 --nombre=Ana --apellido=Pérez` (la contraseña siempre se pide por consola).

Si el administrador principal ya existe, el comando solo verifica los catálogos y no cambia su contraseña. Si se olvidó la contraseña, ver «Solución de problemas».

> **La única forma de crear el administrador es `php artisan app:preparar-instalacion`.** Ningún seeder de la instalación crea administradores ni usuarios con contraseña conocida: `php artisan db:seed` (`DatabaseSeeder`) solo carga los catálogos y el usuario «Sistema», que está deshabilitado. Los seeders de práctica y de pruebas (`PracticaSeeder`, `PlanPruebasSeeder`) sí crean cuentas con contraseñas de ejemplo; por eso se niegan a correr con `APP_ENV=production` y no deben usarse en un equipo real.

## 4. Primer arranque

### 4.1 Con Laragon / Apache / Nginx

Apunte el servidor web a la carpeta `public/` del proyecto. En Laragon, la carpeta del proyecto dentro de `www` queda disponible automáticamente como `http://bienestar-prestamos.test` (menú → Apache → «Auto virtual hosts»). Ajuste `APP_URL` a esa dirección.

### 4.2 Con el servidor integrado de PHP

```
php artisan serve --port=8000
```
Abra `http://127.0.0.1:8000`. La ventana debe quedar abierta mientras se use el aplicativo.

### 4.3 Acceso desde otros equipos de la red local (opcional)

```
php artisan serve --host=0.0.0.0 --port=8000
```
Los demás equipos entran a `http://IP-DEL-SERVIDOR:8000` (ajuste `APP_URL` a esa dirección y permita el puerto 8000 en el Firewall de Windows solo para la red interna). **Riesgos:** es un servidor pensado para desarrollo (un solo proceso, sin HTTPS: las contraseñas y los datos personales viajan sin cifrar por la red), se detiene al cerrar la ventana o cerrar sesión en el equipo, y cualquiera en esa red que llegue al puerto verá la pantalla de ingreso. Para uso estable con varias personas es preferible el servidor web de la pila (Apache/Nginx/IIS) con HTTPS, y restringir el acceso a la red institucional.

### 4.4 Lista de comprobación

Marque cada punto antes de entregar:

- [ ] La pantalla de ingreso carga con el logo y los estilos del Politécnico.
- [ ] El administrador principal entra con su cédula y la contraseña que definió, y ve el menú completo (incluye Usuarios y Auditoría).
- [ ] «Mi contraseña» permite cambiarla.
- [ ] Empleados → Nuevo: se crea un empleado de prueba **y luego se elimina** (o use un empleado real si ya van a cargarse).
- [ ] Modalidades muestra las 8 modalidades con sus cuantías.
- [ ] Quincenas abre sin error (aún no habrá cuotas). Después de cargar préstamos, el informe de la quincena se descarga.
- [ ] `storage\logs\laravel.log` no muestra errores nuevos.
- [ ] Se ejecutó un respaldo con `scripts\respaldo-bd.bat` y generó el archivo `.sql` (sección 6).

## 5. Configuración inicial en la aplicación

Con la sesión del administrador principal. El orden importa: primero los parámetros, después las personas y los préstamos.

1. **Usuarios.** Menú Usuarios: cree las cuentas del personal de Bienestar (cada una con su rol y permisos; los exclusivos del administrador principal no se pueden delegar).
2. **Salario mínimo (SMMLV).** Menú «Salario mínimo (SMMLV)»: registre el SMMLV del año en curso. Sin él no se calculan cuantías ni tramos.
3. **Modalidades → IPC.** En cada modalidad, registre la tasa (IPC del año anterior a la solicitud) del año. La tasa de vivienda se topa automáticamente en 5 % (Acuerdo 11/2023). Revise también las cuantías por tramo.
4. **Tasa de vehículo.** Verifique los puntos adicionales sobre el IPC (cargados por el comando).
5. **Presupuesto.** Menú Presupuesto: cree el presupuesto anual por modalidad.
6. **DTF.** Menú DTF: registre el DTF vigente (se usa en la liquidación por retiro).
7. **Seguros.** Menú Seguros: revise las primas del año (vida de deudores, incendio y terremoto) y ajústelas con las de la póliza vigente.
8. **Empleados.** Cargue la nómina con «Cargar empleados» (CSV con vista previa, todo o nada y reversión del lote). Procedimiento, columnas y plantilla: [`docs/carga-empleados.md`](carga-empleados.md).
9. **Préstamos vigentes.** Cargue la cartera con «Cargar préstamos vigentes» (las cédulas deben existir ya como empleados). Procedimiento y plantilla: [`docs/carga-prestamos-vigentes.md`](carga-prestamos-vigentes.md).
10. **Verificar.** Compare en Reportes → Cartera los totales con los del sistema anterior antes de empezar a operar.

Los archivos con datos reales se guardan en `storage\app\private\real\` o fuera del proyecto: **nunca** se suben al repositorio (ya está en `.gitignore`).

## 6. Respaldo y restauración

Los scripts están en `scripts\` y leen las credenciales de `.env`.

### 6.1 Hacer un respaldo

```
scripts\respaldo-bd.bat
```
Genera `C:\respaldos-bienestar\bienestar-<base>-AAAAMMDD-HHMMSS.sql` (la carpeta se crea sola). Otra carpeta: `scripts\respaldo-bd.bat "E:\respaldos"`. Variables opcionales (defínalas con `set` antes o como variables de entorno del sistema):

| Variable | Efecto |
|---|---|
| `RESPALDO_DIR` | Carpeta destino. **Debe estar fuera del proyecto.** |
| `MYSQLDUMP_EXE` | Ruta completa de `mysqldump.exe` si no está en el `PATH` (en Laragon: `C:\laragon\bin\mysql\<versión>\bin\mysqldump.exe`). |
| `RETENER_DIAS` | Si es mayor que 0, al terminar borra los respaldos **de este script** (`bienestar-<base>-*.sql`) con más de esos días. Por defecto `0`: no borra nada. |

El script devuelve código de salida distinto de 0 si algo falla (útil para el Programador de tareas).

Además de la base, respalde la carpeta `storage\app\private\` (documentos adjuntos a las solicitudes) copiándola a la misma ubicación segura.

### 6.2 Restaurar

```
scripts\restaurar-bd.bat "C:\respaldos-bienestar\bienestar-bienestar_prestamos-20261030-020000.sql"
```
Pide escribir `SI` para continuar. **Reemplaza las tablas de la base indicada en `.env`** por las del respaldo (la base debe existir). Haga antes un respaldo del estado actual si todavía sirve. Terminada la restauración: `php artisan config:clear`.

Para probar un respaldo sin riesgo, restáurelo en otra base creada para ese fin (cambiando temporalmente `DB_DATABASE` en una copia del proyecto), nunca sobre la base en uso.

### 6.3 Programarlo con el Programador de tareas de Windows

1. Abra «Programador de tareas» → «Crear tarea…» (no «tarea básica»).
2. General: nombre `Respaldo Bienestar Préstamos`; marque «Ejecutar tanto si el usuario inició sesión como si no» y «Ejecutar con los privilegios más altos». Use una cuenta de servicio de Informática.
3. Desencadenadores → Nuevo: Diariamente, por ejemplo a las 02:00.
4. Acciones → Nueva: «Iniciar un programa».
   - Programa o script: `C:\ruta\al\proyecto\scripts\respaldo-bd.bat`
   - Iniciar en: `C:\ruta\al\proyecto\scripts`
5. Configuración: marque «Si la tarea falla, reiniciar cada 10 minutos».
6. Pruébela con clic derecho → «Ejecutar» y confirme que aparece el `.sql` nuevo.

Si `mysqldump` no está en el `PATH` del sistema, defina `MYSQLDUMP_EXE` como variable de entorno del sistema (Panel de control → Sistema → Configuración avanzada → Variables de entorno).

### 6.4 Dónde guardar los respaldos

**Un respaldo contiene todos los datos personales** (cédulas, nombres, salarios, préstamos). Guárdelo en una carpeta con permisos solo para Informática y el responsable de Bienestar (clic derecho → Propiedades → Seguridad), nunca en una carpeta compartida abierta, en un correo ni en un servicio público en la nube. Conserve copias en un segundo equipo o unidad, con la misma restricción, y borre las copias antiguas con un criterio definido (`RETENER_DIAS`).

## 7. Operación

### 7.1 Iniciar y detener
Con la pila web (Laragon/Apache), el aplicativo está disponible mientras el servicio corra. Con `php artisan serve`, mientras la ventana siga abierta (Ctrl+C la detiene).

### 7.2 Registros
`storage\logs\laravel.log`. Revíselo si algo falla; no contiene contraseñas.

### 7.3 Auditoría
El administrador principal consulta en el aplicativo el registro de las acciones de cada usuario.

### 7.4 Quincena automática (opcional)
Está apagada por defecto. Solo si Bienestar Social lo decide: `QUINCENA_PROGRAMADOR_ACTIVO=true` y una tarea programada de Windows que ejecute cada minuto `php artisan schedule:run` en la carpeta del proyecto. Antes, lea `docs/quincena-automatica.md`.

## 8. Actualizar a una versión nueva

1. Haga un respaldo: `scripts\respaldo-bd.bat`.
2. Obtenga el código nuevo: `git pull` (o reemplace la carpeta del proyecto conservando `.env` y `storage\`).
3. Ejecute en este orden:
```
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:clear
php artisan view:clear
php artisan app:preparar-instalacion
```
4. Si la actualización cambió la interfaz (hay cambios en `resources\`), repita `npm ci` y `npm run build`.
5. Repita la lista de comprobación 4.4, abreviada: ingresar, abrir un empleado y abrir Quincenas.

`app:preparar-instalacion` es idempotente: sirve para incorporar catálogos nuevos de la versión y no toca el administrador existente. **No** use `php artisan route:cache` ni `php artisan optimize` en Windows: fallan en esta instalación y no son necesarios.

## 9. Seguridad y datos personales

- **Contraseñas.** El administrador principal elige la suya al instalar. Cada persona cambia la suya al primer ingreso desde «Mi contraseña». Si alguien deja la institución, deshabilite su cuenta en Usuarios (no la elimine: se conserva la auditoría).
- **Quién tiene acceso.** Pocas cuentas, con el permiso mínimo que necesitan. Usuarios y Auditoría son solo del administrador principal. Revise la lista de cuentas habilitadas cada trimestre.
- **Equipo.** Acceso físico y de sesión restringido; disco cifrado (BitLocker) si es posible; actualizaciones de Windows al día; contraseña del usuario de la base de datos distinta de las de la aplicación; archivo `.env` legible solo por Informática (contiene la clave de la aplicación y de la base).
- **Datos reales fuera del repositorio.** No suba a Git `storage\app\private\real\`, `docs\practica\real\`, `.env`, respaldos ni archivos de carga: ya están ignorados; confirme con `git status` antes de cada `git push`. En el equipo de producción no debe hacerse `git push` alguno.
- **No usar en producción:** `datos:convertir-q` (herramienta temporal de migración, `docs/conversion-q.md`), `practica:reiniciar`, `db:seed --class=PracticaSeeder`, `DemoSeeder`, `PlanPruebasSeeder`, `DemoLimpiezaSeeder`, `migrate:fresh` y `db:wipe`. Los de práctica ya se niegan a correr con `APP_ENV=production` y `PRACTICA_HABILITADA=false`, pero `migrate:fresh` **borra toda la base** sin preguntar.
- **Cumplimiento.** Los respaldos y las cargas contienen datos personales de empleados: trátelos conforme a la política institucional de protección de datos personales (Ley 1581 de 2012).

## 10. Solución de problemas

| Síntoma | Causa y solución |
|---|---|
| `'php' no se reconoce como un comando` | PHP no está en el `PATH`. En Laragon use su «Terminal»; en otra pila agregue la carpeta de `php.exe` al `PATH` de Windows y abra una terminal nueva. |
| `'composer'` / `'mysql'` / `'npm'` no se reconoce | Igual: agregue su carpeta al `PATH` y abra una terminal nueva. |
| `No application encryption key has been specified` | Falta la llave: `php artisan key:generate` y luego `php artisan config:clear`. Si ya había datos, **no la regenere** (se pierden las sesiones y lo cifrado). |
| `SQLSTATE[HY000] [1045] Access denied` | Usuario o contraseña de `DB_USERNAME`/`DB_PASSWORD` incorrectos. Corrija `.env` y `php artisan config:clear`. |
| `SQLSTATE[HY000] [1049] Unknown database` | La base no existe: créela (2.5) o corrija `DB_DATABASE`. |
| `SQLSTATE[HY000] [2002]` (conexión rechazada) | MariaDB no está corriendo o el puerto es otro. Inicie el servicio y revise `DB_HOST`/`DB_PORT`. |
| La base conecta en la terminal pero no en el navegador | Config en caché antigua: `php artisan config:clear`. |
| `Unable to locate file in Vite manifest` | Falta compilar la interfaz (2.4): `npm ci` y `npm run build`, o copie `public\build`. |
| La pantalla sale sin estilos | Mismo caso, o el servidor web no apunta a `public/`. |
| `Failed to listen on 127.0.0.1:8000 (reason: ...)` / puerto ocupado | Otro programa usa el puerto 8000. Use otro: `php artisan serve --port=8080` (y ajuste `APP_URL`). Para ver quién lo usa: `netstat -ano \| findstr :8000`. |
| Error «419 Page Expired» | Sesión vencida o `APP_URL` distinto de la dirección con la que se entra. Recargue la página e inicie sesión de nuevo; corrija `APP_URL`. |
| `Class "..." not found` tras actualizar | `composer install --no-dev --optimize-autoloader` y `php artisan config:clear`. |
| No sale el correo al Comité | `MAIL_MAILER=log` (solo escribe en el log) o datos SMTP erróneos. Revise `.env` y `storage\logs\laravel.log`. |
| `app:preparar-instalacion` se «aborta» sin pedir la contraseña | Se ejecutó con una tubería o desde un programador. Ejecútelo escribiendo directamente en la terminal. |
| Se olvidó la contraseña del administrador principal | Con la sesión de otro administrador no se puede restablecer (Usuarios es solo del principal). Informática debe restablecerla con: `php artisan tinker` y `App\Models\Usuario::find('CEDULA')->update(['contrasena_hash' => 'NuevaClave1']);` (se cifra sola). Cámbiela de inmediato desde «Mi contraseña». |
| `respaldo-bd.bat`: «no se pudo ejecutar mysqldump» | Defina `MYSQLDUMP_EXE` con la ruta completa. |
| `respaldo-bd.bat`: `Access denied` | Revise `DB_USERNAME`/`DB_PASSWORD` en `.env`. Una contraseña con `%` o `"` puede requerir una sin esos caracteres. |

## 11. Instalación sin Internet

Si el equipo de destino no tiene Internet, el equipo desarrollador entrega un **paquete** ya preparado. Se genera (en un equipo con Internet, con PHP, Composer y Node.js) con:

```
scripts\empaquetar-entrega.bat
```

Crea la carpeta `..\bienestar-entrega-AAAAMMDD` (fuera del repositorio; se detiene si ya existe, no borra nada), compila la interfaz, instala `vendor` con `--no-dev --optimize-autoloader` y verifica el resultado. Falla con un aviso claro si falta `composer`, `npm` o el build.

**Qué contiene:** el código de la aplicación, `vendor` ya instalado, `public\build` ya compilado, `.env.example`, `LEEME.txt`, esta guía y la documentación de operación en `docs\`, y los scripts de respaldo y restauración en `scripts\`.
**Qué no contiene:** `.git`, `.env`, `storage\app\private` (documentos y datos reales), pruebas, `node_modules`, ningún `.csv` ni `.xlsx`, ni configuración de desarrollo, ni la herramienta temporal `datos:convertir-q` (`ConvertirQCommand`, `ConversionQService`, `config\conversion_q.php` y `docs\conversion-q.md`): `php artisan list` del paquete no la muestra. Las cargas de empleados y de préstamos vigentes no se ven afectadas (el lector de CSV que usan es compartido y sí se incluye). El script falla si alguno de esos archivos, un `.env`, un `.csv`, un `.xlsx` o una carpeta `real\` aparece en el paquete.

### Cómo instalarlo

1. Copie la carpeta completa del paquete al equipo de destino (memoria USB o unidad de red), por ejemplo a `D:\laragon\www\bienestar-prestamos`.
2. En el equipo de destino debe haber, instalados de antemano, **PHP 8.3 o superior con sus extensiones y MariaDB** (sección 1). No hacen falta Composer, Git ni Node.js.
3. Siga la sección 2 **omitiendo** 2.1 (obtener el código), 2.2 (`composer install`) y 2.4 (`npm`): ya están hechos. Es decir: `copy .env.example .env` y ajustar los valores (2.3), `php artisan key:generate`, crear la base de datos (2.5), `php artisan migrate --force` (2.6).
4. Siga las secciones 3 a 6 igual que en la instalación normal (`php artisan app:preparar-instalacion`, arranque, configuración, respaldos).

### Qué cambia respecto a la instalación normal

| Instalación normal | Con el paquete |
|---|---|
| `git clone` / `git pull` | Copiar la carpeta |
| `composer install --no-dev --optimize-autoloader` | No se ejecuta (ya está `vendor`) |
| `npm ci` y `npm run build` | No se ejecutan (ya está `public\build`) |
| Actualizar: `git pull` + `composer install` + `migrate` | Recibir un paquete nuevo, copiarlo **sobre la carpeta instalada conservando `.env` y `storage\`**, y ejecutar `php artisan migrate --force`, `php artisan config:clear`, `php artisan view:clear` y `php artisan app:preparar-instalacion` |

### Cómo comprobarlo

- Existen `vendor\autoload.php` y `public\build\manifest.json`.
- `php artisan --version` responde sin errores.
- La pantalla de ingreso carga con sus estilos (lista de comprobación 4.4).
- No hay `.env` en el paquete: el que cree el equipo de destino es el único.

La pantalla de ingreso enlaza además la tipografía Inter desde Google Fonts. Sin Internet esa tipografía no se descarga y el navegador usa la tipografía del sistema (Segoe UI o similar): la aplicación funciona igual, solo cambia el aspecto de las letras.

## Referencias

- `docs/arquitectura.md` — estilo arquitectónico y módulos.
- `docs/carga-empleados.md`, `docs/carga-prestamos-vigentes.md` — cargas por archivo.
- `docs/quincena-automatica.md` — proceso de la quincena y sus variables.
- `docs/reglas-negocio.md` — reglas de los Acuerdos Directivos 19/2022 y 11/2023.
