# Reglas de Negocio
Aplicación de Préstamos de Bienestar Social — Politécnico Colombiano Jaime Isaza Cadavid

Fuente: Acuerdo Directivo No. 19 del 12 de octubre de 2022 (reglamento del Programa de Bienestar Social) y Acuerdo Directivo No. 11 del 15 de noviembre de 2023 (modifica la tasa de interés del Acuerdo 19). Se complementa con lo confirmado en la entrevista con el área de Bienestar Social y el manual de captura de novedades del AS/400.

## 1. Tasa de interés
- La tasa de interés de los créditos de vivienda corresponde al IPC del año inmediatamente anterior, certificado por el DANE, sin superar el 5% anual (Acuerdo 11/2023, Art. 1 y 2, que modifica los artículos 10 y 14 del Acuerdo 19/2022).
- La tasa se fija con base en el año en que se presenta la solicitud del crédito, y no cambia posteriormente para ese préstamo.
- La tasa es el IPC del año anterior al año de la **solicitud**, no del desembolso. Una solicitud puede desembolsarse en otro año (ejemplo confirmado con documentos reales: solicitud en 2018, desembolso en febrero de 2019, tasa de 2018). Por eso la fecha de solicitud y la de desembolso se guardan por separado.
- **Tope del 5 % anual (decidido, Acuerdo 11/2023):** aplica a las cinco modalidades de vivienda (compra, construcción, cancelación total de hipoteca o deshipoteca, reparaciones o mejoras y cambio de vivienda; el código las reconoce porque su nombre empieza por «Vivienda») y a las solicitudes presentadas **desde el 15 de noviembre de 2023**, fecha de expedición del Acuerdo; las anteriores conservan su tasa y no se reajusta nada. Vehículo (IPC más puntos por plazo), educación y calamidad no tienen tope.
- **Cómo se aplica:** el IPC registrado en `tasas_int` no se modifica; el tope se aplica al **leer** la tasa: tasa efectiva = mínimo entre el IPC registrado y el 5 %. Un IPC de 5,20 % da 5 %; uno de 4,80 % queda en 4,80 %; uno de exactamente 5 % no se limita. El tope (`TASA_VIVIENDA_TOPE`, 0,05) y la fecha de vigencia (`TASA_VIVIENDA_VIGENTE_DESDE`, 2023-11-15) están en `config/prestamos.php`.
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
- No se tienen en cuenta las deducciones de seguridad social ni retención en la fuente para este cálculo.
- La cuantía del préstamo está sujeta a esta capacidad de endeudamiento, sin superar los topes máximos por modalidad.
- **Cómo lo aplica el sistema:** la cuota de la solicitud (cuota quincenal × 2, o sea la cuota mensual) debe caber en la capacidad **disponible** del empleado = capacidad total − capacidad comprometida. La comprometida es la suma de las cuotas quincenales de sus préstamos en estado Vigente y En mora × 2; los cancelados no cuentan, y un préstamo recién aprobado entra a la cuenta de inmediato. La misma cuenta la usan la aprobación de solicitudes y el simulador. El rechazo informa la capacidad total, la comprometida, la disponible y la cuota solicitada.
- **Por confirmar (reunión 14-oct):** cómo se descuentan otras deducciones (judiciales, voluntarias, etc.). Hoy el sistema solo resta los préstamos que maneja.
- **Simulador de crédito:** muestra dos capacidades. La *guardada* del empleado (la que usa la aprobación) y una *estimada* = 50 % de (salario − salud 4 % − pensión 4 % − fondo de solidaridad pensional − retención en la fuente manual); el interruptor `SIMULADOR_RESTAR_DESCUENTOS_LEY` (config/prestamos.php, activo por ahora) decide cuál manda en el veredicto, y a la elegida se le resta lo comprometido. Si las dos dan un veredicto distinto, el simulador lo avisa; la aprobación no cambia. **Por confirmar con Bienestar:** si se mantienen los descuentos de ley, dado que el Acuerdo 19/2022 (art. 10, par. 3) dice que seguridad social y retención no se cuentan. Los porcentajes y tramos de solidaridad deben verificarse con la norma vigente.
- **Por confirmar con Bienestar:** el seguro no entra en la comparación de capacidad (ni en la aprobación ni en el simulador), aunque el art. 10, par. 3 habla de «capital, intereses y seguros».

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

## 8. Aplicación de pagos y mora
- Desde la primera cuota se amortiza a capital; cuotas fijas salvo abonos extraordinarios (Art. 12, parágrafo 2).
- **Fechas de vencimiento (decidido, Bienestar):** por calendario, el **día 15 y el último día real de cada mes** (30 o 31 según el mes; 28 o 29 en febrero según sea bisiesto). Cada fecha se calcula por su número de vencimiento, no sumando meses, para que el fin de mes no se corra (`CalendarioQuincenalService::vencimientosDesde()`).
- **Primera cuota:** la fecha base sigue siendo la autorización + 15 días y la primera cuota vence en el primer vencimiento (15 o fin de mes) **igual o posterior** a esa fecha base; las demás siguen la secuencia sin saltos. Cambiar la fecha base al desembolso es un cambio aparte (pendiente).
- Las cuotas reales y la tabla del simulador usan el mismo generador (`PrestamoService::fechasDeVencimiento()`), así que sus fechas coinciden. No se reajustaron préstamos existentes: no hay datos reales cargados y los de prueba se recrean con el seeder.
- Una reliquidación no mueve fechas: las cuotas pendientes que se conservan mantienen la suya (ver §9), así que la secuencia 15 / fin de mes se conserva.
- **Cierre de novedades de nómina (provisional):** `QUINCENA_CIERRE_NOVEDADES_DIAS_ANTES` (por defecto 2) días calendario antes de cada vencimiento. Es solo informativo —se muestra, marcado «provisional», en la vista previa de la quincena— y no bloquea ninguna operación. Pendiente de la respuesta de Bienestar.
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
- **Simulador:** muestra el seguro aparte como monto × 0,0003231 (`SEGURO_TASA_SOBRE_MONTO`) y lo suma a la cuota quincenal. **Por confirmar con Bienestar:** la tarifa correcta (difiere de la de arriba), su periodicidad y si se calcula sobre el monto o sobre el saldo.
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
