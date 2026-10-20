# Quincena automática

Esqueleto del proceso que, cada quincena, identifica las cuotas que vencen y, según la decisión de Bienestar Social, las deja listas para confirmar o las marca como pagadas. Está construido para que **todo lo que todavía no ha respondido Bienestar sea configurable** y la opción segura sea la predeterminada: nada se paga solo y nada corre solo hasta que alguien lo active.

Las respuestas de Bienestar se esperan el **jueves 15 de octubre de 2026**.

## Qué existe

| Pieza | Dónde | Qué hace |
|---|---|---|
| Configuración | `config/quincena.php` | Las cuatro decisiones abiertas, con valores por defecto seguros. |
| Servicio | `App\Services\QuincenaService::procesar($anio, $quincena, $simulacion = true)` | Calcula las cuotas de la quincena; en real y modo `automatico` crea los pagos; con `confirmar()` los paga una persona. |
| Comando | `php artisan quincena:procesar {--anio=} {--quincena=} {--ejecutar}` | Simula por defecto; con `--ejecutar` corre el proceso real. |
| Programador | `routes/console.php` | Registra el comando solo si `quincena.programador_activo` es `true`. |
| Registro | tabla `procesos_quincena`, modelo `ProcesoQuincena` | Un proceso real por año y quincena (restricción única). |
| Confirmación | `QuincenaService::confirmar()`, `QuincenaController`, `ProcesoQuincenaPolicy`, vistas `quincenas/*` | Pantalla de vista previa y confirmación de la quincena (modo `confirmar`). Ver «Confirmación de la quincena». |
| Trazabilidad | `pagos.id_proceso_quincena` (FK nula) | Enlaza cada pago nacido de un proceso con su proceso. Los pagos manuales y los anteriores quedan en nulo. |

Reutiliza lo que ya existía, sin duplicar lógica de pagos:

- `CalendarioQuincenalService` para las quincenas (1 a 24) y sus fechas de inicio y corte.
- `PagoService::registrarPagoCuota`, que ahora acepta un `OrigenPago` opcional (Manual por defecto). El proceso automático lo llama con `OrigenPago::Automatico`.
- `Usuario::sistema()` (cédula `0000000000`) como autor de los pagos automáticos y de la auditoría.
- `pagos.origen` (Manual / Automático).

## Qué quedó configurable

Todas las claves se pueden fijar en `.env` o en `config/quincena.php`.

| Clave | Variable de entorno | Valores | Por defecto |
|---|---|---|---|
| `regla_vencimiento` | `QUINCENA_REGLA_VENCIMIENTO` | `calendario`: quincena calendario (1-15 y 16-fin de mes) de la fecha de vencimiento. `cada_15_dias`: ciclos de 15 días contados desde la autorización. | `calendario` |
| `modo_marcado` | `QUINCENA_MODO_MARCADO` | `confirmar`: el proceso por consola solo calcula y muestra la vista previa; una persona confirma la quincena desde la pantalla. `automatico`: el proceso real crea los pagos con origen Automático a nombre de Sistema. | `confirmar` |
| `inicio_primera_cuota` | `QUINCENA_INICIO_PRIMERA_CUOTA` | `desde_aprobacion` o `desde_desembolso`. | `desde_desembolso` |
| `programador_activo` | `QUINCENA_PROGRAMADOR_ACTIVO` | `true` / `false`. | `false` |

Un valor inválido en `regla_vencimiento` o `modo_marcado` hace fallar el proceso con un mensaje claro: nunca se adivina ni se cae en el modo automático.

> **`inicio_primera_cuota` todavía no cambia nada.** Hoy `PrestamoService` genera las cuotas con `fecha_autorizacion + 15 días`, y cada cuota suma 15 días más. La clave deja registrada la decisión de Bienestar; aplicarla a la generación de cuotas es un cambio de negocio aparte, que se hará cuando respondan.

## Cómo se comporta

### Simulación (por defecto)

`QuincenaService::procesar($anio, $quincena)` y `php artisan quincena:procesar` sin `--ejecutar`. No escribe nada: ni procesos, ni pagos, ni auditoría. Sin `--anio` y `--quincena` toma la quincena actual (zona America/Bogota).

### Proceso real

`php artisan quincena:procesar --anio=2026 --quincena=19 --ejecutar`

- **Exige `--anio` y `--quincena` explícitos**; si falta alguno aborta con un mensaje. El programador también los pasa siempre explícitos.
- **Aborta si no existe el usuario Sistema**, con la indicación de correr `SistemaUsuarioSeeder`. No lo crea.
- **Rechaza una quincena que todavía no empieza.**
- **Es idempotente**: si ya existe un proceso real de esa quincena y año, devuelve ese proceso sin crear nada nuevo. En modo `automatico`, además, las cuotas ya pagadas no vuelven a entrar.
- Con `modo_marcado = confirmar`: **no escribe nada**. Calcula y muestra la vista previa (en el comando, «VISTA PREVIA (modo confirmar: no se escribió nada)»); ya no crea la fila «Pendiente de confirmación». El proceso nace cuando una persona confirma la quincena en la pantalla.
- Con `modo_marcado = automatico`: crea un pago Regular, origen Automático, autor Sistema, por cada cuota, dentro de una sola transacción (estado «Completado»), y deja `pagos.id_proceso_quincena`. La fecha del pago es el corte de la quincena, o hoy si el corte aún no llega.

### Qué cuotas entran

Cuotas **Pendiente** de préstamos **Vigente** o **En mora** que vencen en la quincena según `regla_vencimiento`. El resumen lista aparte las **excluidas con su motivo** (préstamo Cancelado, cuota ya pagada, cuota Vencida).

Las cuotas que vencieron en quincenas anteriores y siguen pendientes **no se procesan**: el resumen solo informa cuántas son (`vencidas_anteriores`).

### Programador

Con `programador_activo = true`, corre todos los días a las 6:00 (hora de Bogotá) y solo actúa el día 15 y el último día del mes. Con `false` (por defecto) no se registra nada.

## Confirmación de la quincena (modo `confirmar`)

Mientras el programador siga apagado, la quincena se cierra a mano desde **Pagos › Quincenas** (RF-007). Pueden usarla el Administrador y el Funcionario de Bienestar (`puedeOperar()`); el invitado y las cuentas deshabilitadas no.

### Pantallas

| Ruta | Qué muestra |
|---|---|
| `quincenas` | Quincenas iniciadas pendientes de confirmar (todas, no solo la actual) y los procesos ya confirmados. |
| `quincenas/{año}/{quincena}` | Vista previa: cuotas con casillas para excluir (motivo obligatorio), advertencia de dos cuotas del mismo préstamo, total y botón «Confirmar quincena». No escribe nada. Si la quincena ya está confirmada redirige al detalle. |
| `quincenas/proceso/{id}` | Detalle de un proceso confirmado: quién y cuándo, cuotas pagadas, cuotas excluidas con motivo. |

**Aviso:** en Inicio (banner amarillo) y en el menú (insignia junto a «Pagos» y «Quincenas») aparece cuando hay una quincena **ya iniciada, sin proceso confirmado y con cuotas candidatas**. Es un cálculo derivado en cada visita (`QuincenaService::quincenasPendientes()`); no depende del programador.

### Qué hace «Confirmar quincena»

Todo en **una sola transacción** (`QuincenaService::confirmar()`):

1. Inserta primero la fila del proceso como `Completado`, con la persona que confirma. La restricción única (año, quincena, tipo) impide la doble confirmación, también la concurrente: quien llega segundo ve «La quincena N de AAAA ya fue confirmada por X el dd/mm/aaaa hh:mm».
2. **Recalcula en vivo** (leyendo las cuotas con bloqueo de escritura) y compara con lo que la persona vio (id y valor de cada cuota). Si el conjunto cambió —un pago manual, una reliquidación (que regenera las cuotas con ids nuevos), una cancelación, un cambio de estado del préstamo— **no paga nada**, revierte, recarga la vista previa y muestra la diferencia.
3. Paga cada cuota no excluida con `PagoService::registrarPagoCuota`: pago Regular, **autor = quien confirma**, origen «Automático» (el enum no se migró), `id_proceso_quincena` = el proceso. Si era la última cuota pendiente, el préstamo se cierra como siempre.
4. Guarda en `procesos_quincena.resumen` las cuotas pagadas, el total, la fecha de pago y las excluidas con su motivo.
5. Audita: una «Pago de cuota» por cada cuota (como siempre) y una «Confirmación de quincena» con quién, cuántas cuotas, el total y las excluidas con su motivo (la fecha y hora las pone la auditoría).

Si cualquier paso falla, no queda nada: ni el proceso, ni pagos, ni auditoría.

### Reglas de la confirmación

- **Fecha del pago:** el corte de la quincena, o hoy si el corte aún no llega.
- **Exclusión manual:** el motivo es obligatorio (se valida en el formulario y en el servicio). La cuota queda **Pendiente** y aparece como «vencida anterior» en la vista previa de la quincena siguiente.
- **Préstamos En mora:** sus cuotas se incluyen, como en el cálculo.
- **Fuera de orden:** se puede confirmar cualquier quincena iniciada sin haber confirmado las anteriores.
- **Dos cuotas del mismo préstamo en la misma quincena:** la pantalla las resalta y advierte; ambas entran salvo que se excluya una.
- **Quincena sin cuotas, o que no ha empezado:** no se puede confirmar. Si todas las cuotas se excluyen con motivo, la quincena se confirma sin pagos.
- **Modo del proceso:** la pantalla funciona igual con `modo_marcado` en `confirmar` o `automatico`; el proceso queda registrado como `confirmar` porque lo confirmó una persona.
- **Procesos «Pendiente de confirmación» heredados** de la versión anterior (el comando creaba esa fila): al confirmar esa quincena se toma la fila existente y se completa, en vez de chocar con la restricción única. No se borra nada.

## Advertencia: cuotas actuales y la regla «calendario»

Con las cuotas tal como se generan hoy (autorización + 15 días, y luego cada 15 días), **la regla `calendario` puede dejar quincenas con 0 o con 2 cuotas del mismo préstamo**. Los ciclos de 15 días no coinciden con las quincenas calendario, que tienen 15 o 16 días (13 o 14 en febrero), así que de vez en cuando dos vencimientos caen en la misma quincena y otra queda vacía.

Por eso el resumen de `QuincenaService` y de `quincena:procesar` **advierte, sin suprimir nada**, cuando un préstamo tiene más de una cuota que vence en la misma quincena: ambas siguen incluidas y, en modo automático, ambas se pagan.

Cómo se resuelve depende de la respuesta de Bienestar sobre las **fechas de vencimiento** (ver abajo).

La regla `cada_15_dias` asigna cada cuota al ciclo de 15 días, contado desde la autorización, que contiene el corte de la quincena. Evita las dobles cuotas, pero como un ciclo no siempre coincide con una quincena, una cuota puede procesarse en la quincena anterior a su fecha o quedar sin asignar; las que queden sin asignar aparecen como vencidas anteriores. Es una aproximación para poder simular, no una decisión de negocio.

## Decisiones que esperan respuesta de Bienestar

| # | Decisión | Opciones | Qué cambia al responder |
|---|---|---|---|
| 1 | **Fechas de vencimiento** | Calendario (15 y fin de mes) o cada 15 días desde la autorización. | `regla_vencimiento`. Si es calendario, probablemente haya que regenerar las fechas de las cuotas para que haya una por quincena. |
| 2 | **Marcado de pagos** | Automático o con confirmación previa. | `modo_marcado`. El paso de confirmación ya está construido (ver arriba); la decisión solo define cuál de los dos caminos se usa. |
| 3 | **Inicio de la primera cuota** | Desde la aprobación o desde el desembolso. | `inicio_primera_cuota`, y el cambio correspondiente en la generación de cuotas (`PrestamoService`). |
| 4 | **Formato del informe** | Por definir: contenido, columnas y formato del informe de la quincena. | Hoy el resumen se imprime en consola y se guarda en `procesos_quincena.resumen`. Falta definir el informe oficial. |

Preguntas menores que conviene resolver junto con las anteriores:

- Si el proceso debe pagar las cuotas vencidas de quincenas anteriores de los préstamos En mora, o solo informarlas como hoy.
- Qué hacer con una cuota excluida a mano: hoy queda Pendiente y solo se informa como vencida anterior en la quincena siguiente; no hay una pantalla para pagarla después fuera de «Pagar» en el detalle del préstamo.
- Si el origen de los pagos confirmados por una persona debe seguir siendo «Automático» o convertirse en un valor propio del enum (hoy se distingue por `id_proceso_quincena` y por el autor).

## Cómo probarlo

```sh
php artisan quincena:procesar                      # simula la quincena actual
php artisan quincena:procesar --anio=2026 --quincena=19
php artisan quincena:procesar --anio=2026 --quincena=19 --ejecutar   # real
```

Para confirmar una quincena en el navegador: iniciar sesión como Administrador o Funcionario, abrir **Pagos › Quincenas** (o el aviso de Inicio), «Revisar y confirmar», marcar las exclusiones con su motivo si hay y pulsar «Confirmar quincena».

Las pruebas (SQLite en memoria): `tests/Feature/QuincenaServiceTest.php`, `ProcesarQuincenaCommandTest.php`, `ProgramadorQuincenaTest.php`, `ProcesoQuincenaModeloTest.php` y `ConfirmarQuincenaTest.php` (vista previa, aviso, confirmación, exclusión, doble confirmación, cambios entre la vista previa y la confirmación, rollback y permisos).
