# Reglas de Negocio
Aplicación de Préstamos de Bienestar Social — Politécnico Colombiano Jaime Isaza Cadavid

Fuente: Acuerdo Directivo No. 19 del 12 de octubre de 2022 (reglamento del Programa de Bienestar Social) y Acuerdo Directivo No. 11 del 15 de noviembre de 2023 (modifica la tasa de interés del Acuerdo 19). Se complementa con lo confirmado en la entrevista con el área de Bienestar Social y el manual de captura de novedades del AS/400.

## 1. Tasa de interés
- La tasa de interés de los créditos de vivienda corresponde al IPC del año inmediatamente anterior, certificado por el DANE, sin superar el 5% anual (Acuerdo 11/2023, Art. 1 y 2, que modifica los artículos 10 y 14 del Acuerdo 19/2022).
- La tasa se fija con base en el año en que se presenta la solicitud del crédito, y no cambia posteriormente para ese préstamo.
- La tasa es el IPC del año anterior al año de la **solicitud**, no del desembolso. Una solicitud puede desembolsarse en otro año (ejemplo confirmado con documentos reales: solicitud en 2018, desembolso en febrero de 2019, tasa de 2018). Por eso la fecha de solicitud y la de desembolso se guardan por separado.
- **Tope del 5 % anual (decidido, Acuerdo 11/2023):** aplica a las cinco modalidades de vivienda (compra, construcción, cancelación total de hipoteca o deshipoteca, reparaciones o mejoras y cambio de vivienda; el código las reconoce porque su nombre empieza por «Vivienda») y a las solicitudes presentadas **desde el 15 de noviembre de 2023**, fecha de expedición del Acuerdo; las anteriores conservan su tasa y no se reajusta nada. Vehículo (IPC más puntos por plazo), educación y calamidad no tienen tope.
- **Cómo se aplica:** el IPC registrado en `tasas_int` no se modifica; el tope se aplica al **leer** la tasa: tasa efectiva = mínimo entre el IPC registrado y el 5 %. Un IPC de 5,20 % da 5 %; uno de 4,80 % queda en 4,80 %; uno de exactamente 5 % no se limita. El tope (`TASA_VIVIENDA_TOPE`, 0,05) y la fecha de vigencia (`TASA_VIVIENDA_VIGENTE_DESDE`, 2023-11-15) están en `config/prestamos.php`.
- **Registro y edición del IPC (pantalla de la modalidad):** la tasa de cada año se registra una sola vez por modalidad (año único) y **se puede editar** después; al editar solo cambia `tasa_anual` (el año, la modalidad y la fuente no), y no se puede borrar. Se acepta coma o punto decimal («6,5» o «6.5») y el rango es de 0 a 100. Cada edición deja en Auditoría («Edición de tasa de interés») el valor anterior y el nuevo. Los errores de validación se muestran en la misma pantalla, junto al formulario. Se necesita el permiso `modalidades.gestionar`; con `modalidades.ver` solo se consulta.
- **El tope nunca se escribe en el dato:** lo que se registra y se edita es el IPC real. Para vivienda la pantalla lo recuerda con una nota («Para vivienda, desde las solicitudes del 15/11/2023 se aplica un tope del 5 % (Acuerdo 11 de 2023); aquí se registra el IPC real.») y el tope se sigue aplicando solo al leer la tasa.
- La misma tasa limitada la usan la validación y el correo al comité de la solicitud, el simulador, el préstamo aprobado (guarda la tasa limitada en `prestamos.tasa_efectiva_anual`) y la reliquidación (que lee la tasa del préstamo). El simulador muestra la nota «Tasa limitada al 5 % según el Acuerdo 11 de 2023; IPC registrado: X %» en la pantalla, en el detalle de la solicitud y del préstamo y en la hoja impresa.
- En el simulador, la tasa y los puntos escritos a mano en vivienda no pueden sumar más que el tope: se rechazan con un mensaje en español. En las demás modalidades no hay tope.
- Cada año debe revisarse la tasa de interés, el impacto de los recursos y la ejecución del Programa (Acuerdo 11/2023, Art. 2, parágrafo).

> Para las modalidades distintas a vivienda (educación, calamidad doméstica, vehículo) el Acuerdo 19/2022 define tasas propias — ver secciones 3 y 4.

### 1.1 Cálculo del interés
La tasa del crédito es una tasa efectiva anual (E.A.). Confirmado con documentos reales de Bienestar:
- **Tasa periódica quincenal** = (1 + E.A.)^(1/24) − 1.
- **Tasa nominal anual** = tasa periódica quincenal × 24.
- La **cuota es fija** (amortización gradual, sistema francés); el interés de cada periodo se calcula sobre el saldo.
- **Retirados** (ver sección 11): el plazo restante en quincenas ÷ 2 equivale a meses; las cuotas son mensuales y la tasa mensual = (1 + E.A.)^(1/12) − 1. No se fija un número de cuotas como "12".

Casos reales para verificar el cálculo (coinciden al centavo con los Excel de Bienestar):

| Saldo | E.A. | Plazo | Cuota fija | Interés del primer periodo |
|---|---|---|---|---|
| 1.158.589 | 1,61 % | 7 quincenas | 165.953,74 | 771,29 |
| 2.251.738 | 10,22 % | 5 meses | 461.407,23 | 18.333,61 |
| 29.087.609 | 10,22 % | 47 meses | 747.320,44 | 236.830,80 |

## 2. Capacidad de endeudamiento
- Un funcionario tiene capacidad de pago cuando, después de las deducciones voluntarias, las ordenadas por autoridad competente y la cuota del préstamo (capital, intereses y seguros), le queda libre el 50% de su salario (Acuerdo 19/2022, Art. 10, parágrafo 3).
- **Descuentos de ley (decidido, Bienestar):** a diferencia de la lectura literal del Acuerdo, el sistema **sí** descuenta las deducciones de ley del salario antes de calcular la capacidad: salud 4 %, pensión 4 % y fondo de solidaridad pensional por tramos de SMMLV (retención en la fuente solo si el operador la digita en el simulador). Los porcentajes y tramos deben verificarse con la norma vigente.
- La cuantía del préstamo está sujeta a esta capacidad de endeudamiento, sin superar los topes máximos por modalidad.
- **Una sola fuente de verdad (`CapacidadEndeudamientoService`):** el simulador y la aprobación de solicitudes usan el mismo cálculo. La capacidad total es el 50 % de (`empleados.salario` devengado − salud 4 % − pensión 4 % − fondo de solidaridad − retención manual opcional), con el SMMLV del año de la solicitud (o de la simulación) para ubicar el tramo del fondo. La aprobación no recibe retención (es un dato del simulador), así que equivale al simulador con retención 0.
- **Capacidad guardada = referencia:** `empleados.capacidad_endeudamiento` se conserva, sin modificarla, solo como valor informativo: la ficha del empleado muestra la «Capacidad calculada» y, si difiere, «(referencia: la guardada es X)». Ya no decide la aprobación, salvo con el interruptor.
- **Interruptor `capacidad.fuente`** (`config/prestamos.php`, variable `CAPACIDAD_FUENTE`): `calculada` (por defecto) o `guardada`. Con `guardada` la aprobación y el simulador deciden con la capacidad guardada, como antes (si es nula cuenta como 0). Reemplaza al interruptor `SIMULADOR_RESTAR_DESCUENTOS_LEY`, que se retiró.
- **Cuota que se compara:** en **vivienda**, cuota + seguros estimados (vida de deudores y, si su prima es mayor que 0, incendio y terremoto; servicio de primas por año); en las demás modalidades, solo la cuota. Siempre cuota mensual = (cuota quincenal [+ seguros]) × 2. El seguro sigue siendo una estimación.
- **Sin salario:** con fuente `calculada` y salario vacío o 0 no se puede calcular: la aprobación queda bloqueada con el incumplimiento `capacidad_no_calculable` («No se puede calcular la capacidad de endeudamiento: el empleado no tiene salario registrado.») y el simulador no evalúa la modalidad. Sin SMMLV del año ya consta `sin_smmlv`. Con fuente `guardada` no aplica.
- **Cómo lo aplica el sistema:** la cuota de la solicitud (arriba) debe caber en la capacidad **disponible** del empleado = capacidad total − capacidad comprometida. La comprometida es la suma de las cuotas quincenales de sus préstamos en estado Vigente y En mora × 2; los cancelados no cuentan, y un préstamo recién aprobado entra a la cuenta de inmediato. La misma cuenta la usan la aprobación de solicitudes y el simulador. El rechazo informa la capacidad total, la comprometida, la disponible y la cuota solicitada; en vivienda desglosa además la cuota y los seguros estimados.
- **Bloqueo por salario mínimo (decidido, Bienestar):** si el salario del empleado es **igual o menor** al SMMLV del año de la solicitud, la solicitud se bloquea. A quien devenga el mínimo no se le puede descontar por nómina, y «es el sistema el que tiene que rechazarlo, no permitirlo». Se compara `empleados.salario` (tratado como salario devengado total) con el SMMLV registrado en el aplicativo (`smmlv_anual`) para el año de la `fecha_solicitud`, no el de otro año. Un peso por encima del mínimo sigue funcionando.
  - **Registro:** `SolicitudCreditoService::crear()` lo rechaza con «No se puede registrar: el salario del empleado es igual o menor al salario mínimo (SMMLV). A quien devenga el mínimo no se le puede descontar por nómina.»
  - **Aprobación:** el incumplimiento `salario_minimo` de `incumplimientos()` mantiene bloqueada la aprobación de las solicitudes que ya existían.
  - **Simulador:** la modalidad queda «no apta» (motivo `salario_minimo`) y la simulación se rechaza con el mismo mensaje, sin ofrecer crear la solicitud.
  - **Si no se puede evaluar:** sin salario registrado (cero) o sin SMMLV del año no se aplica esta regla y se conserva el comportamiento anterior (en el simulador «El salario del empleado debe ser mayor que cero.»; sin SMMLV, el aviso `sin_smmlv`, que ya bloquea la aprobación y el simulador pero no el registro).
- **Por confirmar (reunión 14-oct):** cómo se descuentan otras deducciones (judiciales, voluntarias, etc.). Hoy el sistema solo resta los préstamos que maneja.
- **Simulador de crédito:** sigue mostrando la capacidad calculada con descuentos de ley (con su desglose) y la guardada, y avisa si dan un veredicto distinto; el veredicto lo da la que fija `capacidad.fuente`, igual que la aprobación. El monto máximo que ofrece por modalidad también incluye los seguros en vivienda.

### 2.1 Elegibilidad: año de servicio
- Para las modalidades de **vivienda** (compra, construcción, deshipoteca, reparaciones o mejoras, cambio de vivienda) y **vehículo**, el empleado debe tener al menos **un año completo de servicio**, contado desde su fecha de vinculación hasta la fecha de la solicitud. Exactamente un año cumple.
- Las demás modalidades (educación, calamidad doméstica) no exigen antigüedad.
- Si el empleado no tiene fecha de vinculación registrada, el sistema no puede comprobar la antigüedad y rechaza la solicitud de estas modalidades.
- El sistema reconoce las modalidades de vivienda porque su nombre empieza por «Vivienda»; no hay un campo de categoría.
- **Por confirmar (reunión 14-oct):** cómo cuenta el periodo de prueba.

## 3. Modalidades de crédito
- Vivienda — compra de inmueble
- Vivienda — construcción
- Vivienda — cancelación total de hipoteca (deshipoteca)
- Vivienda — reparaciones o mejoras
- Vivienda — cambio de vivienda
- Educación
- Calamidad doméstica y necesidades especiales
- Vehículo

> El Acuerdo 19/2022 también contempla la modalidad de seguros (concepto 010 del manual AS/400), sin una tabla propia de cuantías en el articulado revisado.

## 4. Cuantías, plazos y tasas por modalidad

> En el sistema todos los plazos se manejan **en quincenas** (24 por año): vivienda 20 años = 480, vehículo 7 años = 168, educación y calamidad 36 meses = 72. Las pantallas muestran al lado una equivalencia aproximada (meses hasta 120 quincenas, años desde ahí).

### 4.1 Vivienda
| Salario del beneficiario | Cuantía máxima | Plazo | Tasa |
|---|---|---|---|
| Hasta 4 SMMLV | 130 SMMLV | 20 años | IPC año anterior (≤5%) |
| Mayor a 4 y hasta 8 SMMLV | 190 SMMLV | 20 años | IPC año anterior (≤5%) |
| Mayor a 8 SMMLV | 220 SMMLV | 20 años | IPC año anterior (≤5%) |

Amortización gradual con cuota quincenal (Art. 12).

### 4.2 Educación
| Salario básico | Cuantía máxima | Plazo máximo | Tasa |
|---|---|---|---|
| Hasta 4 SMMLV | 17.5 SMMLV | 36 meses | IPC |
| Hasta 8 SMMLV | 19 SMMLV | 36 meses | IPC |
| Superior a 8 SMMLV | 20 SMMLV | 36 meses | IPC |

Puede reliquidarse semestralmente si al momento de la solicitud el empleado tiene capacidad de endeudamiento (Art. 28, parágrafo).

### 4.3 Calamidad doméstica y necesidades especiales
| Salario básico | Cuantía máxima | Plazo máximo | Tasa |
|---|---|---|---|
| Hasta 4 SMMLV | 5 SMMLV | Hasta 36 meses | IPC |
| Hasta 8 SMMLV | 10 SMMLV | Hasta 36 meses | IPC |
| Superior a 8 SMMLV | 15 SMMLV | Hasta 36 meses | IPC |

### 4.4 Vehículo
| Tipo de solicitud | 3 años | 4 años | 5 años | 7 años | Cuantía máxima |
|---|---|---|---|---|---|
| Primera solicitud | IPC | IPC+1 | IPC+2 | IPC+3 | Hasta 50 SMMLV |
| A partir de la segunda solicitud | IPC+2 | IPC+3 | IPC+4 | IPC+5 | Hasta 50 SMMLV |

**Cómo se aplica el tramo según el plazo (aclarado por Bienestar, art. 38):** el plazo máximo es 7 años (168 quincenas) en la primera y en la segunda solicitud. Con el plazo en quincenas (72, 96, 120 y 168 son los tramos de 3, 4, 5 y 7 años), si el plazo cae entre dos tramos se toma **siempre el tramo anterior** (84 → 3 años; 108 → 4; 132, 144 y 150 → 5; 6 años = 144 quincenas usa el de 5). Un plazo menor al primer tramo (24 o 48 quincenas) usa el tramo de 3 años. Un plazo mayor a 168 quincenas se rechaza: «El plazo máximo del crédito de vehículo es de 7 años (168 quincenas).» Se usa la tabla de primera o de segunda solicitud según corresponda; el redondeo de la tasa (4 decimales) no cambia. Antes los años se tomaban completos y un plazo sin fila exacta (1, 2 o 6 años) quedaba con 0 puntos. El simulador muestra junto a la tasa el tramo aplicado, p. ej. «Tramo de 5 años: IPC + 2 puntos (plazo solicitado: 6 años)». La implementación es `TasaVehiculoService::tramoPara()`; los puntos se leen de la tabla editable `tasas_vehiculo`.

## 5. Presupuesto del Fondo
- Se constituye con: abono a capital e intereses recaudados, partidas del presupuesto institucional (0.5% del presupuesto anual de funcionamiento, incrementado cada año en IPC + 5 puntos porcentuales), rendimientos de inversiones y donaciones (Art. 2).
- 70% del presupuesto se destina a vivienda, 30% a las demás modalidades (Art. 2, parágrafo 2, y Art. 18).
- Dentro del 70% de vivienda: 70% compra y construcción (15% de ese para construcción); el 30% restante se divide en partes iguales (10% c/u) entre deshipoteca, reparaciones/mejoras y cambio de vivienda (Art. 18).
- Los ingresos se manejan en una cuenta especial separada, "Politécnico Colombiano JIC - Programas de Bienestar Social" (Art. 3).

## 6. Comité de Bienestar Social

> **Corrección frente a lo dicho en la entrevista:** la entrevista mencionó "mínimo 3 votos a favor". El Acuerdo 19/2022 (Art. 4 y 6) establece una regla distinta, que es la oficial.

- Integrado por 5 miembros con voz y voto: Rector o delegado (preside), Director de Bienestar Institucional, Director de Gestión Humana, un representante de docentes y uno de empleados no docentes (Art. 4).
- El Profesional Especializado de Desarrollo Laboral es Secretario, con voz pero sin voto.
- Quórum mínimo: 3 miembros (Art. 6, parágrafo 2).
- Decisiones por mayoría de los asistentes a la reunión — no por un número fijo de votos (Art. 6, parágrafo 3).
- Constancia en actas firmadas por Presidente y Secretario.
- La notificación de rechazo se envía por correo electrónico (confirmado en la entrevista).
- **Envío al Comité:** el administrador o el funcionario (permiso `solicitudes.enviar-comite`) envía cada solicitud **Radicada** por correo al Comité, un correo por solicitud, con los documentos que elija como adjuntos (hasta 15 MB en total, configurable). El cuerpo trae empleado, cédula, modalidad, monto, plazo, tasa, cuota y capacidad de endeudamiento —sin el salario— y, si está activado, las observaciones de incumplimientos. El envío no cambia el estado: queda una marca «Enviada al comité» y un registro (quién, cuándo, a quiénes, adjuntos y cifras enviadas); reenviar exige confirmarlo. **Por confirmar con Bienestar:** correos del Comité y de la copia, remitente, textos, si se permite enviar sin documentos y si el correo debe incluir las observaciones. Ver `docs/restricciones.md`, sección 7.
- Las solicitudes se ordenan por puntaje (antigüedad, composición familiar, salario, destinación, cabeza de familia) — Art. 16 y 17; ver el detalle en la sección 15. **Alcance por decidir: no automatizado.**

## 7. Desembolso
- Solo se hace efectivo cuando el beneficiario haya constituido la garantía y el contrato de mutuo esté formalizado (Art. 23).
- Compra de vivienda: pago directo al vendedor.
- Cancelación de hipoteca: pago directo a la entidad financiera acreedora (tercero/proveedor).
- Construcción y mejoras: dos cuotas iguales — primera al liquidar el préstamo, segunda tras inspección técnico-administrativa que compruebe el 80% de inversión de la primera (Art. 11 y 23).
- Si a los 30 días no se ejecuta adecuadamente la inversión, se declara extinguido el plazo y exigible el crédito (Art. 22, parágrafo 2).
- **Sin pagos ni abonos antes del desembolso (decidido, Bienestar):** un pago o abono solo se registra cuando el crédito existe de forma efectiva y tiene saldo; no se abona a lo que no se ha desembolsado. Mientras `prestamos.pendiente_desembolso = true`, el servicio (`Prestamo::asegurarDesembolsado()`, llamado desde `PagoService`, `ReliquidacionService` y `CesantiasService`) rechaza el pago de cuota, el abono extraordinario, la cancelación total, el abono de cesantías y la reliquidación con «No se puede registrar: el préstamo aún no se ha desembolsado. Los pagos y abonos se registran después del desembolso.» La pantalla del préstamo deja esos botones deshabilitados con el motivo. Con el primer desembolso se levanta el bloqueo. Los préstamos anteriores a la regla (`pendiente_desembolso = false`) no cambian. No se bloquea el retiro del empleado (es un hecho laboral, no un movimiento de dinero sobre el préstamo); el préstamo pendiente de desembolso no tiene hoy una acción de «anular» y la única «cancelación» existente es la cancelación total (pago del saldo), que sí queda bloqueada.

## 8. Aplicación de pagos y mora
- Desde la primera cuota se amortiza a capital; cuotas fijas salvo abonos extraordinarios (Art. 12, parágrafo 2).
- **Fechas de vencimiento (decidido, Bienestar):** por calendario, el **día 15 y el último día real de cada mes** (30 o 31 según el mes; 28 o 29 en febrero según sea bisiesto). Cada fecha se calcula por su número de vencimiento, no sumando meses, para que el fin de mes no se corra (`CalendarioQuincenalService::vencimientosDesde()`).
- **Primera cuota (decidido, Bienestar):** la cuota empieza a descontarse **desde el desembolso**. Los vencimientos son el 15 y el último día real de cada mes (`CalendarioQuincenalService`). La primera cuota vence en el primer vencimiento **estrictamente posterior** a la fecha del desembolso: desembolso el 3 → el 15; el 20 → el último día; el 15 exacto → el último día; el último día exacto → el 15 del mes siguiente. Las demás siguen el calendario sin saltos.
  - **Cierre de novedades (decidido, Bienestar):** las novedades de nómina se reportan unos 2 días antes de la quincena (casi siempre 2, a veces 1). Para cada vencimiento V (15 o último día real del mes) su cierre es V menos `quincena.cierre_novedades_dias_antes` días calendario (`QUINCENA_CIERRE_NOVEDADES_DIAS_ANTES`, por defecto 2). **La primera cuota es el primer V cuyo cierre es posterior a la fecha del desembolso** (y V sigue siendo posterior a ella). Ejemplo: cierre el 29 de octubre para la quincena del 31; desembolso el 30 de octubre → primera cuota el 15 de noviembre. Si el desembolso es el mismo día del cierre, `quincena.desembolso_dia_cierre_entra` (`QUINCENA_DESEMBOLSO_DIA_CIERRE_ENTRA`, por defecto `false`, **por confirmar con Bienestar**) decide: `false` no entra (pasa a la quincena siguiente) y `true` sí entra. La regla está solo en `CalendarioQuincenalService::primeraCuotaConCierre()` y la usan el desembolso real y la «Fecha estimada de desembolso» del simulador. Con `QUINCENA_INICIO_PRIMERA_CUOTA = desde_aprobacion` no cambia nada.
  - **Otra quincena (a veces se reporta de última hora):** al registrar el primer desembolso, la pantalla muestra «Primera cuota propuesta: dd/mm/aaaa. Cierre de novedades de esa quincena: dd/mm/aaaa.» y deja elegir la quincena **anterior** (solo si su vencimiento sigue siendo posterior a la fecha del desembolso) o la **siguiente**. Elegir otra distinta a la propuesta exige un **motivo** (texto obligatorio; si falta, se rechaza y no se guarda el desembolso). La auditoría «Registro de desembolso» guarda la fecha de la primera cuota, la propuesta original, el cierre de novedades, el criterio usado (días del cierre y si el día del cierre entra) y, si aplica, la opción elegida con su motivo. El motivo queda en `prestamos.motivo_primera_cuota` y el préstamo muestra «Cierre de novedades de esa quincena: …» y «Quincena elegida por el funcionario: <motivo>». Solo el primer desembolso fija la primera cuota; los préstamos ya desembolsados no cambian.
  - **Cuándo se fija:** al registrar el primer desembolso (`DesembolsoService`). Se guarda en `prestamos.inicio_primera_cuota`, se reasignan las fechas de las cuotas (los valores no cambian) y el detalle de la auditoría «Registro de desembolso» trae «primera cuota: AAAA-MM-DD». Un segundo tramo (construcción y mejoras) no mueve la fecha.
  - **Antes del desembolso:** al aprobar, el préstamo queda con `pendiente_desembolso = true` y su tabla con fechas provisionales (autorización + 15 días). Sus cuotas **no se cobran**: no entran en el proceso/informe de quincena (vista previa, confirmación, pendientes y vencidas anteriores), ni en el informe de créditos por empleado de la quincena, ni en la cartera vencida. La pantalla del préstamo dice «Las cuotas empiezan a descontarse a partir del desembolso.» y, ya desembolsado, «Primera cuota: <fecha>». El simulador mantiene su estimación con la nota «La fecha real de la primera cuota se define al desembolsar».
  - **Fecha estimada de desembolso (simulador, opcional):** si se deja vacía, todo queda igual. Si se diligencia (fecha válida AAAA-MM-DD, sin restricción de pasado o futuro), la primera cuota estimada es `CalendarioQuincenalService::primerVencimientoPosterior(fecha)`, la misma regla del desembolso real, y la tabla sigue el calendario quincenal desde ella. Se muestra «Primera cuota estimada: <fecha>» con la nota «Estimación según la fecha de desembolso indicada; la fecha real se define al desembolsar», también en la hoja impresa. Solo cambian las fechas mostradas: cuotas, intereses, montos y capacidad son los mismos.
  - **Sigue contando como comprometido:** el préstamo aprobado y aún sin desembolsar sigue restando en la capacidad de endeudamiento del empleado (el compromiso existe desde la aprobación), y se puede ver y consultar. Lo mismo el simulador y el retiro del empleado.
  - **Préstamos anteriores:** los aprobados antes de esta regla no se migran ni se reescriben (`pendiente_desembolso = false`, sin `inicio_primera_cuota`); su desembolso no cambia sus cuotas. La regla aplica a los préstamos que se aprueben desde ahora.
  - **Interruptor:** `QUINCENA_INICIO_PRIMERA_CUOTA` (`desde_desembolso` por defecto). Con `desde_aprobacion` los préstamos nuevos conservan la regla anterior.
  - **Pendiente:** el primer pago de construcción y mejoras en dos tramos no se toca aquí.
- Las cuotas reales y la tabla del simulador usan el mismo generador (`PrestamoService::fechasDeVencimiento()`), así que sus fechas coinciden. No se reajustaron préstamos existentes: no hay datos reales cargados y los de prueba se recrean con el seeder.
- Una reliquidación no mueve fechas: las cuotas pendientes que se conservan mantienen la suya (ver §9), así que la secuencia 15 / fin de mes se conserva.
- **Cierre de novedades de nómina (provisional):** `QUINCENA_CIERRE_NOVEDADES_DIAS_ANTES` (por defecto 2) días calendario antes de cada vencimiento. Se muestra, marcado «provisional», en la vista previa de la quincena y no bloquea ninguna operación; su único efecto es decidir la quincena de la primera cuota de un crédito nuevo (ver «Primera cuota», sección 7). Pendiente de la respuesta de Bienestar.
- Intereses de mora: los legalmente establecidos por el Gobierno Nacional, por mes o fracción de mes en mora (Art. 14, parágrafo 1).
- Desde la 3ª cuota en mora se inician acciones administrativas/judiciales; cobro prejurídico con interés de 1.5 veces el bancario corriente, según el Manual de Cartera institucional (Art. 14, parágrafo 2) — **manual no revisado, pendiente**.

## 9. Abonos extraordinarios y reliquidación
- El beneficiario puede abonar total o parcialmente el capital en cualquier momento, eligiendo entre disminuir cuota o plazo (Art. 12, parágrafos 3 y 4).
- El Acuerdo (Art. 12, parágrafo 5) dice que, si el abono es "superior a diez (10) cuotas quincenales", se genera nueva liquidación de la cuota según el plazo pendiente; en esos casos el empleado elige entre bajar la cuota o el plazo.
- **Decidido (Bienestar):** la elección entre bajar la cuota o el plazo aplica a **todas** las modalidades (`ABONO_MODALIDADES`, por defecto `*`), con el umbral de 10 cuotas quincenales (`ABONO_UMBRAL_CUOTAS`).
- **Decidido:** el umbral compara el abono con 10 veces la cuota real del préstamo, **sin seguro** (el seguro no forma parte de la cuota real).
- **Decidido:** un abono **mayor** a 10 cuotas obliga a elegir entre bajar la cuota o el plazo. Uno **menor o igual** no pide elección: se reliquida solo, siempre en modo plazo. El saldo de capital baja, la cuota queda exactamente igual y baja el número de cuotas con la fórmula financiera (misma cuota, saldo menor); la última cuota puede quedar menor. Si el abono es menor que una cuota el número de cuotas puede no cambiar, pero el saldo baja y la última cuota queda menor.
- Las cuotas pendientes que se conservan mantienen su fecha de vencimiento: al bajar el plazo se eliminan las últimas cuotas de la tabla, no se corren las fechas. Las cuotas pagadas no se tocan. Se usa la tasa original del préstamo (la del año de la solicitud), no la vigente.
- Un abono igual o mayor al saldo se rechaza y se manda a «Cancelación total».
- La reliquidación automática queda en el historial de reliquidaciones marcada como «automática: abono bajo el umbral» y en la auditoría.
- Los abonos con liquidaciones de cesantías también generan reliquidación (Art. 12, parágrafo 6).

## 10. Cesantías
- Cada dos años, en febrero, a los beneficiarios de vivienda se les liquida voluntariamente el 50% de sus cesantías del año anterior, abonado a la deuda (Art. 13).
- El beneficiario puede autorizar que cada dos años se pignore a favor de la institución el 50% de sus cesantías (Art. 25, parágrafo 1).

## 11. Retiro del empleado y liquidación
- Si al retirarse (salvo por pensión) queda saldo, se imputa contra la liquidación de prestaciones sociales (primas, vacaciones, bonificaciones, indemnizaciones, cesantías e intereses) (Art. 40).
- Si persiste saldo, se reliquida a tasa DTF trimestre anticipado equivalente mes vencido, en cuotas constantes mensuales (Art. 40).
- Cálculo confirmado con documentos reales: el plazo restante en quincenas ÷ 2 da el número de meses (cuotas mensuales); la tasa mensual es (1 + E.A.)^(1/12) − 1 (sección 1.1). **Por confirmar (reunión 14-oct):** cómo se obtiene la E.A. a partir de la DTF trimestre anticipado.
- La tasa DTF se actualiza el 1 de enero de cada año, con base en el cierre de diciembre 31 del año anterior, certificada por la Superfinanciera (Art. 40, parágrafo 2).
- El crédito de vehículo se reliquida, en caso de retiro, a tasa DTF más los puntos adicionales del IPC de esa modalidad (Art. 40, parágrafo 1).

## 12. Seguros
- Seguro de vida deudor (prima mensual sobre saldo de la deuda) y seguro de incendio/terremoto para vivienda (prima anual sobre valor comercial) (Art. 15).
- **Prima del seguro de vida deudor:** mensual = saldo × 0,00034516 (0,0041419 ÷ 12). Se aplica al saldo de todos los créditos de la persona. **Por confirmar (reunión 14-oct):** si cubre todas las modalidades.
- **Primas por año (decidido, Bienestar):** la prima vigente es la que entregó Bienestar y **puede cambiar cada año**, así que se administra en la pantalla «Seguros» (tabla `primas_seguro`: concepto `vida_deudores` o `incendio_terremoto`, año y tasa con hasta 8 decimales, única por concepto y año) sin tocar código. Valores iniciales: vida de deudores 0,0003231 y incendio y terremoto 0,0000000 (aún sin definir), para el año en curso y el anterior. Se puede registrar la prima de un año y editarla; no se borra. Cada alta y cada edición deja en Auditoría el valor anterior y el nuevo. Permisos: `seguros.ver` y `seguros.gestionar`; el Administrador tiene ambos y el Funcionario de bienestar solo ve (`!seguros.gestionar` en sus defaults).
- **Cómo se cobra:** el seguro se cobra **aparte de la cuota**; no se guarda en la tabla `cuotas`, ni cambia la cuota guardada del préstamo ni su tabla de amortización. El valor del simulador es una **estimación**: «Estimación; el valor real lo calcula el área de seguros».
- **Simulador:** estimación por quincena = monto × prima de `vida_deudores` del año de la simulación (si no hay del año, la de la vigente más reciente de un año anterior; si no hay ninguna, el respaldo `SEGURO_TASA_SOBRE_MONTO` = 0,0003231, con un aviso discreto) y, **solo en vivienda**, más monto × prima de `incendio_terremoto` si es mayor que cero. En vivienda la cuota más el seguro se rotula «Cuota estimada con seguros» y se desglosa vida de deudores e incendio y terremoto; en las demás modalidades sigue siendo «Cuota + seguro». Solo en vivienda, la cuota más los seguros estimados se compara con la capacidad de endeudamiento, en el simulador y en la aprobación (sección 2).
- **Por confirmar con Bienestar:** la periodicidad real de la prima, si se calcula sobre el monto o sobre el saldo (la tarifa de arriba difiere de la del simulador) y si la prima de incendio y terremoto es una fracción del monto como la de vida (el simulador la trata así) o del valor comercial del inmueble.
- El listado para el seguro se genera en la segunda quincena de cada mes.
- Hay dos pólizas: la anual **VGD0099** y la mensual **VGD00100**.
- Beneficiario del seguro de vida: la institución. Beneficiario del seguro de incendio/terremoto: el propio empleado, para reconstrucción (Art. 15, parágrafo 2).

## 13. Garantías
- Hipoteca (vivienda) o prenda (vehículo), apoyadas en pagaré (Art. 41).
- Deshipoteca puede garantizarse inicialmente con pagaré + 2 avalistas con propiedad raíz libre, hasta constituir la hipoteca definitiva (Art. 41, parágrafo).
- Nunca se admite hipoteca de segundo grado (Art. 25).

## 14. Terminación del contrato
Causales de terminación anticipada, con exigibilidad total del crédito (Art. 26):
- Mora de 3 meses en el pago de cuotas.
- No ocupación de la vivienda por el adjudicatario.
- Falsedad de documentos o datos aportados.
- Persecución judicial del inmueble hipotecado por un tercero.
- Desmejora o depreciación del inmueble que lo vuelva garantía insuficiente.
- Gravamen adicional no autorizado sobre el inmueble.

## 15. Puntaje de vivienda (formato FGH83, Art. 17)
**Alcance por decidir: no automatizado.** Se documenta como referencia; hoy el Comité ordena las solicitudes fuera del sistema.

| Criterio | Puntos |
|---|---|
| Tiempo de servicio | 3 por año completo de servicio (máximo 60) |
| Composición familiar: cónyuge | 3 |
| Composición familiar: hijo hasta 25 años estudiando | 3 |
| Composición familiar: otras personas a cargo | 1 (máximo 3) |
| Composición familiar: tope total | 18 |
| Salario hasta 4 SMMLV | 8 |
| Salario entre 4 y 8 SMMLV | 6 |
| Salario de más de 8 SMMLV | 4 |
| Destinación: compra | 5 |
| Destinación: construcción | 4 |
| Destinación: deshipoteca | 3 |
| Destinación: reparaciones | 2 |
| Destinación: cambio de vivienda | 1 |
| Cabeza de familia | 5 |

Desempate (Art. 16, parágrafo 1): primero el tiempo de servicio, luego la composición familiar y por último el ingreso salarial.

## 16. Conceptos del AS/400
Conceptos de descuento conocidos en el manual de novedades del AS/400:

| Concepto | Significado |
|---|---|
| 10 | Seguros |
| 15 | Calamidad |
| 16 | Vehículo |
| 19 | Computador |
| 26 | Vehículo, segunda vez |
| 30 | Compra de vivienda |
| 33 | Deshipoteca |

**Por confirmar (reunión 14-oct):** el significado de los conceptos 14, 31 y 34 y de la columna TIPO. El concepto 19 (computador) no corresponde a ninguna de las modalidades de la sección 3.

## 17. Informe quincenal de cartera
Columnas del informe que usa Bienestar: cédula, nombre, concepto, fecha del préstamo, valor del monto, total de quincenas, quincena (cuotas pagadas), saldo, valor de la cuota, amortización a capital, interés, año de solicitud y tipo.

## 18. Trazabilidad de la normativa
| Documento | Fecha | Contenido |
|---|---|---|
| Acuerdo Directivo No. 19 | 12 de octubre de 2022 | Reglamento del Programa de Bienestar Social |
| Acuerdo Directivo No. 11 | 15 de noviembre de 2023 | Modifica la tasa de interés (Arts. 10 y 14) del Acuerdo 19/2022 |

## 19. Aviso de cuota excluida del descuento por nómina (Bienestar)
Bienestar aclaró que el aplicativo no puede controlar el pago de una cuota que queda excluida del descuento por nómina (licencia no remunerada, situación administrativa en que no se paga nómina, etc.): eso depende de otra área. Por eso el aplicativo solo **informa** a dónde debe ir el empleado a pagarla.

- **Qué es una cuota excluida:** hoy el único mecanismo explícito es la exclusión manual, con motivo, que quien confirma la quincena marca en la vista previa. La cuota queda Pendiente y la exclusión se guarda en el resumen del proceso confirmado. El aviso se muestra mientras la cuota siga Pendiente; deja de mostrarse al pagarse. No se infiere exclusión de ningún otro dato: los préstamos Cancelado/Pagado/En trámite y las cuotas Vencida o ya Pagada que la vista previa lista en «No entran en esta quincena» no son exclusiones por nómina y no llevan aviso. No existen en el aplicativo licencias ni novedades de nómina que alimenten una exclusión.
- **Dónde se muestra:** en el préstamo, junto al estado de la cuota excluida; en la vista previa de la quincena, al marcar una cuota como excluida; y en el detalle de la quincena confirmada, sobre la tabla «Cuotas excluidas». Nunca en cuotas normales. No hay hoja imprimible de cuotas, por lo que no se agregó allí.
- **Texto configurable (`config/quincena.php`, por `.env`):** `aviso_cuota_excluida` (`QUINCENA_AVISO_CUOTA_EXCLUIDA`), por defecto «Esta cuota no se descontó por nómina. Debe pagarse por ventanilla: {lugar}.», y `aviso_cuota_excluida_lugar` (`QUINCENA_AVISO_CUOTA_EXCLUIDA_LUGAR`), por defecto «consigne en la cuenta del Fondo de Bienestar Social el valor de la cuota, con la factura que genera Costeo y Facturación (puede solicitarla allí o a Bienestar Social), y entregue el soporte de pago a Bienestar Social para legalizarlo en Tesorería». `{lugar}` se reemplaza por el segundo valor. No se incluyen cuentas, direcciones ni teléfonos: los define Bienestar.
- **Alcance:** solo informativo. No cambia cálculos, estados, mora, pagos ni cierres.

## 20. Tipos de vinculación del empleado (Bienestar)
Bienestar confirmó los tipos que existen en la nómina. Se guardan con un nombre legible en `empleados.tipo_vinculacion` (enum `App\Enums\TipoVinculacion`, única fuente de formularios, filtros, validaciones y etiquetas) y se muestran siempre por su nombre, nunca por el valor interno.

| Valor guardado | Se muestra como |
|---|---|
| `vinculado` | Vinculado |
| `docente` | Docente |
| `docente_ocasional` | Docente ocasional |
| `jubilado` | Jubilado |

- **Docente ocasional: sin crédito.** No es beneficiario de crédito del Fondo. Se bloquea el registro de la solicitud («No se puede registrar: los docentes ocasionales no son beneficiarios de crédito del Fondo.»), la validación de una solicitud ya existente la marca con el incumplimiento `tipo_vinculacion` (así no se puede aprobar) y el simulador responde «no apto» con el mensaje «Los docentes ocasionales no son beneficiarios de crédito del Fondo.».
- **Jubilado: sin cambios por ahora.** No se bloquea ni recibe un trato distinto; la decisión está pendiente con Bienestar. Reglas actuales que podrían afectarlo cuando se defina: (1) la exigencia de empleado en estado Activo y, en las modalidades que piden un año de servicio, la fecha de vinculación; (2) el bloqueo por salario igual o menor al SMMLV, si se registra su mesada como salario; (3) la capacidad de endeudamiento estimada, que descuenta salud y pensión del salario (un jubilado normalmente no aporta pensión); (4) el supuesto de que toda cuota se descuenta por nómina, cuando la mesada la paga otra entidad (en la práctica sería una cuota con el aviso de la sección 19).
- **Empleado de carrera (`de_carrera`).** Indica si es empleado de carrera administrativa o docente de carrera. Se edita en el formulario de empleados y se muestra en la ficha, pero **no interviene** en puntaje, cuantía ni ninguna regla: la preferencia asociada está pendiente de definir con Bienestar.
- **Datos anteriores.** La migración convirtió «Empleado» en `vinculado` y «Docente» en `docente`; cualquier otro valor se habría dejado en `vinculado` y registrado en el log. No existe registro de auditoría de los cambios de empleado, por lo que la edición del tipo y de `de_carrera` no se audita (igual que el resto de datos del empleado).

## 21. DTF para la liquidación por retiro (captura y consulta)
La liquidación del crédito de quien se retira usa el DTF trimestre anticipado del Banco de la República (Acuerdo 19, art. 40). Esta parte solo **captura y consulta** el DTF; la liquidación por retiro completa sigue pendiente y `registrarRetiro` / `liquidarSaldoPorRetiro` no cambian. Nada consume todavía el DTF.

- **Pantalla «DTF»** (menú «Modalidades y presupuesto»): lista por fecha de vigencia descendente, registro de un DTF y edición de uno existente. No se borra. Reutiliza los permisos `modalidades.ver` (consultar) y `modalidades.gestionar` (registrar y editar). Cada alta y cada edición queda en Auditoría con el valor anterior y el nuevo.
- **Campos:** fecha de vigencia (desde cuándo aplica; única), DTF nominal trimestre anticipado (%; de 0 a menos de 100, hasta 4 decimales, acepta coma) y fuente u observación (opcional, hasta 100 caracteres). El DTF efectivo anual no se digita: se calcula y se guarda como **EA = (1 / (1 − TA/4))⁴ − 1** y se muestra con 2 decimales. Ejemplo de verificación de la funcionaria: 9,81 % nominal → 10,44 % efectivo anual.
- **Dos criterios sin decidir hoy (pregunta 39).** El Acuerdo dice que el DTF se actualiza cada 1 de enero con el del cierre del 31 de diciembre anterior; en la práctica dicen usar el vigente a la fecha del retiro. `DtfService::vigenteEn(fecha)` devuelve el registro con la fecha de vigencia más reciente menor o igual a la fecha dada; `delCierreDeAnio(año)` el vigente al 31 de diciembre del año anterior; `dtfParaRetiro(fecha)` aplica el criterio de `config/prestamos.php` (`dtf.criterio`, variable `DTF_CRITERIO`): `vigente_a_fecha` (por defecto) o `cierre_anio_anterior`. Si no hay un DTF que aplique devuelven null: no se inventan valores.

## 22. Informe de quincena por cuota (la «Q» de Informática)
Informe nuevo, por cuota, armado con lo que calcula `QuincenaService`; no reemplaza ni cambia el historial de pagos (que sigue siendo una fila por empleado), solo se enlaza desde su pantalla, en el menú «Reportes y descargas». No cambia cálculos, estados, mora ni cierres: solo lee.

- **Dónde se descarga:** botón «Descargar Excel (CSV)» en la vista previa de la quincena (respeta las exclusiones marcadas en ese momento, con su motivo si ya se escribió), en el detalle de una quincena confirmada (usa lo guardado) y, desde el historial de pagos, el ítem «Descargar informe de la quincena (CSV)» del menú «Reportes y descargas» (que agrupa también «Resumen de préstamos por modalidad y estado» e «Imprimir o guardar la Q en PDF»; «Buscar» sigue a la vista) para la quincena elegida (lleva al informe confirmado si existe y, si no, al de la vista previa). Permisos: `quincenas.confirmar` para la vista previa y `quincenas.ver` para la quincena confirmada. Cada descarga queda en Auditoría («Descarga de informe de quincena», con la quincena, vista previa o confirmada, el número de cuotas y de excluidas).
- **Formato actual: CSV, no .xlsx.** Se pidió Excel con `phpoffice/phpspreadsheet`, pero esa librería exige la extensión PHP **zip** y en este entorno (PHP 8.3, Laragon) no está activa; la instalación con composer se rechazó (`phpspreadsheet 5.10.0 requires ext-zip`). Por eso se entrega un **CSV UTF-8 con BOM, separado por punto y coma**, con coma decimal y fechas AAAA-MM-DD, que Excel en español abre directamente. Una sola hoja: encabezado, las cuotas, los totales y, debajo, el «Resumen por modalidad». No hay formato de celdas (miles, anchos, filtros ni encabezados congelados). **Para pasar a .xlsx:** activar `extension=zip` en `php.ini` (también en el servidor de Informática), con las extensiones `zip`, `xml`, `xmlreader`, `xmlwriter`, `dom`, `simplexml`, `gd`, `mbstring`, `fileinfo`, `libxml` y `zlib`; luego `composer require phpoffice/phpspreadsheet` y cambiar solo `InformeQuincenaService::csv()`.
- **Encabezado:** «Informe de quincena», la quincena (Qn de año, vista previa o confirmada), la fecha de vencimiento de la quincena, la fecha y hora de generación (hora de Bogotá) y el usuario.
- **Columnas (una fila por cuota incluida en el proceso):** Cédula, Nombre, Tipo de vinculación (por su nombre), Modalidad, N.º de préstamo, Monto inicial (monto aprobado), Plazo total (quincenas), Cuota n.º, Quincenas pagadas, Saldo inicial, Valor de la cuota, Capital, Intereses, Nuevo saldo, Estado y Motivo de exclusión. No incluye seguro, salario, capacidad ni otros datos sensibles. Los préstamos pendientes de desembolso no aparecen.
- **Cómo se calculan los saldos y las pagadas:** a partir de la tabla de cuotas del préstamo. *Saldo inicial* = suma del capital de las cuotas con número mayor o igual al de la fila (el capital que falta amortizar antes de esa cuota). *Nuevo saldo* = saldo inicial − capital de la cuota. *Quincenas pagadas* = cuotas anteriores a la de la fila que están en estado Pagada. Es el saldo según la tabla de amortización vigente: no suma cuotas anteriores sin pagar (por ejemplo una excluida antes) y, si el préstamo se reliquidó después, refleja la tabla actual.
- **Estado:** *Descontada* (cuota pagada en la quincena confirmada), *Excluida* (marcada a mano, con motivo; en quincenas confirmadas antes de guardarse el motivo queda vacío) o *Pendiente* (en la vista previa, aún sin descontar). Las excluidas no suman en el total «A descontar».
- **Totales y resumen:** dos filas de totales (a descontar, sin excluidas; y excluidas) con cuotas, valor, capital e intereses, y el «Resumen por modalidad» con los mismos subtotales por modalidad y una fila total. Una quincena sin cuotas genera el archivo igual, con el aviso «La quincena no tiene cuotas.». Los textos que empiezan por `=`, `+`, `-` o `@` se neutralizan para que Excel no los ejecute como fórmula.
- **Nombre del archivo:** `informe-quincena-AAAA-Qn-FECHA.csv`, con la fecha de vencimiento de la quincena (`-vista-previa` si aún no está confirmada).

## 23. Administrador principal, «Acción» en la auditoría y datos del empleado (jefatura)
- **Administrador principal (RF-001, RNF-001):** los permisos del grupo «Administración» —Gestionar usuarios (ver, crear y editar cuentas, sus permisos, habilitarlas o deshabilitarlas) y Consultar la auditoría— son exclusivos del **administrador principal**, la cuenta con rol Administrador marcada en `usuarios.es_principal` (booleana, por defecto `false`; solo puede haber una). Un administrador que no es el principal no los tiene: sus casillas salen deshabilitadas con la nota «Solo el administrador principal gestiona usuarios y auditoría.», el servidor las ignora si llegan, y no entra a Usuarios ni a Auditoría (403 en las rutas, Gates, policies y menú).
- **El principal** tiene siempre esos dos permisos: en su formulario salen marcados y bloqueados, y el servidor los conserva aunque la petición los quite (`Usuario::permisosAsignados()`). No se puede deshabilitar ni dejar de ser Administrador, así que siempre hay un principal activo. El Funcionario sigue sin poder tenerlos (se rechaza), y el resto del sistema de permisos no cambia.
- **Migración:** marca como principal al administrador activo más antiguo (menor fecha de creación y, a igualdad, menor cédula) que no sea el usuario Sistema `0000000000`. La tabla no tiene `id` numérico: la clave es la cédula. **Trabajo futuro:** una pantalla para traspasar el rol de principal; hoy se hace por base de datos.
- **Auditoría:** donde la columna o el filtro se llamaba «Función» ahora dice «Acción». Solo cambia el texto visible; el campo en base de datos sigue siendo `funcion_realizada`.
- **Empleado (nuevo y edición):** la **cédula** solo admite dígitos (el campo borra lo demás, también al pegar; teclado numérico en móviles; el servidor la rechaza si trae otra cosa con «La cédula debe contener solo números…»). El **nombre** no admite números: solo letras con tildes y ñ, espacios, apóstrofos (' o ’), guiones y puntos (p. ej. «María José», «O'Hara», «De la Cruz Jr.»); el campo borra lo demás, también al pegar, y el servidor lo rechaza con un mensaje en español. Si hay otros errores, el formulario conserva lo digitado.

## 24. Lista de préstamos, hoja impresa y quincena en curso (jefatura)
- **Búsqueda en `/prestamos`:** barra «Buscar por cédula o nombre del empleado», con el mismo patrón de Empleados (filtra mientras se escribe, sin recargar, y también por el parámetro `q` de la URL). Muestra los préstamos de los empleados cuyo **nombre contiene** el texto, sin importar mayúsculas ni tildes (ni la ñ), o cuya **cédula empieza** por lo digitado. Vacía muestra la lista normal; sin coincidencias dice «No hay préstamos que coincidan con la búsqueda». El filtro se hace en PHP (igual en MariaDB y SQLite); la lista no pagina. Mismo permiso que antes (`prestamos.ver`).
- **Menú y ayuda:** Pagos → «Pagos y abonos» (antes «Registrar pago o abono»; misma ruta y permisos). La lista dice: «Para registrar un pago, un abono o una cancelación, busque al empleado y entre a «Ver» en su préstamo.»
- **Imprimir el préstamo:** botón «Imprimir» en `prestamos/{id}` (mismo permiso que ver el préstamo). La hoja (A4 vertical, patrón de la hoja del simulador: bordes finos, encabezado de tabla repetido en cada página, pie fijo) trae logo y nombre del Politécnico, «Información del préstamo», datos del empleado (nombre, cédula, tipo de vinculación), del préstamo (modalidad, monto, plazo, tasa, cuota, estado, fecha de desembolso, primera cuota y su cierre de novedades), la tabla de cuotas, los pagos y abonos, los desembolsos y el aviso de cuota excluida si aplica; sin botones ni formularios, y pie con fecha, hora y usuario de la impresión. Son los mismos datos de la pantalla; no cambia ningún cálculo. Los estilos de impresión están en la vista (`prestamos/partials/hoja-impresion.blade.php`).
- **Quincenas:** la pantalla trae un selector con la **quincena en curso** seleccionada por defecto. En curso es la del rango que incluye hoy; si hoy ya pasó su cierre de novedades (está entre su cierre y su fin), la siguiente (`QuincenaService::quincenaEnCurso()`). Cada quincena lleva una etiqueta: «En curso», «Pendiente anterior» o «Confirmada» (esta última prevalece si ya se confirmó). Las demás siguen en las listas y en el selector. No cambian las reglas de cálculo ni de confirmación: una quincena que todavía no empieza no se puede abrir.

## 25. Resumen de pagos: todas las modalidades y detalle por modalidad (jefatura, RF-009)
- **Qué cuenta:** el resumen suma todos los registros de la tabla `pagos` (pago de cuota, abono extraordinario y cancelación total, sin distinguir tipo ni estado del préstamo) agrupados por modalidad. Una modalidad aparece si tiene al menos un pago en el periodo; las que no tienen pagos no aparecen. Los filtros son «Pagado desde» y «Pagado hasta» (fecha del pago); **sin fechas cubre todos los pagos registrados**, no hay un rango escondido, y la pantalla dice el periodo usado («Periodo: todos los pagos registrados», «del 01/05/2026 al 01/07/2026», «desde el …», «hasta el …»).
- **Por qué podía faltar una modalidad (diagnóstico):** no era un error del código ni un filtro oculto. El resumen cuenta solo filas de `pagos`; una modalidad sin ninguna fila de pago no sale (por ejemplo, préstamos que aún no se desembolsan no admiten pagos, o cuya cuota quedó «Pagada» sin que exista un registro de pago, como ocurre con datos de prueba antiguos). Las cuotas marcadas «Pagada» sin registro de pago no cuentan.
- **«Ver detalle»:** cada fila del resumen tiene un botón que abre el detalle de esa modalidad (`reportes/pagos/modalidad/{id}`, mismo permiso `reportes.ver`) con los préstamos de la modalidad que recibieron pagos en el mismo periodo: Empleado, Cédula, N.º de préstamo, Estado, Monto solicitado (el de la solicitud; si el préstamo no tiene solicitud, el monto aprobado), N.º de cuotas (plazo total actual), Valor de la cuota, Cuotas pagadas («X de Y») y Total pagado en el periodo. Ordenado por nombre, con fila de totales (cantidad de préstamos, suma de montos solicitados y total pagado) y una línea con la modalidad y el periodo. El total pagado del detalle coincide con el monto de la modalidad en el resumen.
- **Búsqueda y hoja impresa:** desde 11 préstamos el detalle ofrece la búsqueda en tiempo real por nombre (sin mayúsculas ni tildes) o cédula (prefijo), con el mismo patrón de las otras pantallas; con búsqueda, los totales son los de las filas mostradas. Botón «Imprimir» (hoja A4 horizontal, encabezado de tabla repetido, pie con fecha, hora y usuario; mismo patrón que la hoja del simulador y la del préstamo) y enlace «Volver al resumen» que conserva las fechas. No cambia ningún cálculo.

## 26. Carga de préstamos vigentes (cartera inicial)
Detalle del procedimiento y la plantilla en `docs/carga-prestamos-vigentes.md`.
- **Para qué:** cargar por archivo la cartera de préstamos que ya están vigentes, para practicar y empezar a operar el aplicativo **sin importar nada del AS/400** (fuera de alcance, `docs/restricciones.md`). Solo se carga lo que la persona digita o pega en el CSV.
- **Préstamos históricos:** no se aplican las reglas de capacidad de endeudamiento, antigüedad, salario mínimo ni presupuesto. **Nada de la carga consume ni modifica el presupuesto.**
- **Errores (bloquean toda la carga):** empleado inexistente; modalidad inexistente; monto o plazo inválidos; plazo mayor al máximo de la modalidad; fecha inválida (AAAA-MM-DD); `cuotas_pagadas` mayor al plazo; fila duplicada en el archivo o préstamo igual ya existente (misma cédula, modalidad, monto y fecha de desembolso); docente ocasional (no es beneficiario de crédito del Fondo); año de desembolso sin tasa registrada para la modalidad (salvo que la fila traiga `tasa_anual`).
- **Advertencias (no bloquean):** monto mayor a la cuantía máxima de la modalidad para el tramo salarial del empleado; `saldo_actual` que difiere en más de 1 peso del saldo calculado; fecha de desembolso anterior al registro de la tasa del año (se usa la tasa del año de desembolso); préstamo con todas las cuotas pagadas (queda Vigente con saldo cero); modalidades de vivienda por tramos (se registra un solo desembolso por el 100 %, y el detalle seguirá ofreciendo el tramo 2).
- **Cálculo:** cuota francesa quincenal con `r = (1 + E.A.)^(1/24) − 1`, la tasa del **año del desembolso** para la modalidad (con el tope de vivienda del Acuerdo 11/2023 y los puntos de vehículo por plazo, como en una solicitud nueva) más `puntos_adicionales`; si la fila trae `tasa_anual`, esa tasa se usa tal cual (los puntos no se suman). Fechas de cuota con `CalendarioQuincenalService`: la primera con la misma regla de cierre de novedades del desembolso real, las demás por calendario.
- **Qué crea (todo o nada, en una sola transacción):** por fila, un préstamo `Vigente` con `pendiente_desembolso = false`, `fecha_autorizacion` = fecha del desembolso, `origen = 'carga_inicial'` y `lote_carga`; un desembolso (tramo 1, 100 %) con la fecha dada; la tabla de cuotas completa, con las **primeras N cuotas marcadas «Pagada» sin crear filas de pago ni abonos**.
- **Pagos históricos:** no se reconstruyen. El Resumen de pagos y el Historial de pagos solo cuentan pagos registrados en el aplicativo; las cuotas de la carga marcadas «Pagada» no aparecen ahí. Una vez cargados, los préstamos se comportan como cualquier otro (quincena según su próxima cuota, informe de quincena, pagos, abonos, cancelación).
- **Revertir un lote:** elimina los préstamos del lote con sus cuotas y desembolsos, **solo si ninguno tiene pagos, abonos de cesantías, reliquidaciones, desembolsos adicionales o cancelación** registrados después de la carga; si no, la pantalla explica por qué.
- **Auditoría:** «Carga de préstamos vigentes» (archivo, lote, cantidad) y «Reversión de carga de préstamos vigentes» (lote, cantidad).
- **Permiso:** `prestamos.cargar_vigentes` (exige `prestamos.ver`). Por defecto lo tiene el Administrador y **no** el Funcionario. No cambia que Usuarios y Auditoría sean solo del administrador principal.
