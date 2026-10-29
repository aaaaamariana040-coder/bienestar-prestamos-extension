# Conversión de los exportes Q09 y Q15 (TEMPORAL)

> **Esta herramienta es temporal.** Es una migración puntual para convertir dos exportes del AS/400 en las plantillas de «Cargar empleados» y «Cargar préstamos vigentes». **No es parte del producto**: no tiene rutas, pantallas ni permisos, y el aplicativo no lee formatos del AS/400 (docs/restricciones.md); solo lo hace este comando. Se borra cuando termine la migración (el comando `app/Console/Commands/ConvertirQCommand.php`, `app/Services/ConversionQService.php` y `config/conversion_q.php`).

## Qué hace

`php artisan datos:convertir-q` lee dos archivos CSV de `storage/app/private/real/` y escribe otros dos en la misma carpeta:

| Entrada | Salida | Se carga con |
|---|---|---|
| `q09.csv` — reporte de nómina | `empleados-q09.csv` | Empleados → Cargar empleados |
| `q15.csv` — cartera | `prestamos-q15.csv` | Solicitudes y préstamos → Cargar préstamos vigentes |

La conversión **no carga nada** en el aplicativo: solo prepara los archivos. Después se suben con las pantallas de carga, que tienen su vista previa, su confirmación todo o nada y la reversión del lote. Cargue primero los empleados y luego los préstamos.

## Entradas

Van en `storage/app/private/real/`, que está en `.gitignore` (el comando se niega a correr si la carpeta no está ignorada por git o si faltan los archivos). Aceptan separador `;` o `,` y codificación UTF-8 (con o sin BOM) o Latin-1.

- **`q09.csv`**: la fila 1 es un título (`Reporte_AS400`); los encabezados están en la fila 2: Cédula, Nombre, Programa, Cargo, Ingreso (aaaammdd), Nacimiento, Nivel, Grado, Asignación, EPS, Pensión, Centro de Costos, Sede, Jornada, Tipo Contrato. (El comando busca la fila de encabezados en las primeras 10.)
- **`q15.csv`**: encabezados en la fila 1: CEDULA_NUEVA, NOMBRE_NUEVO, CONCEPTO_NUEVO, FECHA_PRESTAVO (dd/mm/aaaa), VALOR_MONTO, TOTAL_QUINCENA, QUINCENA_NUEVA, SALDO_NUEVO, VALOR DE CUOTA, AMORTIZA, INTERN, una columna de año y TIPO. Solo se usan las siete primeras listadas.

## Salida de empleados (`empleados-q09.csv`)

Las columnas exactas de la plantilla de Cargar empleados: `cedula`, `nombre_completo`, `tipo_vinculacion`, `de_carrera`, `cargo`, `dependencia`, `salario`, `fecha_vinculacion`, `capacidad_endeudamiento` (vacía).

- `salario` = Asignación; `dependencia` = Programa; `cargo` = Cargo; `fecha_vinculacion` = Ingreso, de aaaammdd a aaaa-mm-dd (si no es una fecha real queda vacía y se cuenta).
- `tipo_vinculacion`, según el **código de Tipo Contrato** (`tipo_contrato.codigos` de `config/conversion_q.php`): `6` → `docente_ocasional`, `7` → `jubilado`, `2` → `docente`, `1` → `vinculado`. **Cuando el código y el cargo se contradicen, manda el código** (el informe cuenta cuántos casos). Si el valor no es un código configurado, se usa el **Cargo** (sin mayúsculas ni tildes): «JUBILADO» → `jubilado`; «PROFESOR» → `docente`, o `docente_ocasional` si el Tipo Contrato contiene «OCASIONAL»; cualquier otro → `vinculado`.
- `de_carrera`: sale **vacío** mientras no se confirme qué valores de Tipo Contrato significan «de carrera» y «no de carrera» (listas `carrera` y `no_carrera` de `config/conversion_q.php`). El informe lista cada valor de Tipo Contrato con su conteo y su clasificación (los códigos, con el tipo de vinculación al que llevan) y cuántos empleados quedaron en cada tipo de vinculación.
- No se incluyen y se cuentan: salario vacío o cero, cédula vacía o inválida y cédula repetida (se queda la primera).

### Evidencia del mapeo por código

- **Tipo Contrato es un código y define la vinculación.** Los códigos 6, 7, 2 y 1 corresponden a docente ocasional, jubilado, docente y vinculado; por eso manda sobre el cargo cuando se contradicen.
- **QUINCENA_NUEVA son cuotas pagadas**, no restantes: así lo muestra la verificación de saldos con la cuota francesa (ver «Salida de préstamos»).
- **La columna TIPO de la cartera (Q15) coincide con Tipo Contrato** de la nómina (Q09), lo que confirma que el código identifica el tipo de vinculación del empleado.

## Salida de préstamos (`prestamos-q15.csv`)

Las columnas exactas de la plantilla de Cargar vigentes: `cedula`, `modalidad`, `monto_inicial`, `fecha_desembolso`, `plazo_quincenas`, `cuotas_pagadas`, `tasa_anual` (deducida, ver abajo), `puntos_adicionales` (vacía), `saldo_actual`.

- `modalidad` sale del concepto (primer número de CONCEPTO_NUEVO) con el mapeo de `config/conversion_q.php`.
- `monto_inicial` = VALOR_MONTO; `fecha_desembolso` = FECHA_PRESTAVO en aaaa-mm-dd; `plazo_quincenas` = TOTAL_QUINCENA.
- `saldo_actual` = SALDO_NUEVO, para que la vista previa avise si el saldo calculado difiere.
- **`tasa_anual`** depende de `--tasa=derivada|vacia` (por defecto `derivada`; ver «Tasa deducida»). Con `vacia` la columna queda en blanco y el aplicativo usa la tasa vigente al desembolso.
- **`cuotas_pagadas`**: depende de cómo se lea QUINCENA_NUEVA. El comando lo **verifica**: para una muestra de filas (500 por defecto, `--muestra=`) calcula el saldo con la cuota francesa y la tasa del aplicativo (la del año del desembolso) y lo compara con SALDO_NUEVO bajo las dos interpretaciones, *restantes* (`pagadas = TOTAL_QUINCENA − QUINCENA_NUEVA`) y *pagadas* (`pagadas = QUINCENA_NUEVA`). Cuenta las coincidencias exactas (diferencia ≤ 1 peso) y las aproximadas (≤ 1 %), usa la interpretación con más coincidencias y, sin evidencia, usa *restantes*. Se puede forzar con `--interpretacion=restantes|pagadas`. La verificación necesita las tasas de cada año en la base: las filas sin tasa o sin saldo no cuentan.

### Tasa deducida (`--tasa=derivada`, por defecto)

Para cada préstamo el comando calcula la tasa efectiva anual con la que una cuota francesa quincenal de **SALDO_NUEVO** a las **quincenas restantes** da la **VALOR DE CUOTA** del archivo (con `r = (1 + EA)^(1/24) − 1`), resolviéndola por **bisección**:

- Si la solución queda entre **0 % y 40 % EA**, se escribe en `tasa_anual` redondeada a 2 decimales.
- Si no (cuota por encima de la que daría el 40 %, por debajo de la del 0 %, datos faltantes o que no convergen), `tasa_anual` queda vacía y se cuenta en el informe como «tasa no deducible» (con el motivo: `sin_datos`, `fuera_de_rango`, `no_converge`).
- **Vivienda:** la cuota puede incluir seguros, que inflan la tasa deducida. Si la tasa deducida de una vivienda se aparta más de 2 puntos de la del aplicativo (o del tope de vivienda de 5 % si no hay tasa del año), no se usa: queda vacía y se cuenta como `vivienda_descartada`.
- Las quincenas restantes dependen de cómo se lea QUINCENA_NUEVA (ver la interpretación, arriba). Cuando falta la tasa del año en la base y no se pueden comparar saldos, **la misma deducción decide la interpretación**: gana la lectura que da más tasas plausibles (0–40 %) y, si empatan, la más consistente dentro de cada año. `--interpretacion` la fuerza.
- El informe trae los conteos de «tasa deducida» y «tasa no deducible» y, por año del préstamo, el mínimo, la mediana y el máximo de la tasa deducida; un año con menos de 3 préstamos solo muestra su cantidad, para no identificar a nadie.

La columna VALOR DE CUOTA no es obligatoria: sin ella toda tasa queda «sin datos» y vacía.

### Mapeo de conceptos (`config/conversion_q.php`)

| Concepto | Resultado |
|---|---|
| 14 (matrícula) | Educación |
| 15 (calamidad) | Calamidad doméstica y necesidades especiales |
| 16 (vehículo) y 26 (vehículo, 2.ª vez) | Vehículo (el 26 usa los puntos de segunda solicitud al verificar) |
| 10 | Excluido: crédito de seguros (no es del Fondo) |
| 19 | Excluido: computador (no existe como modalidad) |
| 30 (vivienda) | Vivienda — compra de inmueble. **Es una aproximación**: el archivo no dice cuál de las modalidades de vivienda es y el comando no puede saberlo; revise estos préstamos antes de confirmar la carga. |
| 33 (deshipoteca) | Vivienda — cancelación total de hipoteca (deshipoteca) |
| 31 y 34 | Sin mapeo (significado desconocido) |

No van al CSV y se cuentan en el informe: conceptos excluidos, conceptos sin mapeo, modalidades del mapeo que no existan en la base, cédulas que no están entre los empleados convertidos (no están en Q09 o se omitieron) y filas con monto, fecha, plazo o cuotas inválidos.

## Cómo correrlo

1. Copie `q09.csv` y `q15.csv` a `storage/app/private/real/` (ignorada por git).
2. La base debe tener las modalidades y las tasas de los años de los préstamos (la verificación de saldos las usa).
3. `php artisan datos:convertir-q` (opciones: `--interpretacion=auto|restantes|pagadas`, `--muestra=500`, `--tasa=derivada|vacia`).
4. Lea el informe, ajuste `config/conversion_q.php` si hace falta y repita.
5. Suba `empleados-q09.csv` a «Cargar empleados» y, ya cargados los empleados, `prestamos-q15.csv` a «Cargar préstamos vigentes»; revise cada vista previa antes de confirmar.

## Informe y privacidad

El informe de pantalla trae **solo agregados**: empleados convertidos y omitidos con motivo, préstamos convertidos, excluidos y sin mapeo por concepto, cédulas de la cartera sin empleado (cantidad), resultado de la verificación de saldos, conteos de tasa deducida y no deducible con el resumen por año, valores de Tipo Contrato con conteos y modalidades de vivienda. **Nunca** nombres, cédulas, salarios ni montos individuales. Nada se escribe en `laravel.log`, ningún mensaje de error lleva datos del archivo y el comando no sube nada a git. Las pruebas automáticas usan solo CSV ficticios (cédulas 9000000xxx, «Ejemplo …») en una carpeta temporal.
