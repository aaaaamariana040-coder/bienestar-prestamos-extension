@echo off
setlocal EnableExtensions
rem ============================================================================
rem  Restaura un respaldo (.sql) hecho con scripts\respaldo-bd.bat sobre la base
rem  de datos que indica el archivo .env. REEMPLAZA las tablas existentes.
rem
rem  Uso:  scripts\restaurar-bd.bat "C:\respaldos-bienestar\bienestar-XXXX.sql"
rem  Variable opcional:  MYSQL_EXE  ruta completa de mysql.exe si no esta en el PATH
rem
rem  La base debe existir (puede estar vacia). Pide confirmacion escribiendo SI.
rem ============================================================================

set "RAIZ=%~dp0.."
set "ENV_ARCHIVO=%RAIZ%\.env"
set "ARCHIVO=%~1"

if "%ARCHIVO%"=="" (
    echo Uso: scripts\restaurar-bd.bat "ruta\del\respaldo.sql"
    exit /b 1
)
if not exist "%ARCHIVO%" (
    echo ERROR: no existe el archivo %ARCHIVO%
    exit /b 1
)
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

if "%MYSQL_EXE%"=="" set "MYSQL_EXE=mysql"

"%MYSQL_EXE%" --version >nul 2>&1
if errorlevel 1 (
    echo ERROR: no se pudo ejecutar mysql. Agregue su carpeta bin al PATH o defina MYSQL_EXE con la ruta completa.
    exit /b 1
)

echo Se va a restaurar:   %ARCHIVO%
echo Sobre la base:       %DB_DATABASE% en %DB_HOST%:%DB_PORT%
echo ATENCION: las tablas actuales de esa base se reemplazan por las del respaldo.
set "CONFIRMA="
set /p "CONFIRMA=Para continuar escriba SI y pulse Enter: "
if /i not "%CONFIRMA%"=="SI" (
    echo Cancelado: no se hizo ningun cambio.
    exit /b 1
)

set "MYSQL_PWD=%DB_PASSWORD%"
"%MYSQL_EXE%" --host="%DB_HOST%" --port="%DB_PORT%" --user="%DB_USERNAME%" --default-character-set=utf8mb4 "%DB_DATABASE%" < "%ARCHIVO%"
set "RESULTADO=%ERRORLEVEL%"
set "MYSQL_PWD="

if not "%RESULTADO%"=="0" (
    echo ERROR: la restauracion fallo con codigo %RESULTADO%.
    exit /b %RESULTADO%
)

echo Restauracion completa. Ejecute: php artisan config:clear
exit /b 0
