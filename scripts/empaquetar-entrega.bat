@echo off
setlocal EnableExtensions
rem ============================================================================
rem  Genera el paquete de entrega de Bienestar Prestamos, listo para instalar en
rem  un equipo SIN Internet (vendor --no-dev ya instalado y public\build ya
rem  compilado). Se ejecuta en el equipo de desarrollo, que si necesita Internet
rem  (o la cache de Composer y de npm) para preparar el paquete.
rem
rem  Uso:  scripts\empaquetar-entrega.bat [carpeta_destino]
rem  Por defecto crea ..\bienestar-entrega-AAAAMMDD junto a la carpeta del
rem  proyecto (fuera del repositorio). Si la carpeta ya existe, se detiene: no
rem  borra ni sobrescribe nada.
rem
rem  NO incluye: .git, .env, storage\app\private (datos reales y cargas), pruebas,
rem  node_modules, archivos de configuracion de desarrollo, docs de desarrollo ni la
rem  herramienta temporal datos:convertir-q (ConvertirQCommand, ConversionQService,
rem  config\conversion_q.php, docs\conversion-q.md).
rem ============================================================================

for %%I in ("%~dp0..") do set "RAIZ=%%~fI"

for /f %%F in ('powershell -NoProfile -Command "Get-Date -Format yyyyMMdd"') do set "FECHA=%%F"
if "%FECHA%"=="" (
    echo ERROR: no se pudo obtener la fecha.
    exit /b 1
)

if "%~1"=="" (
    for %%I in ("%RAIZ%\..") do set "PADRE=%%~fI"
    set "DESTINO=%PADRE%\bienestar-entrega-%FECHA%"
) else (
    set "DESTINO=%~f1"
)

rem --- Herramientas necesarias ------------------------------------------------
where php >nul 2>&1
if errorlevel 1 (
    echo ERROR: no se encontro php en el PATH.
    exit /b 1
)
where composer >nul 2>&1
if errorlevel 1 (
    echo ERROR: no se encontro composer en el PATH. Instalelo desde https://getcomposer.org y abra una terminal nueva.
    exit /b 1
)
where npm >nul 2>&1
if errorlevel 1 (
    echo ERROR: no se encontro npm en el PATH. Instale Node.js y abra una terminal nueva; hace falta para compilar la interfaz.
    exit /b 1
)
where robocopy >nul 2>&1
if errorlevel 1 (
    echo ERROR: no se encontro robocopy.
    exit /b 1
)

if exist "%DESTINO%" (
    echo ERROR: la carpeta %DESTINO% ya existe. Cambiele el nombre, borrela usted mismo o indique otra: scripts\empaquetar-entrega.bat "otra\carpeta"
    exit /b 1
)

rem --- Compilar la interfaz ---------------------------------------------------
echo [1/5] Compilando la interfaz (npm)...
pushd "%RAIZ%"
if not exist "%RAIZ%\node_modules" (
    call npm ci
    if errorlevel 1 (
        popd
        echo ERROR: npm ci fallo. Se necesita Internet o la cache de npm.
        exit /b 1
    )
)
call npm run build
if errorlevel 1 (
    popd
    echo ERROR: npm run build fallo. No se genera el paquete.
    exit /b 1
)
popd
if not exist "%RAIZ%\public\build\manifest.json" (
    echo ERROR: falta public\build\manifest.json: la interfaz no se compilo. No se genera el paquete.
    exit /b 1
)

rem --- Copiar el codigo -------------------------------------------------------
echo [2/5] Copiando el codigo a %DESTINO% ...
mkdir "%DESTINO%"
if errorlevel 1 (
    echo ERROR: no se pudo crear %DESTINO%
    exit /b 1
)

robocopy "%RAIZ%" "%DESTINO%" /E /NFL /NDL /NJH /NJS /NP ^
    /XD "%RAIZ%\.git" "%RAIZ%\.github" "%RAIZ%\.claude" "%RAIZ%\.ai" "%RAIZ%\.idea" "%RAIZ%\.vscode" ^
        "%RAIZ%\node_modules" "%RAIZ%\vendor" "%RAIZ%\tests" "%RAIZ%\storage" "%RAIZ%\bootstrap\cache" ^
        "%RAIZ%\docs" "%RAIZ%\scripts" ^
    /XF .env .env.* .gitignore .gitattributes .editorconfig .npmrc .mcp.json boost.json AGENTS.md CLAUDE.md README.md ^
        phpunit.xml .phpunit.result.cache package.json package-lock.json vite.config.js *.log *.csv *.xlsx hot fonts-manifest.dev.json ^
        ConvertirQCommand.php ConversionQService.php conversion_q.php
if errorlevel 8 (
    echo ERROR: robocopy fallo al copiar el codigo.
    exit /b 1
)

rem .env.example se necesita para crear el .env en el equipo de destino.
copy /y "%RAIZ%\.env.example" "%DESTINO%\.env.example" >nul

rem Documentacion necesaria para instalar y operar.
robocopy "%RAIZ%\docs" "%DESTINO%\docs" guia-instalacion.md carga-empleados.md carga-prestamos-vigentes.md quincena-automatica.md reglas-negocio.md arquitectura.md /NFL /NDL /NJH /NJS /NP
if errorlevel 8 (
    echo ERROR: robocopy fallo al copiar docs.
    exit /b 1
)

rem Scripts de respaldo y restauracion.
robocopy "%RAIZ%\scripts" "%DESTINO%\scripts" respaldo-bd.bat restaurar-bd.bat /NFL /NDL /NJH /NJS /NP
if errorlevel 8 (
    echo ERROR: robocopy fallo al copiar scripts.
    exit /b 1
)

rem Estructura vacia de storage y bootstrap\cache (sin datos ni cache).
for %%D in ("storage\app\private" "storage\app\public" "storage\framework\cache\data" "storage\framework\sessions" "storage\framework\views" "storage\logs" "bootstrap\cache") do mkdir "%DESTINO%\%%~D" 2>nul

rem --- Dependencias de PHP sin las de desarrollo ------------------------------
echo [3/5] Instalando vendor con --no-dev --optimize-autoloader...
pushd "%DESTINO%"
call composer install --no-dev --optimize-autoloader --no-interaction
if errorlevel 1 (
    popd
    echo ERROR: composer install fallo. Se necesita Internet o la cache de Composer. El paquete NO es valido.
    exit /b 1
)
popd

rem --- LEEME.txt --------------------------------------------------------------
echo [4/5] Escribiendo LEEME.txt...
(
    echo BIENESTAR PRESTAMOS - paquete de entrega
    echo.
    echo Este paquete ya trae las dependencias de PHP ^(vendor^) y la interfaz compilada
    echo ^(public\build^). NO se ejecuta "composer install" ni "npm" en el equipo de destino
    echo y no se necesita Internet para instalarlo.
    echo.
    echo Siga docs\guia-instalacion.md, seccion "Instalacion sin Internet".
    echo Resumen: copiar la carpeta, crear .env desde .env.example, php artisan key:generate,
    echo crear la base de datos, php artisan migrate --force y
    echo php artisan app:preparar-instalacion.
    echo.
    echo Requisitos en el equipo de destino: PHP 8.3 o superior con sus extensiones y MariaDB.
    echo El paquete NO contiene datos de la institucion ni archivo .env.
) > "%DESTINO%\LEEME.txt"

rem --- Verificacion del contenido ---------------------------------------------
echo [5/5] Verificando que el paquete no contiene datos ni secretos...
set "PROBLEMAS=0"

for /f "delims=" %%P in ('dir /s /b /a "%DESTINO%\.env" 2^>nul') do (
    echo   SOBRA: %%P
    set "PROBLEMAS=1"
)
for /f "delims=" %%P in ('dir /s /b /a:d "%DESTINO%\real" 2^>nul') do (
    echo   SOBRA: %%P
    set "PROBLEMAS=1"
)
for /f "delims=" %%P in ('dir /s /b "%DESTINO%\*.csv" "%DESTINO%\*.xlsx" 2^>nul') do (
    echo   SOBRA: %%P
    set "PROBLEMAS=1"
)
for %%N in (app\Console\Commands\ConvertirQCommand.php app\Services\ConversionQService.php config\conversion_q.php docs\conversion-q.md) do (
    if exist "%DESTINO%\%%N" (
        echo   SOBRA ^(herramienta temporal datos:convertir-q^): %%N
        set "PROBLEMAS=1"
    )
)
if not exist "%DESTINO%\vendor\autoload.php" (
    echo   FALTA: vendor\autoload.php
    set "PROBLEMAS=1"
)
if not exist "%DESTINO%\public\build\manifest.json" (
    echo   FALTA: public\build\manifest.json
    set "PROBLEMAS=1"
)
if not exist "%DESTINO%\docs\guia-instalacion.md" (
    echo   FALTA: docs\guia-instalacion.md
    set "PROBLEMAS=1"
)

if "%PROBLEMAS%"=="1" (
    echo ERROR: el paquete no paso la verificacion. No lo entregue.
    exit /b 1
)

echo.
echo Paquete listo: %DESTINO%
echo Copie esa carpeta completa al equipo de destino ^(memoria USB, unidad de red^).
exit /b 0
