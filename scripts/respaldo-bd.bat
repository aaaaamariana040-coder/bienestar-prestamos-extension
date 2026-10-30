@echo off
setlocal EnableExtensions
rem ============================================================================
rem  Respaldo de la base de datos de Bienestar Prestamos (mysqldump).
rem  Lee las credenciales del archivo .env de la raiz del proyecto y guarda un
rem  archivo con fecha en el nombre, FUERA del repositorio.
rem
rem  Uso:  scripts\respaldo-bd.bat [carpeta_destino]
rem  Variables opcionales (se pueden definir antes de ejecutar):
rem    RESPALDO_DIR      carpeta destino (por defecto C:\respaldos-bienestar)
rem    MYSQLDUMP_EXE     ruta completa de mysqldump.exe si no esta en el PATH
rem    RETENER_DIAS      si es mayor que 0, borra los respaldos de este script con
rem                      mas de esos dias (por defecto 0 = no borra nada)
rem
rem  El respaldo contiene DATOS PERSONALES: la carpeta destino debe tener acceso
rem  restringido. Ver docs/guia-instalacion.md.
rem ============================================================================

set "RAIZ=%~dp0.."
set "ENV_ARCHIVO=%RAIZ%\.env"

if not exist "%ENV_ARCHIVO%" (
    echo ERROR: no se encontro el archivo %ENV_ARCHIVO%
    exit /b 1
)

set "DB_HOST=127.0.0.1"
set "DB_PORT=3306"
set "DB_DATABASE="
set "DB_USERNAME="
set "DB_PASSWORD="

for /f "usebackq tokens=1,* delims==" %%A in ("%ENV_ARCHIVO%") do (
    if /i "%%A"=="DB_HOST" set "DB_HOST=%%B"
    if /i "%%A"=="DB_PORT" set "DB_PORT=%%B"
    if /i "%%A"=="DB_DATABASE" set "DB_DATABASE=%%B"
    if /i "%%A"=="DB_USERNAME" set "DB_USERNAME=%%B"
    if /i "%%A"=="DB_PASSWORD" set "DB_PASSWORD=%%B"
)

if defined DB_HOST set "DB_HOST=%DB_HOST:"=%"
if defined DB_PORT set "DB_PORT=%DB_PORT:"=%"
if defined DB_DATABASE set "DB_DATABASE=%DB_DATABASE:"=%"
if defined DB_USERNAME set "DB_USERNAME=%DB_USERNAME:"=%"
if defined DB_PASSWORD set "DB_PASSWORD=%DB_PASSWORD:"=%"

if "%DB_DATABASE%"=="" (
    echo ERROR: DB_DATABASE no esta definido en %ENV_ARCHIVO%
    exit /b 1
)
if "%DB_USERNAME%"=="" (
    echo ERROR: DB_USERNAME no esta definido en %ENV_ARCHIVO%
    exit /b 1
)

if "%MYSQLDUMP_EXE%"=="" set "MYSQLDUMP_EXE=mysqldump"
if "%RESPALDO_DIR%"=="" set "RESPALDO_DIR=C:\respaldos-bienestar"
if not "%~1"=="" set "RESPALDO_DIR=%~1"
if "%RETENER_DIAS%"=="" set "RETENER_DIAS=0"

"%MYSQLDUMP_EXE%" --version >nul 2>&1
if errorlevel 1 (
    echo ERROR: no se pudo ejecutar mysqldump. Agregue su carpeta bin al PATH o defina MYSQLDUMP_EXE con la ruta completa.
    exit /b 1
)

if not exist "%RESPALDO_DIR%" mkdir "%RESPALDO_DIR%"
if errorlevel 1 (
    echo ERROR: no se pudo crear la carpeta %RESPALDO_DIR%
    exit /b 1
)

for /f %%F in ('powershell -NoProfile -Command "Get-Date -Format yyyyMMdd-HHmmss"') do set "FECHA=%%F"
if "%FECHA%"=="" (
    echo ERROR: no se pudo obtener la fecha.
    exit /b 1
)

set "DESTINO=%RESPALDO_DIR%\bienestar-%DB_DATABASE%-%FECHA%.sql"

rem La contrasena va por variable de entorno para que no aparezca en la linea de comandos.
set "MYSQL_PWD=%DB_PASSWORD%"

"%MYSQLDUMP_EXE%" --host="%DB_HOST%" --port="%DB_PORT%" --user="%DB_USERNAME%" --single-transaction --routines --default-character-set=utf8mb4 --result-file="%DESTINO%" "%DB_DATABASE%"
set "RESULTADO=%ERRORLEVEL%"
set "MYSQL_PWD="

if not "%RESULTADO%"=="0" (
    echo ERROR: mysqldump fallo con codigo %RESULTADO%. El respaldo no es valido.
    if exist "%DESTINO%" del "%DESTINO%"
    exit /b %RESULTADO%
)

echo Respaldo creado: %DESTINO%
echo Recuerde: contiene datos personales. Guardelo en una ubicacion con acceso restringido.

if %RETENER_DIAS% GTR 0 (
    forfiles /p "%RESPALDO_DIR%" /m "bienestar-%DB_DATABASE%-*.sql" /d -%RETENER_DIAS% /c "cmd /c del @path" >nul 2>&1
)

exit /b 0
