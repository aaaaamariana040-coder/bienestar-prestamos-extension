# Carga de empleados por archivo

Procedimiento para cargar empleados desde un archivo CSV, con el mismo esquema de «Cargar préstamos vigentes»: plantilla descargable, vista previa que no guarda nada, confirmación todo o nada y reversión del lote.

Reglas: `docs/reglas-negocio.md`, sección 27. Casos de prueba: `docs/plan-pruebas.md`, sección 34.

## Quién puede

Quien tenga el permiso `empleados.cargar_masivo` («Cargar empleados por archivo», grupo Empleados; exige `empleados.ver`). Por defecto lo tiene el Administrador y no el Funcionario. Aparece en el menú Empleados → «Cargar empleados» y como botón en la lista de empleados. Usuarios y Auditoría siguen siendo solo del administrador principal.

## Procedimiento

1. **Descargar la plantilla** (Empleados → Cargar empleados → «Descargar plantilla»). CSV en UTF-8 con BOM, separado por punto y coma.
2. **Llenarla.** Una fila por empleado. El archivo también se acepta con coma como separador, con o sin BOM.
3. **Subir el archivo** y pulsar «Ver vista previa»: valida cada fila y **no guarda nada**. Cada fila queda en uno de cuatro estados:
   - **Correcta.**
   - **Con advertencia:** se puede cargar; el mensaje explica qué revisar.
   - **Con error:** bloquea toda la carga; el mensaje dice qué corregir.
   - **Omitida (ya existe):** la cédula ya está en la base. No es error: la fila no se toca ni se actualiza.
4. **Confirmar la carga** (solo si no hay errores y queda al menos un empleado nuevo). Es todo o nada: se crean todos los nuevos en una sola transacción o ninguno. Con errores, el botón está deshabilitado y el archivo ni siquiera se guarda en el disco.
5. **Cancelar** (opcional): borra del disco el archivo pendiente y no guarda nada.
6. **Resultado:** cantidad creada, cantidad omitida e identificador del lote (`CARGA-AAAAMMDD-HHMMSS-XXXX`).
7. **Revertir un lote** (opcional): en «Lotes cargados», «Revertir lote». Solo si **ninguno** de los empleados del lote tiene solicitudes, préstamos o cuenta de usuario; si alguno los tiene, no se revierte nada y la pantalla dice cuáles empleados lo impiden.

## Columnas

Salen del formulario «Nuevo empleado»; las reglas de validación son las mismas (se leen de `StoreEmpleadoRequest`, no se copian).

| Columna | Obligatoria | Formato |
|---|---|---|
| `cedula` | Sí | Solo números, máximo 10 dígitos, sin puntos |
| `nombre_completo` | Sí | Letras (con tildes y ñ), espacios, apóstrofos, guiones y puntos; sin números; máximo 150 |
| `tipo_vinculacion` | Sí | `vinculado`, `docente`, `docente_ocasional` o `jubilado` (también se acepta la etiqueta, p. ej. «Docente ocasional») |
| `de_carrera` | No | `si` o `no` (vacío es no) |
| `cargo` | No | Texto, máximo 100 |
| `dependencia` | No | Texto, máximo 100 |
| `salario` | Sí | Formato colombiano: `3.200.000`, `4.850.000,50`, `$ 3.200.000`; no negativo |
| `fecha_vinculacion` | No | Fecha de ingreso: `dd/mm/aaaa` o `aaaa-mm-dd` |
| `capacidad_endeudamiento` | No | Monto; si se deja vacío se sugiere el 50 % del salario, como en el formulario |

El estado no es columna: todo empleado nuevo entra **Activo**, como en el formulario manual. Máximo 1.000 filas y 2 MB por archivo.

## Plantilla de ejemplo

Cédulas y nombres claramente ficticios.

```csv
cedula;nombre_completo;tipo_vinculacion;de_carrera;cargo;dependencia;salario;fecha_vinculacion;capacidad_endeudamiento
9000000001;Ejemplo Uno;vinculado;si;Auxiliar administrativo;Dependencia de ejemplo;3.200.000;15/03/2018;
9000000002;Ejemplo Dos;docente;no;Docente de cátedra;Facultad de ejemplo;4.850.000,50;2020-08-03;
9000000003;Ejemplo Tres;jubilado;si;;;2.400.000;;1.200.000
```

## Errores y advertencias

**Errores** (bloquean): cédula vacía o inválida; cédula repetida dentro del archivo; campo obligatorio vacío (nombre, tipo de vinculación, salario); tipo de vinculación inexistente; fecha inválida; salario inválido; «de carrera» distinto de sí o no; nombre con caracteres no permitidos.

**Advertencias** (no bloquean, reglas que el aplicativo ya tiene para los créditos): docente ocasional (no es beneficiario de crédito del Fondo); salario igual o menor al SMMLV del año (no se puede descontar por nómina); sin fecha de ingreso (no se podrá verificar la antigüedad que exigen vivienda y vehículo); fecha de ingreso futura.

## Auditoría

«Carga de empleados» (archivo, lote, cantidad creada y omitida) y «Reversión de carga de empleados» (lote, cantidad). **Sin cédulas, nombres ni salarios.**

## Privacidad

- **Este trabajo no necesita archivos reales.** Para pruebas y ejemplos use solo datos ficticios.
- Los archivos con datos reales nunca se versionan: `storage/app/private/real/` y `docs/practica/real/` están en `.gitignore` (hay una prueba que lo confirma). Guarde ahí los archivos reales, fuera del repositorio.
- El archivo subido queda en `storage/app/private/cargas-empleados/pendientes/` solo entre la vista previa y la confirmación; se borra al confirmar o cancelar (y no se guarda si la vista previa tiene errores).
- Ningún mensaje de excepción ni la auditoría llevan cédulas, nombres ni salarios; las cédulas solo se ven en pantalla para quien tiene el permiso.
