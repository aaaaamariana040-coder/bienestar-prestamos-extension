# Carga de préstamos vigentes

Procedimiento para cargar por archivo la cartera de préstamos que ya están vigentes, para practicar y empezar a operar el aplicativo sin importar nada del AS/400. Solo se carga lo que la persona digita o pega en el archivo; no se usan ni se copian nombres ni cédulas reales en los ejemplos.

Reglas: `docs/reglas-negocio.md`, sección 26. Casos de prueba: `docs/plan-pruebas.md`, sección 32.

## Quién puede

Quien tenga el permiso `prestamos.cargar_vigentes` («Cargar préstamos vigentes», grupo Préstamos y pagos). Por defecto lo tiene el Administrador y no el Funcionario. Aparece en el menú Solicitudes y préstamos, junto a Préstamos.

## Procedimiento

1. **Descargar la plantilla.** Menú «Cargar préstamos vigentes» → «Descargar plantilla». Es un CSV en UTF-8 con BOM, separado por punto y coma, que abre bien en Excel.
2. **Llenarla.** Una fila por préstamo. Las cédulas deben ser de empleados que ya existen en el aplicativo.
3. **Subir el archivo** y pulsar «Ver vista previa». La vista previa valida cada fila y **no guarda nada**. Cada fila queda en uno de tres estados:
   - **Correcta.**
   - **Con advertencia:** se puede cargar; el mensaje explica qué revisar.
   - **Con error:** bloquea la carga; el mensaje dice qué corregir.
4. **Corregir y volver a subir** hasta que no haya filas con error. El botón «Confirmar carga» solo se habilita sin errores.
5. **Confirmar la carga.** Es todo o nada: se crean todos los préstamos en una sola transacción o ninguno.
6. **Resultado.** Se muestra la cantidad creada, los préstamos (con enlace a su detalle) y el identificador del lote (`CARGA-AAAAMMDD-HHMMSS-XXXX`).
7. **Revertir un lote (opcional).** En «Cargar préstamos vigentes», la tabla «Lotes cargados» trae «Revertir lote» por cada lote. Sirve para repetir prácticas con datos limpios. Solo se puede mientras ningún préstamo del lote tenga pagos, abonos, reliquidaciones, desembolsos adicionales o cancelación; si no, la pantalla dice por qué.

## Columnas

| Columna | Obligatoria | Formato |
|---|---|---|
| `cedula` | Sí | Cédula de un empleado existente |
| `modalidad` | Sí | Nombre completo de la modalidad, como aparece en Modalidades (no distingue mayúsculas ni tildes) |
| `monto_inicial` | Sí | Formato colombiano: `3.000.000`, `3.000.000,50`, `$ 3.000.000` (máximo dos decimales) |
| `fecha_desembolso` | Sí | `AAAA-MM-DD` |
| `plazo_quincenas` | Sí | Entero; no puede superar el plazo máximo de la modalidad |
| `cuotas_pagadas` | Sí | Entero de 0 al plazo; las primeras N cuotas quedan «Pagada» |
| `tasa_anual` | No | E.A. en %, con coma o punto (`10,5`). Si viene, se usa tal cual |
| `puntos_adicionales` | No | Puntos que se suman a la tasa del año del desembolso (no se suman si viene `tasa_anual`) |
| `saldo_actual` | No | Solo para verificar: si difiere en más de 1 peso del saldo calculado, advertencia |

Máximo 1.000 filas y 2 MB por archivo.

## Plantilla de ejemplo

Las cédulas son de empleados de demostración (rango 1000000xxx). Las fechas de la plantilla descargable se arman con el mes en curso.

```csv
cedula;modalidad;monto_inicial;fecha_desembolso;plazo_quincenas;cuotas_pagadas;tasa_anual;puntos_adicionales;saldo_actual
1000000002;Educación;3.000.000;2026-10-01;24;1;;;
1000000004;Calamidad doméstica y necesidades especiales;2.000.000;2026-10-05;12;0;;;
1000000005;Educación;4.500.000,50;2026-10-10;36;1;;;
```

## Qué se crea por cada fila

- Un préstamo **Vigente**, ya desembolsado (`pendiente_desembolso = false`), con `origen = 'carga_inicial'` y el `lote_carga`.
- Un desembolso (tramo 1, 100 %) con la fecha dada.
- La tabla de cuotas con la fórmula vigente: cuota francesa quincenal, `r = (1 + E.A.)^(1/24) − 1`, con la tasa del año del desembolso para la modalidad más los puntos (o la tasa dada). Las fechas siguen el calendario (15 y fin de mes); la primera cuota usa la misma regla de cierre de novedades del desembolso real.
- Las primeras N cuotas en estado **Pagada**.

## Lo que la carga no hace

- **No reconstruye pagos históricos:** no crea filas de pago ni abonos. El **Resumen de pagos** y el Historial de pagos solo cuentan pagos registrados en el aplicativo; las cuotas marcadas como pagadas por la carga no aparecen ahí.
- **No toca el presupuesto:** no consume ni modifica el presupuesto de ninguna modalidad.
- **No aplica** reglas de capacidad de endeudamiento, antigüedad ni salario mínimo: son préstamos históricos.
- **No carga docentes ocasionales** (no son beneficiarios de crédito del Fondo).
- En las modalidades de vivienda que se desembolsan por tramos (construcción, reparaciones o mejoras) se registra un solo desembolso por el 100 %; el detalle del préstamo seguirá ofreciendo registrar el tramo 2.

## Auditoría

Quedan registradas «Carga de préstamos vigentes» (archivo, lote, cantidad) y «Reversión de carga de préstamos vigentes» (lote, cantidad). El archivo subido queda en `storage/app/private/cargas-vigentes/pendientes/` solo entre la vista previa y la confirmación; al confirmar se elimina.
