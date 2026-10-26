# SDD: diseño de la solución

**Autor:** Isaac Moreno Moreno

Documento de diseño previo a la implementación, actualizado con el resultado final. Recoge requisitos, diseño y trazabilidad. El detalle de uso y de las decisiones está en los documentos de cada parte, para no repetirlo aquí:

- Parte 1: [`parte1/SOLUCION.md`](../parte1/SOLUCION.md)
- Parte 2: [`parte2/SOLUCION.md`](../parte2/SOLUCION.md)
- Desglose en tareas: [`TASKS.md`](TASKS.md)

---

## 1. Requisitos

| ID | Parte | Requisito | Origen |
|----|-------|-----------|--------|
| R1 | 1 | API REST que reciba CUPS, fecha, lectura en kWh y tipo (real o estimada) | Enunciado |
| R2 | 1 | Rechazar contratos de baja y fechas fuera de vigencia, indicando que no se guarda y por qué | Enunciado |
| R3 | 1 | Recibir la misma lectura varias veces no genera duplicados | Enunciado |
| R4 | 1 | Validar el formato de la petición y responder con errores claros | Decisión propia |
| R5 | 1 | Detectar lecturas contradictorias (misma fecha y tipo, otro valor) | Decisión propia |
| R6 | 2 | Explicar qué pasó y a cuántos clientes afecta, con datos | Enunciado |
| R7 | 2 | Proponer una corrección segura | Enunciado |
| R8 | 2 | Evitar que se repita el mes siguiente | Enunciado |
| R9 | 2 | Informe para el responsable de Facturación, con decisiones pendientes | Enunciado |
| R10 | 1 y 2 | Reproducible con `docker compose up` sobre una base de datos recién creada | Enunciado |

---

## 2. Diseño de la parte 1

### 2.1 Componentes

| Componente | Responsabilidad |
|------------|-----------------|
| `LecturaController` (Yii2) | Validar el formato, llamar al caso de uso y traducir errores a códigos HTTP |
| `RegistrarLecturaHandler` | Reglas de negocio. Solo depende de las interfaces de repositorio |
| `SqlServerContratoRepository`, `SqlServerLecturaRepository` | Consultas SQL con PDO; transacción y bloqueo para la idempotencia |
| `Cups`, `LecturaKwh`, `TipoLectura` | Tipos que validan su formato al construirse |

Objetivo de la separación: probar todas las reglas sin base de datos. El coste (más estructura de la necesaria para un endpoint) está asumido y explicado en `parte1/SOLUCION.md` §3.4.

### 2.2 Flujo de una petición

```
POST /api/lecturas
  │
  ▼
LecturaController
  ├─ formato incorrecto .............................. 400 VALIDACION
  ▼
RegistrarLecturaHandler
  ├─ fecha > hoy ..................................... 422 FECHA_FUTURA
  ├─ contratos del CUPS
  │    ├─ ninguno .................................... 422 CUPS_NO_ENCONTRADO
  │    ├─ ninguno vigente en la fecha ................ 422 FECHA_FUERA_VIGENCIA
  │    └─ vigente pero de baja ....................... 422 CONTRATO_BAJA
  └─ transacción (UPDLOCK, HOLDLOCK sobre la clave contrato + fecha + tipo)
       ├─ existe con el mismo kWh .................... 200 duplicado
       ├─ existe con otro kWh ........................ 409 LECTURA_CONFLICTIVA
       └─ no existe .................................. 201 guardada
```

### 2.3 Decisiones clave

| Decisión | Alternativa descartada | Motivo |
|----------|------------------------|--------|
| Contrato vigente en la fecha de la lectura | Contrato activo hoy | Un CUPS cambia de titular; las lecturas se imputan al titular de esa fecha |
| Idempotencia por datos de negocio (contrato, fecha, tipo) | Cabecera `Idempotency-Key` | La distribuidora no tiene que generar identificadores |
| Bloqueo en transacción | Índice único | Los datos ya contienen un duplicado histórico (contrato 3) |
| No validar que el acumulado no retroceda | Rechazar lecturas menores | El contador da la vuelta y las estimadas se regularizan |
| Yii2 solo en la capa HTTP | Controlador con ActiveRecord | Probar las reglas sin base de datos |

---

## 3. Diseño de la parte 2

### 3.1 Método de investigación

1. Partir de los síntomas (facturas duplicadas e importes altos) y del log de Jenkins (timeout).
2. Formular hipótesis y contrastar cada una con una consulta de diagnóstico (scripts `01`–`08`).
3. Cuantificar el alcance por causa y cruzarlo con las 7 reclamaciones.
4. Corregir solo lo que tiene una corrección segura y reversible; dejar a negocio lo que afecta a facturas emitidas.

| Hipótesis | Resultado |
|-----------|-----------|
| H1. Ejecución cortada y relanzada sin limpiar | Confirmada |
| H2. El SP no comprueba si ya existe factura | Confirmada |
| H3. Lecturas duplicadas en septiembre | Confirmada (recarga del fichero de SUR) |
| H4. Precio erróneo en septiembre | Confirmada en otra forma: el SP no prorratea el cambio |
| H5. El cursor del SP explica el timeout | Descartada |

### 3.2 Estrategia de corrección y prevención

| Principio | Aplicación |
|-----------|------------|
| No borrar datos | Facturas anuladas (`estado = 'anulada'`); lecturas movidas a `lecturas_descartadas` |
| No tocar lo que es decisión de negocio | Los importes de facturas emitidas no se reescriben; el script `13` calcula la propuesta |
| Scripts idempotentes y verificables | Cada corrección comprueba su resultado antes del `COMMIT`; ejecutarlos dos veces no cambia nada |
| Prevenir en dos niveles | En el proceso (SP con transacción, guarda y prorrateo) y en la base de datos (índices únicos) |
| Cambio mínimo en el SP | Se mantiene el cursor; el problema no era el rendimiento |

---

## 4. Trazabilidad

| Requisito | Implementación | Tareas | Verificación |
|-----------|----------------|--------|--------------|
| R1 | `LecturaController`, ruta en `config/web.php` | T1, T4.3 | `smoke.sh` (alta nueva) |
| R2 | `RegistrarLecturaHandler`, `Contrato::estaVigenteEn` | T2, T3.2 | Tests T5.5 y T5.6; `smoke.sh` (422) |
| R3 | `findByClave` con bloqueo + `transaccional()` | T2.4, T4.2 | Tests T5.2; `smoke.sh` (reintento y 5 envíos simultáneos) |
| R4 | `LecturaController::validateRequest` y tipos | T2.1, T4.3 | `smoke.sh` (400) |
| R5 | `LecturaConflictivaException` | T2.3, T3.2 | Test T5.3; `smoke.sh` (409) |
| R6 | Scripts `01`–`08` y `13` | T8 | `run.sh --diagnostico` |
| R7 | Scripts `10` y `11` | T9 | `run.sh`: 61 facturas anuladas, 44 lecturas retiradas |
| R8 | Scripts `12` y `20` | T10 | `run.sh`: relanzar no crea facturas ni duplicados |
| R9 | `parte2/SOLUCION.md` §6 | T11 | Revisión del informe |
| R10 | `compose.yaml`, `smoke.sh`, `run.sh` | T1, T12 | Ejecución completa sobre base de datos recién creada |

---

## 5. Riesgos y limitaciones

| Riesgo | Mitigación |
|--------|------------|
| La API no tiene autenticación | Fuera de alcance v1; primer siguiente paso |
| La lectura de cierre (fecha = baja, recibida después) se rechaza | Pendiente de negocio; el cambio es una condición en el handler |
| Un timeout de Jenkins aborta el lote sin pasar por el `CATCH` | Las facturas se deshacen igualmente; el log puede quedar `EN_CURSO` y la comprobación post-facturación lo detecta |
| Las ejecuciones deshechas dejan huecos en la numeración de facturas | Revisar con Administración |
| Se desconoce la causa del timeout del 5/10 | Con los cambios del SP, aunque se repita ya no genera duplicados |
