# Guion de la demostración
Aplicación de Préstamos de Bienestar Social — datos ficticios para mostrar el simulador y las solicitudes

Los datos los crea `DemoSeeder` (`database/seeders/DemoSeeder.php`). Son personas inventadas con cédulas del rango reservado **9000000001 a 9000000020**; hoy se usan las ocho primeras. El seeder no forma parte de `php artisan db:seed` por defecto.

## 1. Cargar los datos de demostración
Con la base de datos ya sembrada (usuario Administrador y modalidades):

```sh
php artisan db:seed --class=DemoSeeder
```

- Se puede correr más de una vez: un escenario cuyo empleado ya existe se salta y no se duplica.
- No modifica ningún dato existente. Solo agrega, si faltan para el año actual, el SMMLV, la tasa (IPC 5 %) de cada modalidad y su presupuesto, y la matriz de puntos de vehículo si estaba vacía.
- Los préstamos se crean con los servicios reales (solicitud, aprobación, desembolso y cancelación), así que su tabla de cuotas es la que generaría la aplicación.
- No se ejecuta en producción.

## 2. Escenarios
Las cifras de la última columna usan el SMMLV de 2026 (1.750.905) y un IPC del 5 %; con otros valores en la base cambian los montos, pero no los resultados cualitativos. Todos se consultan en **Simulador de crédito** escribiendo la cédula.

| Escenario | Cédula | Qué mostrar | Resultado esperado |
|---|---|---|---|
| a. Antiguo, salario medio, sin préstamos | 9000000001 | Escribir la cédula; mirar la capacidad y elegir Vivienda — compra de inmueble y luego Vehículo | Sin tabla de préstamos. Capacidad total = disponible ($2.626.500). Vivienda: monto máximo ≈ $227.617.650 (limitado por la cuantía). Vehículo: tasa 8 % (IPC + 3, primera solicitud), monto máximo ≈ $87.545.250 |
| b. Antiguo con préstamo vigente de calamidad | 9000000002 | Ver la tabla de préstamos y cómo bajó la capacidad; elegir Vivienda — compra de inmueble | Un préstamo de calamidad. Capacidad comprometida ≈ $1.448.817 y disponible ≈ $1.177.683. Vivienda: monto máximo ≈ $180.302.183, ahora **limitado por la capacidad** (sin el préstamo sería $227.617.650) |
| c. Capacidad agotada | 9000000003 | Ver los dos préstamos vigentes y elegir cualquier modalidad | Disponible $0 (la capacidad de este empleado se fijó igual a lo comprometido: $1.047.601). Todas las modalidades responden «La capacidad de endeudamiento está agotada…» |
| d. Menos de un año de servicio | 9000000004 | Elegir Vivienda — compra de inmueble, luego Vehículo y luego Educación | Vivienda y Vehículo: «No cumple el tiempo de servicio exigido. Para esta modalidad se exige al menos un año de servicio; el empleado lleva 6 meses.» Educación sí puede (≈ $30.640.837) |
| e. Retirado | 9000000005 | Escribir la cédula | Se muestran sus datos y el aviso «El empleado está retirado y no puede solicitar un crédito.»; no aparecen modalidades |
| f. Salario alto (más de 8 SMMLV) | 9000000006 | Elegir Vivienda — compra de inmueble | Salario $17.509.000. Monto máximo ≈ $385.199.100 (220 SMMLV, el tramo de más de 8 SMMLV), limitado por la cuantía |
| g. Vehículo ya pagado | 9000000007 | Elegir Vehículo y comparar con el escenario a | Sin préstamos vigentes (el de vehículo está cancelado). Tasa **10 %** (IPC + 5, segunda solicitud) frente al 8 % del escenario a. Monto máximo ≈ $87.545.250 |
| h. Solicitud pendiente con un documento | 9000000008 | En **Solicitudes**, abrir la solicitud de Hugo Demo Solicitud; ver la sección «Documentos», descargar `cedula-demo.pdf` | Solicitud Radicada de Educación con un documento («Cédula»). Se puede aprobar o rechazar, subir otro documento o eliminarlo |

Para entrar a la aplicación se usa un usuario existente (por ejemplo el Administrador sembrado `1000000000`). Las acciones que se hagan sobre los datos de demostración (aprobar la solicitud del escenario h, subir documentos) también quedan en la base y las retira el comando de la sección 3.

## 3. Quitar los datos de demostración

```sh
php artisan db:seed --class=DemoLimpiezaSeeder
```

Borra únicamente a los empleados con cédula entre 9000000001 y 9000000020 y lo que depende de ellos: solicitudes, documentos (también los archivos guardados), préstamos, cuotas, pagos, desembolsos, reliquidaciones y abonos de cesantías. Devuelve al presupuesto lo que esos préstamos habían comprometido. No toca ningún otro dato.

Qué queda después de quitarlos:
- Los registros de auditoría y el historial del presupuesto, que son un registro permanente.
- Los parámetros del año (SMMLV, tasas, presupuestos) que el seeder hubiera tenido que agregar; son configuración normal de la aplicación.

Si se prefiere hacerlo a mano, no basta con borrar los empleados: los préstamos, cuotas, pagos y desembolsos tienen llaves foráneas con restricción y el presupuesto comprometido tendría que ajustarse. Por eso se recomienda el comando.
