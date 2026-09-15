# Modelo de Datos
Aplicación de Préstamos de Bienestar Social — versión ampliada según RF-001 a RF-045 y RNF-001 a RNF-025.

## 1. Listado de tablas
| Tabla | Propósito |
|---|---|
| usuario | Cuentas de acceso, con rol |
| empleado | Empleados beneficiarios |
| tipo_prestamo | Catálogo de modalidades |
| tasa_int | Tasa vigente por modalidad y año |
| tasa_vehiculo | Tasa especial de vehículo (plazo + primera/segunda solicitud) |
| cuantia_smmlv | Cuantía máxima por tramo de SMMLV y modalidad |
| presupuesto_anual | Presupuesto asignado/comprometido/disponible |
| presupuesto_historial | Historial de modificaciones al presupuesto |
| solicitud_credito | Solicitudes antes de ser aprobadas |
| prestamo | Préstamo aprobado y desembolsado |
| cuota | Tabla de amortización |
| pago | Ledger de pagos |
| desembolso | Desembolsos, incluyendo por tramos |
| proveedor | Terceros beneficiarios de un desembolso |
| reliquidacion | Reliquidaciones (abono, retiro, cambio de condiciones) |
| abono_cesantias | Abonos bianuales de cesantías |
| auditoria | Registro de auditoría |

## 2. Estructura de cada tabla

### usuario
| Campo | Tipo | Nulo | Descripción |
|---|---|---|---|
| cedula | VARCHAR(20) | No (PK, FK -> empleado.cedula) | Todo usuario corresponde a un empleado |
| nombre_completo | VARCHAR(150) | No | |
| contrasena_hash | VARCHAR(255) | No | Hash, nunca texto plano |
| rol | VARCHAR(30) | No | 'ADMINISTRADOR' \| 'FUNCIONARIO_BIENESTAR' |
| habilitado | BOOLEAN | No | Para deshabilitar cuentas |

> Solo existen dos roles fijos, con diferencias fijas (el Funcionario tiene todo menos gestión de usuarios). No se modela como tabla de permisos independiente por ahora.

### empleado
| Campo | Tipo | Nulo | Descripción |
|---|---|---|---|
| cedula | VARCHAR(20) | No (PK) | |
| nombre_completo | VARCHAR(150) | No | |
| tipo_vinculacion | VARCHAR(30) | No | Empleado / Docente |
| cargo | VARCHAR(100) | Sí | |
| dependencia | VARCHAR(100) | Sí | |
| salario | DECIMAL(18,2) | No | |
| fecha_vinculacion | DATE | Sí | |
| estado | VARCHAR(20) | No | Activo / Retirado |
| capacidad_endeudamiento | DECIMAL(18,2) | Sí | 50% del salario neto |

### tipo_prestamo
| Campo | Tipo | Nulo | Descripción |
|---|---|---|---|
| id_tipo | INT | No (PK) | |
| nombre | VARCHAR(60) | No | |
| descripcion | TEXT | Sí | |
| plazo_maximo | INT | No | |
| habilitada | BOOLEAN | No | Deshabilitar si se agotó presupuesto |
| fuente_normativa | VARCHAR(100) | Sí | |

### tasa_int
| Campo | Tipo | Nulo | Descripción |
|---|---|---|---|
| id_tasa | INT | No (PK) | |
| id_tipo | INT | No (FK) | |
| anio | INT | No | |
| tasa_anual | DECIMAL(7,4) | No | IPC año anterior, tope 5% |
| fuente_normativa | VARCHAR(100) | Sí | |

> Tabla de solo-inserción: cada año se agrega una fila nueva, nunca se edita una existente.

### tasa_vehiculo
| Campo | Tipo | Nulo | Descripción |
|---|---|---|---|
| id_tasa_vehiculo | INT | No (PK) | |
| plazo_anios | INT | No | 3/4/5/7 |
| es_primera_solicitud | BOOLEAN | No | |
| puntos_adicionales_ipc | DECIMAL(5,2) | No | |

### cuantia_smmlv
| Campo | Tipo | Nulo | Descripción |
|---|---|---|---|
| id_cuantia | INT | No (PK) | |
| id_tipo | INT | No (FK) | |
| tramo_desde_smmlv | DECIMAL(6,2) | No | |
| tramo_hasta_smmlv | DECIMAL(6,2) | Sí | Nulo = sin tope superior |
| cuantia_maxima_smmlv | DECIMAL(8,2) | No | |

### presupuesto_anual
| Campo | Tipo | Nulo | Descripción |
|---|---|---|---|
| id_presupuesto | INT | No (PK) | |
| anio | INT | No | |
| id_tipo | INT | No (FK) | |
| monto_asignado | DECIMAL(18,2) | No | |
| monto_comprometido | DECIMAL(18,2) | No | |
| monto_disponible | DECIMAL(18,2) | No | Calculado |

### presupuesto_historial
| Campo | Tipo | Nulo | Descripción |
|---|---|---|---|
| id_historial | INT | No (PK) | |
| id_presupuesto | INT | No (FK) | |
| monto_anterior | DECIMAL(18,2) | No | |
| monto_nuevo | DECIMAL(18,2) | No | |
| motivo | TEXT | Sí | |
| cedula_usu | VARCHAR(20) | No (FK) | |
| fecha | DATETIME | No | |

### solicitud_credito
| Campo | Tipo | Nulo | Descripción |
|---|---|---|---|
| id_solicitud | INT | No (PK) | |
| cedula_empleado | VARCHAR(20) | No (FK) | |
| id_tipo | INT | No (FK) | |
| monto_solicitado | DECIMAL(18,2) | No | |
| plazo_solicitado | INT | No | |
| fecha_solicitud | DATE | No | |
| estado | VARCHAR(20) | No | Radicada / Aprobada / Rechazada |
| fecha_decision | DATE | Sí | |
| motivo_rechazo | TEXT | Sí | |

> La votación individual del Comité se gestiona por correo, fuera del sistema. El sistema solo registra el resultado final. Notificación de rechazo por correo.

### prestamo
| Campo | Tipo | Nulo | Descripción |
|---|---|---|---|
| id_prestamo | INT | No (PK) | |
| id_solicitud | INT | Sí (FK) | |
| cedula_empleado | VARCHAR(20) | No (FK) | |
| id_tipo | INT | No (FK) | |
| id_tasa | INT | Sí (FK) | Nulo si vehículo |
| monto_aprobado | DECIMAL(18,2) | No | |
| num_cuotas | INT | No | |
| cuota_periodica | DECIMAL(18,2) | No | |
| fecha_autorizacion | DATE | No | |
| estado | VARCHAR(20) | No | Vigente / Cancelado / En mora |

### cuota
| Campo | Tipo | Nulo | Descripción |
|---|---|---|---|
| id_cuota | INT | No (PK) | |
| id_prestamo | INT | No (FK) | |
| numero_cuota | INT | No | |
| fecha_vencimiento | DATE | No | |
| valor_capital | DECIMAL(18,2) | No | |
| valor_interes | DECIMAL(18,2) | No | |
| valor_total | DECIMAL(18,2) | No | |
| estado | VARCHAR(20) | No | Pendiente / Pagada / Vencida |

### pago
| Campo | Tipo | Nulo | Descripción |
|---|---|---|---|
| id_pago | INT | No (PK) | |
| id_prestamo | INT | No (FK) | |
| id_cuota | INT | Sí (FK) | Nulo si es abono directo a capital |
| fecha_pago | DATE | No | |
| monto | DECIMAL(18,2) | No | |
| tipo | VARCHAR(20) | No | Regular / Abono extraordinario / Cancelación |
| monto_aplicado_interes | DECIMAL(18,2) | No | Se aplica primero a intereses |
| monto_aplicado_capital | DECIMAL(18,2) | No | |
| cedula_usu | VARCHAR(20) | No (FK) | Quien registró el pago |

### desembolso
| Campo | Tipo | Nulo | Descripción |
|---|---|---|---|
| id_desembolso | INT | No (PK) | |
| id_prestamo | INT | No (FK) | |
| id_proveedor | VARCHAR(20) | Sí (FK) | Nulo si va directo al empleado |
| numero_tramo | INT | No | 1 o 2; 1 en desembolso único |
| porcentaje | DECIMAL(5,2) | No | 80/20 o 100% |
| fecha | DATE | Sí | |
| valor | DECIMAL(18,2) | No | |
| requiere_inspeccion | BOOLEAN | No | |
| inspeccion_aprobada | BOOLEAN | Sí | |
| estado | VARCHAR(20) | No | Pendiente / Realizado |

### proveedor
| Campo | Tipo | Nulo | Descripción |
|---|---|---|---|
| id_proveedor | VARCHAR(20) | No (PK) | |
| nit | VARCHAR(20) | No | |
| nombre_empresa | VARCHAR(150) | No | |
| direccion | VARCHAR(200) | Sí | |
| num_cuenta_bancaria | VARCHAR(30) | Sí | |

### reliquidacion
| Campo | Tipo | Nulo | Descripción |
|---|---|---|---|
| id_reliquidacion | INT | No (PK) | |
| id_prestamo | INT | No (FK) | |
| motivo | VARCHAR(30) | No | Abono extraordinario / Cambio de condiciones / Retiro |
| saldo_anterior | DECIMAL(18,2) | No | |
| saldo_nuevo | DECIMAL(18,2) | No | |
| tasa_aplicada | DECIMAL(7,4) | Sí | Ej. tasa DTF en retiro |
| fecha | DATE | No | |
| cedula_usu | VARCHAR(20) | No (FK) | |

### abono_cesantias
| Campo | Tipo | Nulo | Descripción |
|---|---|---|---|
| id_abono | INT | No (PK) | |
| id_prestamo | INT | No (FK) | Solo préstamos de vivienda |
| anio | INT | No | |
| porcentaje | DECIMAL(5,2) | No | 50% |
| monto | DECIMAL(18,2) | No | |
| fecha_abono | DATE | No | Febrero, bianual |

### auditoria
| Campo | Tipo | Nulo | Descripción |
|---|---|---|---|
| id_audi | INT | No (PK) | |
| cedula_usu | VARCHAR(20) | No (FK) | |
| fecha | DATE | No | |
| hora | TIME | No | |
| funcion_realizada | VARCHAR(100) | No | |
| detalle | TEXT | Sí | |

## 3. Puntos pendientes
- Confirmar si la modalidad de "seguros" requiere tabla de cuantías propia.
- Sistema de puntaje de convocatorias (Art. 17): no automatizado — confirmar si se necesita.
- Detalle del cálculo de mora según el Manual de Cartera institucional (no revisado).
