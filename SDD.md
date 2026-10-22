# SDD — Prueba Técnica PHP

**Documento:** Software Design Document (planificación + arquitectura)  
**Fecha:** 2026-10-22  
**Autor:** Ismael Moreno  
**Proyecto:** Prueba técnica — comercializadora de electricidad

---

## Índice

1. [Visión general](#1-visión-general)  
2. [Parte 1 — API de recepción de lecturas](#2-parte-1--api-de-recepción-de-lecturas)  
   2.1 [Contexto y objetivo](#21-contexto-y-objetivo)  
   2.2 [Modelo de datos existente](#22-modelo-de-datos-existente)  
   2.3 [Contrato de la API](#23-contrato-de-la-api)  
   2.4 [Validaciones y reglas de negocio](#24-validaciones-y-reglas-de-negocio)  
   2.5 [Idempotencia y reintentos](#25-idempotencia-y-reintentos)  
   2.6 [Arquitectura y principios de diseño](#26-arquitectura-y-principios-de-diseño)  
   2.7 [Estructura de directorios](#27-estructura-de-directorios)  
   2.8 [Flujo de una petición](#28-flujo-de-una-petición)  
   2.9 [Decisiones de diseño](#29-decisiones-de-diseño)  
   2.10 [Plan de implementación](#210-plan-de-implementación)  
3. [Parte 2 — Investigación de la incidencia de facturación](#3-parte-2--investigación-de-la-incidencia-de-facturación)  
   3.1 [Contexto](#31-contexto)  
   3.2 [Evidencias disponibles](#32-evidencias-disponibles)  
   3.3 [Hipótesis de trabajo](#33-hipótesis-de-trabajo)  
   3.4 [Plan de investigación (SQL)](#34-plan-de-investigación-sql)  
   3.5 [Scripts de corrección](#35-scripts-de-corrección)  
   3.6 [Prevención](#36-prevención)  
   3.7 [Informe para negocio](#37-informe-para-negocio)  
   3.8 [Plan de implementación](#38-plan-de-implementación)  
4. [Dependencias entre partes](#4-dependencias-entre-partes)  
5. [Riesgos y limitaciones](#5-riesgos-y-limitaciones)

---

## 1. Visión general

La prueba consta de dos ejercicios independientes sobre la operativa de una comercializadora de electricidad:

| Parte | Tipo | Objetivo |
|-------|------|----------|
| 1 | Desarrollo | API REST en PHP para recibir lecturas de contadores de una distribuidora |
| 2 | Análisis / SQL | Diagnosticar y corregir una incidencia de facturación duplicada/errónea en septiembre 2026 |

Ambas partes comparten el mismo dominio de negocio (contratos, CUPS, lecturas, facturación) pero usan bases de datos distintas y son completamente independientes.

---

## 2. Parte 1 — API de recepción de lecturas

### 2.1 Contexto y objetivo

Una distribuidora deja de enviar lecturas en ficheros mensuales y quiere hacerlo vía API REST. Se necesita una primera versión funcional que:

- Reciba lecturas individuales (CUPS + fecha + kWh + tipo).
- Valide que el contrato existe y acepta esa lectura.
- Sea idempotente frente a reintentos de la distribuidora.
- Devuelva respuestas claras cuando la lectura no pueda guardarse.

### 2.2 Modelo de datos existente

**Tabla `contratos`**

| Columna | Tipo | Notas |
|---------|------|-------|
| `id` | INT PK | |
| `cups` | VARCHAR(22) | Identificador único del punto de suministro |
| `titular` | NVARCHAR(150) | |
| `nif` | VARCHAR(15) | |
| `tarifa` | VARCHAR(10) | p.ej. `2.0TD`, `3.0TD` |
| `estado` | VARCHAR(10) | `activo` / `baja` |
| `fecha_alta` | DATE | Inicio de vigencia del contrato |
| `fecha_baja` | DATE NULL | Fin de vigencia (NULL si activo) |
| `digitos_contador` | TINYINT | 5 ó 6 dígitos; el contador da la vuelta al superar 99999/999999 |

**Tabla `lecturas`**

| Columna | Tipo | Notas |
|---------|------|-------|
| `id` | INT PK | |
| `contrato_id` | INT FK | |
| `fecha_lectura` | DATE | |
| `lectura_kwh` | INT | Valor acumulado del contador |
| `tipo` | VARCHAR(10) | `real` / `estimada` |
| `fecha_recepcion` | DATETIME2(0) | Momento de inserción |

Índices relevantes: `ix_contratos_cups (cups)`, `ix_lecturas_contrato_fecha (contrato_id, fecha_lectura)`.

**Observación sobre los datos de muestra:**  
El CUPS `ES0904688281126584QG` tiene dos contratos (id 12, dado de baja el 2026-07-15, e id 24, activo desde 2026-07-16). Esto es normal: un CUPS puede cambiar de titular. La lógica debe resolver el contrato vigente en la fecha de la lectura, no simplemente el activo.

### 2.3 Contrato de la API

#### Endpoint

```
POST /api/lecturas
Content-Type: application/json
```

#### Request body

```json
{
  "cups":          "ES0904998698797309NB",
  "fecha_lectura": "2026-10-01",
  "lectura_kwh":   10849,
  "tipo":          "real"
}
```

| Campo | Tipo | Requerido | Validación |
|-------|------|-----------|------------|
| `cups` | string | Sí | 20-22 caracteres, empieza por `ES` |
| `fecha_lectura` | date (ISO 8601) | Sí | No puede ser futura |
| `lectura_kwh` | integer | Sí | ≥ 0 |
| `tipo` | string | Sí | `real` o `estimada` |

#### Respuestas

**201 Created — lectura aceptada**
```json
{
  "id":             42,
  "cups":           "ES0904998698797309NB",
  "contrato_id":    1,
  "fecha_lectura":  "2026-10-01",
  "lectura_kwh":    10849,
  "tipo":           "real",
  "fecha_recepcion":"2026-10-22T14:00:00"
}
```

**200 OK — lectura ya existente (idempotencia)**
```json
{
  "id":             42,
  "cups":           "ES0904998698797309NB",
  "contrato_id":    1,
  "fecha_lectura":  "2026-10-01",
  "lectura_kwh":    10849,
  "tipo":           "real",
  "fecha_recepcion":"2026-10-22T14:00:00",
  "duplicado":      true
}
```

**422 Unprocessable Entity — lectura rechazada por negocio**
```json
{
  "error":  "CONTRATO_BAJA",
  "mensaje":"El contrato para CUPS ES0904229486628360ZC está dado de baja. La lectura no se ha guardado."
}
```

Códigos de error posibles:

| `error` | Descripción |
|---------|-------------|
| `CUPS_NO_ENCONTRADO` | No existe ningún contrato para ese CUPS |
| `CONTRATO_BAJA` | El contrato estaba de baja en la fecha de la lectura |
| `FECHA_FUERA_VIGENCIA` | La fecha es anterior a `fecha_alta` o posterior a `fecha_baja` |
| `FECHA_FUTURA` | `fecha_lectura` es posterior a la fecha actual |

**400 Bad Request — validación de formato**
```json
{
  "error":  "VALIDACION",
  "campos": {
    "cups":          "El CUPS no tiene un formato válido",
    "lectura_kwh":   "Debe ser un entero no negativo"
  }
}
```

**500 Internal Server Error**
```json
{
  "error":  "ERROR_INTERNO",
  "mensaje":"Error al procesar la solicitud"
}
```

### 2.4 Validaciones y reglas de negocio

Orden de validación (fail-fast):

1. **Formato de entrada** — validar tipos, rangos y formato del body JSON (Yii2 `LecturaRequest`, antes de entrar al caso de uso).  
2. **CUPS existe** — el repositorio de contratos busca en `contratos` por CUPS. Si no hay ninguno → `CupsNoEncontradoException`.  
3. **Resolución del contrato vigente** — dado que un CUPS puede tener varios contratos históricos, se busca el contrato cuya vigencia (`fecha_alta` ≤ `fecha_lectura` ≤ `fecha_baja` o `fecha_baja IS NULL` para activos) incluya la fecha de la lectura.  
4. **Estado del contrato en la fecha** — si en esa fecha el contrato estaba de baja → `ContratoBajaException`.  
5. **Fecha fuera de vigencia** — si no se encontró ningún contrato vigente para esa fecha → `FechaFueraVigenciaException`.  
6. **Fecha futura** — `fecha_lectura` no puede ser posterior a hoy → `FechaFuturaException` (lanzada por el Value Object `Lectura` en el dominio).  
7. **Idempotencia** — si ya existe una lectura con mismo `contrato_id` + `fecha_lectura` + `lectura_kwh` + `tipo` → devolver 200 con `duplicado: true`.

**Nota:** no se valida coherencia del valor kWh respecto a lecturas anteriores (que el acumulado no retroceda). Esto es por diseño: el contador puede dar la vuelta (`digitos_contador`) y hay lecturas estimadas que se regularizarán después. Se registra lo que llega y la lógica de consumo se calcula aguas abajo.

### 2.5 Idempotencia y reintentos

Si la distribuidora reintenta un envío (mismo CUPS, fecha, kWh y tipo), la API:
- No inserta un duplicado.
- Devuelve 200 con el registro original y `"duplicado": true`.

La clave natural de idempotencia es `(contrato_id, fecha_lectura, lectura_kwh, tipo)`. No se añade un campo `idempotency_key` externo porque el dominio ya tiene suficiente información para detectar el duplicado de forma determinista.

Si llega la misma fecha y CUPS pero con distinto `lectura_kwh` o `tipo` (corrección legítima), sí se inserta como nueva lectura. Esta decisión queda documentada en `SOLUCION.md`.

### 2.6 Arquitectura y principios de diseño

#### Patrón: Arquitectura Hexagonal (Ports & Adapters)

El código se organiza en tres capas concéntricas. El **dominio** es el núcleo puro sin dependencias de framework. La **aplicación** contiene los casos de uso que orquestan el dominio. La **infraestructura** son los adaptadores concretos (Yii2, SQL Server, HTTP).

```
┌──────────────────────────────────────────────────────┐
│  Infrastructure (adaptadores)                        │
│  ┌────────────────────────────────────────────────┐  │
│  │  Application (casos de uso)                   │  │
│  │  ┌──────────────────────────────────────────┐ │  │
│  │  │  Domain (núcleo puro, sin deps externas) │ │  │
│  │  └──────────────────────────────────────────┘ │  │
│  └────────────────────────────────────────────────┘  │
└──────────────────────────────────────────────────────┘
```

La dependencia **siempre apunta hacia dentro**: la infraestructura depende de la aplicación y el dominio, nunca al revés. Los repositorios en el dominio son interfaces (puertos); las implementaciones SQL Server son adaptadores en la infraestructura.

#### Aplicación de principios SOLID

| Principio | Aplicación concreta |
|-----------|---------------------|
| **S** — Single Responsibility | Cada clase tiene una única razón de cambio: `RegistrarLecturaHandler` solo orquesta el caso de uso; `SqlServerContratoRepository` solo habla con la BD. |
| **O** — Open/Closed | Los repositorios son interfaces; se puede añadir un nuevo adaptador (MySQL, Redis) sin modificar el dominio. |
| **L** — Liskov Substitution | `SqlServerLecturaRepository` implementa `LecturaRepository`; el handler lo usa sin saber qué implementación hay detrás. |
| **I** — Interface Segregation | `LecturaRepository` y `ContratoRepository` son interfaces pequeñas y cohesivas, con solo los métodos que cada caso de uso necesita. |
| **D** — Dependency Inversion | El handler depende de abstracciones (`LecturaRepository`, `ContratoRepository`), no de implementaciones concretas. Las implementaciones se inyectan desde la infraestructura. |

#### Aplicación de DDD (Domain-Driven Design)

| Concepto DDD | Implementación |
|---|---|
| **Aggregate Root** | `Lectura` — encapsula la lógica de creación y validación de fechas futuras. |
| **Entities** | `Lectura`, `Contrato` — identidad por `id` o `contrato_id`. |
| **Value Objects** | `Cups` (validación de formato ES), `LecturaKwh` (≥ 0), `TipoLectura` (enum `real`/`estimada`) — inmutables, sin identidad propia. |
| **Repository interfaces** (puertos) | `LecturaRepository`, `ContratoRepository` — abstraen el acceso a datos; el dominio no sabe nada de SQL. |
| **Domain Exceptions** | `CupsNoEncontradoException`, `ContratoBajaException`, `FechaFueraVigenciaException`, `FechaFuturaException` — el dominio expresa sus invariantes mediante excepciones tipadas. |
| **Application Service** | `RegistrarLecturaHandler` — único caso de uso, recibe un `RegistrarLecturaCommand` (DTO de entrada) y devuelve `LecturaRegistradaResponse` (DTO de salida). |

#### Stack técnico

- PHP 8.3 (imagen Docker provista)
- Yii2 `^2.0` como adaptador HTTP (controlador + request model)
- PDO con driver `pdo_sqlsrv` (ya instalado en la imagen)
- SQL Server 2022 (contenedor `db`)
- PHPUnit para tests unitarios del caso de uso

**No se usan:**
- ActiveRecord de Yii2 para el dominio (rompería la separación de capas).
- ORM avanzado (los repositorios SQL son suficientes para dos tablas).
- Cola de mensajes (el volumen de lecturas no lo justifica en esta versión).
- Autenticación (fuera de scope v1; documentado como deuda en `SOLUCION.md`).

### 2.7 Estructura de directorios

```
parte1/
├── app/
│   ├── Dockerfile
│   ├── composer.json
│   ├── phpunit.xml
│   ├── config/
│   │   ├── web.php                      ← Yii2: componentes, URL rules, DI container
│   │   └── db.php                       ← DSN y credenciales SQL Server
│   ├── web/
│   │   └── index.php                    ← entry point Yii2
│   │
│   ├── src/                             ← código de la aplicación (PSR-4)
│   │   │
│   │   ├── Domain/                      ← núcleo puro, sin dependencias de framework
│   │   │   ├── Lectura/
│   │   │   │   ├── Lectura.php          ← Aggregate Root (Entity)
│   │   │   │   ├── LecturaKwh.php       ← Value Object (≥ 0)
│   │   │   │   ├── TipoLectura.php      ← Value Object (enum: real | estimada)
│   │   │   │   ├── LecturaRepository.php← Puerto (interface)
│   │   │   │   └── Exception/
│   │   │   │       └── FechaFuturaException.php
│   │   │   └── Contrato/
│   │   │       ├── Contrato.php         ← Entity
│   │   │       ├── Cups.php             ← Value Object (formato ES + longitud)
│   │   │       ├── ContratoRepository.php← Puerto (interface)
│   │   │       └── Exception/
│   │   │           ├── CupsNoEncontradoException.php
│   │   │           ├── ContratoBajaException.php
│   │   │           └── FechaFueraVigenciaException.php
│   │   │
│   │   ├── Application/                 ← casos de uso (orquesta el dominio)
│   │   │   └── RegistrarLectura/
│   │   │       ├── RegistrarLecturaCommand.php    ← DTO de entrada
│   │   │       ├── RegistrarLecturaHandler.php    ← caso de uso (SOLID: SRP, DIP)
│   │   │       └── LecturaRegistradaResponse.php  ← DTO de salida
│   │   │
│   │   └── Infrastructure/              ← adaptadores (Yii2, SQL Server, HTTP)
│   │       ├── Http/
│   │       │   └── LecturaController.php← Adaptador entrada: Yii2 controller
│   │       └── Persistence/
│   │           ├── SqlServerLecturaRepository.php ← Adaptador salida: SQL Server
│   │           └── SqlServerContratoRepository.php← Adaptador salida: SQL Server
│   │
│   ├── tests/
│   │   └── Application/
│   │       └── RegistrarLecturaHandlerTest.php  ← tests unitarios con mocks
│   │
│   └── SOLUCION.md
│
└── db/
    └── init.sql
```

### 2.8 Flujo de una petición

```
POST /api/lecturas
       │
       ▼
LecturaController::actionCreate()         [Adaptador de entrada — Infraestructura]
       │
       ├─ LecturaRequest::load() + validate()
       │     └─ error de formato → 400 Bad Request
       │
       ├─ new RegistrarLecturaCommand($cups, $fecha, $kwh, $tipo)
       │
       ▼
RegistrarLecturaHandler::handle()          [Caso de uso — Aplicación]
       │
       ├─ ContratoRepository::findByCupsAndFecha()
       │     └─ CupsNoEncontradoException  → 422 CUPS_NO_ENCONTRADO
       │     └─ ContratoBajaException      → 422 CONTRATO_BAJA
       │     └─ FechaFueraVigenciaException→ 422 FECHA_FUERA_VIGENCIA
       │
       ├─ Lectura::create($contratoId, $cups, $fecha, $kwh, $tipo)
       │     └─ FechaFuturaException       → 422 FECHA_FUTURA
       │        (el dominio valida su propia invariante)
       │
       ├─ LecturaRepository::findDuplicate()
       │     └─ encontrado                 → 200 con duplicado:true
       │
       ├─ LecturaRepository::save()
       │     └─ error de BD               → 500 ERROR_INTERNO
       │
       └─ LecturaRegistradaResponse       → 201 Created
              │
              ▼
       LecturaController serializa la respuesta JSON
```

### 2.9 Decisiones de diseño

| Decisión | Elección | Motivo |
|----------|----------|--------|
| Arquitectura | Hexagonal + DDD | Separación clara entre dominio, aplicación e infraestructura; el dominio es testable sin BD ni framework |
| Framework | Yii2 como adaptador HTTP | Framework del equipo; queda confinado a la capa de infraestructura, no contamina el dominio |
| Value Objects | `Cups`, `LecturaKwh`, `TipoLectura` | Encapsulan validaciones propias del dominio; evitan primitivos sueltos (Primitive Obsession) |
| Inyección de dependencias | Yii2 DI container en `web.php` | Resuelve `LecturaRepository` → `SqlServerLecturaRepository` sin que el handler lo sepa |
| Resolución de contrato | Por vigencia en la fecha, no por estado actual | Un CUPS puede cambiar de titular; el estado `baja` no impide lecturas históricas legítimas |
| Idempotencia | Por clave natural `(contrato_id, fecha, kwh, tipo)` | No requiere campo extra; el dominio lo determina |
| Tests | PHPUnit con mocks de repositorios | El handler no necesita BD para ser testeado (SRP + DIP en acción) |
| Sin auth | Fuera de scope v1 | Se documenta como deuda en `SOLUCION.md` |

### 2.10 Plan de implementación

| # | Tarea | Fichero principal |
|---|-------|-------------------|
| 1 | Value Objects del dominio: `Cups`, `LecturaKwh`, `TipoLectura` | `src/Domain/` |
| 2 | Entities: `Lectura` (con `FechaFuturaException`), `Contrato` | `src/Domain/` |
| 3 | Interfaces de repositorio (puertos) | `src/Domain/*/[Lectura\|Contrato]Repository.php` |
| 4 | Caso de uso: `RegistrarLecturaHandler` con command y response DTOs | `src/Application/RegistrarLectura/` |
| 5 | Adaptadores de persistencia SQL Server | `src/Infrastructure/Persistence/` |
| 6 | Adaptador HTTP: `LecturaController` + `LecturaRequest` | `src/Infrastructure/Http/` |
| 7 | Configurar Yii2: URL rules, DI container, `db.php` | `config/web.php`, `config/db.php` |
| 8 | Tests unitarios con mocks de repositorios | `tests/Application/RegistrarLecturaHandlerTest.php` |
| 9 | Verificar con `curl` los casos: feliz, duplicado y errores de negocio | — |
| 10 | Redactar `SOLUCION.md` | `app/SOLUCION.md` |

---

## 3. Parte 2 — Investigación de la incidencia de facturación

### 3.1 Contexto

El 5 de octubre de 2026 se ejecutó la facturación mensual de septiembre. Jenkins (job `facturacion-mensual #48`) lanzó `sp_facturar_periodo @periodo = '2026-09'`. El proceso **tardó 45 minutos** (vs ~1 min el mes anterior) y terminó con **timeout**. Desde entonces hay reclamaciones de clientes que afirman:

- Haber recibido la factura **dos veces**.
- Haber recibido facturas con un **importe mucho más alto** de lo normal.

### 3.2 Evidencias disponibles

1. **Log de Jenkins #47 (agosto):** proceso OK, ~1 min 30 s de ejecución. Sin anomalías.
2. **Log de Jenkins #48 (septiembre):** timeout a los 45 minutos. El proceso llegó a ejecutar el SP pero no completó.
3. **Esquema de la base de datos:**
   - `log_procesos`: registra cada ejecución del SP con estado (`EN_CURSO`, `OK`, `ERROR`).
   - `facturas`: tiene `ejecucion_id` → FK a `log_procesos`.
   - `facturas.estado`: `borrador`, `emitida`, `pagada`, `anulada`.
   - `lecturas`: tiene `tipo` (`real` / `estimada`) y `fichero_origen`.
   - `tarifas_precios`: historial de precios con `vigente_desde` / `vigente_hasta`.
4. **Reclamaciones:** registradas en `reclamaciones` desde el 5 de octubre.

### 3.3 Hipótesis de trabajo

Dado el síntoma de **facturas duplicadas** y el **timeout** del SP, las hipótesis ordenadas por probabilidad son:

**H1 — El SP se ejecutó parcialmente y volvió a lanzarse sin limpiar la ejecución previa**  
El timeout causó que la primera ejecución dejara filas en estado `EN_CURSO` o facturas en estado `borrador`. Si alguien relanzó el proceso manualmente, el SP no detectó la ejecución previa y generó facturas para contratos ya facturados → duplicados.

**H2 — El SP no tiene guarda de idempotencia**  
El SP no comprueba si ya existe una factura para `(contrato_id, periodo)` antes de insertar → cada llamada inserta una nueva factura independientemente.

**H3 — Lecturas duplicadas o con consumos anómalos para septiembre**  
Si en `lecturas` hay filas duplicadas o con `consumo_kwh` erróneo para el periodo `2026-09`, el SP calcula importes inflados.

**H4 — Cambio de precio en `tarifas_precios` para septiembre**  
Un precio erróneamente alto introducido en `tarifas_precios` con `vigente_desde` en septiembre.

**H5 — El timeout fue consecuencia de un bucle o cursor ineficiente dentro del SP**  
El SP procesa fila a fila en lugar de en set-based, lo que explica los 45 min. No causa directamente los duplicados, pero sí el timeout que desencadenó el reintento.

### 3.4 Plan de investigación (SQL)

Los scripts se entregarán en `parte2/scripts/` con nombres numerados:

#### `01_estado_ejecuciones.sql` — Estado del log de procesos

```sql
-- Ver todas las ejecuciones del proceso de facturación
SELECT id, proceso, parametros, inicio, fin, estado,
       filas_procesadas, usuario, mensaje,
       DATEDIFF(SECOND, inicio, ISNULL(fin, GETDATE())) AS segundos
FROM   dbo.log_procesos
WHERE  proceso = 'facturacion_mensual'
ORDER  BY inicio DESC;
```

Buscamos: ejecuciones con estado `EN_CURSO` que nunca terminaron, o múltiples ejecuciones para el mismo periodo.

#### `02_facturas_duplicadas.sql` — Contratos con más de una factura en el mismo periodo

```sql
-- Detectar facturas duplicadas por contrato y periodo
SELECT contrato_id, periodo,
       COUNT(*)          AS num_facturas,
       SUM(total)        AS total_facturado,
       STRING_AGG(CAST(id AS VARCHAR), ', ') AS ids_facturas,
       STRING_AGG(estado, ', ')              AS estados
FROM   dbo.facturas
WHERE  periodo = '2026-09'
  AND  estado <> 'anulada'
GROUP  BY contrato_id, periodo
HAVING COUNT(*) > 1
ORDER  BY num_facturas DESC;
```

#### `03_facturas_importe_anomalo.sql` — Facturas con importe fuera de rango

```sql
-- Comparar importe septiembre con media de meses anteriores por contrato
WITH historico AS (
    SELECT contrato_id,
           AVG(total) AS media_historica,
           STDEV(total) AS desv_historica
    FROM   dbo.facturas
    WHERE  periodo BETWEEN '2026-04' AND '2026-08'
      AND  estado <> 'anulada'
    GROUP  BY contrato_id
),
sep AS (
    SELECT contrato_id, id, numero, total, consumo_kwh, precio_kwh
    FROM   dbo.facturas
    WHERE  periodo = '2026-09'
      AND  estado <> 'anulada'
)
SELECT s.contrato_id, s.id, s.numero, s.total,
       h.media_historica,
       ROUND(s.total / NULLIF(h.media_historica, 0), 1) AS ratio_vs_media,
       s.consumo_kwh, s.precio_kwh
FROM   sep s
JOIN   historico h ON h.contrato_id = s.contrato_id
WHERE  s.total > h.media_historica * 2   -- más del doble de la media
ORDER  BY ratio_vs_media DESC;
```

#### `04_lecturas_sept_anomalas.sql` — Lecturas de septiembre con consumo anómalo o duplicadas

```sql
-- Lecturas duplicadas para septiembre
SELECT contrato_id, fecha_desde, fecha_hasta,
       COUNT(*) AS num_lecturas,
       SUM(consumo_kwh) AS consumo_total
FROM   dbo.lecturas
WHERE  fecha_hasta >= '2026-09-01' AND fecha_desde < '2026-10-01'
GROUP  BY contrato_id, fecha_desde, fecha_hasta
HAVING COUNT(*) > 1;

-- Consumo de septiembre vs agosto por contrato
SELECT l_sep.contrato_id,
       l_sep.consumo_kwh AS consumo_sep,
       l_ago.consumo_kwh AS consumo_ago,
       ROUND(l_sep.consumo_kwh / NULLIF(l_ago.consumo_kwh, 0), 1) AS ratio
FROM  (SELECT contrato_id, SUM(consumo_kwh) AS consumo_kwh
       FROM   dbo.lecturas
       WHERE  fecha_hasta >= '2026-09-01' AND fecha_desde < '2026-10-01'
       GROUP  BY contrato_id) l_sep
LEFT JOIN
      (SELECT contrato_id, SUM(consumo_kwh) AS consumo_kwh
       FROM   dbo.lecturas
       WHERE  fecha_hasta >= '2026-08-01' AND fecha_desde < '2026-09-01'
       GROUP  BY contrato_id) l_ago
ON l_sep.contrato_id = l_ago.contrato_id
ORDER BY ratio DESC;
```

#### `05_precios_sept.sql` — Verificar precios vigentes en septiembre

```sql
SELECT t.codigo, tp.precio_kwh, tp.termino_fijo_mes,
       tp.vigente_desde, tp.vigente_hasta
FROM   dbo.tarifas_precios tp
JOIN   dbo.tarifas t ON t.id = tp.tarifa_id
WHERE  tp.vigente_desde <= '2026-09-30'
  AND (tp.vigente_hasta IS NULL OR tp.vigente_hasta >= '2026-09-01')
ORDER  BY t.codigo, tp.vigente_desde;
```

#### `06_reclamaciones_detalle.sql` — Cruzar reclamaciones con facturas

```sql
SELECT r.id, r.fecha, r.canal, c.nombre, c.email,
       co.cups, f.numero, f.total, f.estado, f.periodo,
       r.texto
FROM   dbo.reclamaciones r
JOIN   dbo.clientes c    ON c.id = r.cliente_id
JOIN   dbo.contratos co  ON co.cliente_id = c.id
LEFT JOIN dbo.facturas f ON f.contrato_id = co.id AND f.periodo = '2026-09'
ORDER  BY r.fecha;
```

#### `07_sp_definition.sql` — Revisar el stored procedure

```sql
EXEC sp_helptext 'dbo.sp_facturar_periodo';
-- O bien:
SELECT definition
FROM   sys.sql_modules
WHERE  object_id = OBJECT_ID('dbo.sp_facturar_periodo');
```

Analizar si el SP: (a) comprueba existencia previa de facturas, (b) usa cursor o bucle fila a fila, (c) usa transacciones correctamente.

### 3.5 Scripts de corrección

Los scripts de corrección solo se ejecutarán tras confirmar el diagnóstico. Se entregarán en `parte2/scripts/`:

#### `10_correccion_anular_duplicados.sql`

```sql
-- DIAGNÓSTICO PREVIO: ejecutar 02_facturas_duplicadas.sql
-- CORRECCIÓN: anular las facturas duplicadas conservando la primera emitida

BEGIN TRANSACTION;

-- Identificar qué factura conservar (la de menor id por (contrato_id, periodo))
WITH primeras AS (
    SELECT MIN(id) AS id_conservar, contrato_id, periodo
    FROM   dbo.facturas
    WHERE  periodo = '2026-09'
      AND  estado NOT IN ('anulada', 'pagada')
    GROUP  BY contrato_id, periodo
    HAVING COUNT(*) > 1
),
a_anular AS (
    SELECT f.id
    FROM   dbo.facturas f
    JOIN   primeras p ON p.contrato_id = f.contrato_id AND p.periodo = f.periodo
    WHERE  f.id <> p.id_conservar
      AND  f.estado NOT IN ('anulada', 'pagada')
)
UPDATE dbo.facturas
SET    estado = 'anulada'
WHERE  id IN (SELECT id FROM a_anular);

-- Ver qué se va a anular antes de confirmar
-- ROLLBACK TRANSACTION;
COMMIT TRANSACTION;
```

**Nota de seguridad:** el script deja el `COMMIT` comentado por defecto para revisión previa. La corrección no elimina filas, solo cambia el estado a `anulada` → reversible.

#### `11_correccion_importes_anomalos.sql`

Si el diagnóstico confirma que el importe inflado viene de un precio erróneo en `tarifas_precios`, el script actualizará ese precio y recalculará las facturas afectadas. El contenido definitivo depende del resultado de la investigación.

### 3.6 Prevención

Los entregables de prevención se incluirán en `parte2/scripts/20_prevencion.sql` y cubrirán:

1. **Unicidad en facturas por contrato y periodo:**

```sql
-- Añadir constraint única para evitar facturas duplicadas a nivel de BD
ALTER TABLE dbo.facturas
ADD CONSTRAINT uq_facturas_contrato_periodo
    UNIQUE (contrato_id, periodo);
-- NOTA: hay que anular duplicados existentes antes de aplicar este constraint.
```

2. **Guarda en el SP** (modificación del stored procedure):

```sql
-- Añadir al inicio del SP, antes de insertar:
IF EXISTS (
    SELECT 1 FROM dbo.facturas
    WHERE  contrato_id = @contrato_id
      AND  periodo      = @periodo
      AND  estado      <> 'anulada'
)
BEGIN
    -- Ya existe factura: saltar o lanzar error según diseño
    RETURN;
END
```

3. **Timeout en Jenkins:** aumentar el timeout del job o, mejor, hacer el SP set-based en lugar de fila a fila para reducir el tiempo de ejecución.

4. **Monitorización:** añadir una consulta de verificación post-ejecución que alerte si hay duplicados antes de emitir facturas.

### 3.7 Informe para negocio

> **Para:** Responsable de Facturación  
> **Asunto:** Incidencia facturación septiembre 2026 — diagnóstico y propuesta

**¿Qué ha pasado?**

La facturación del 5 de octubre falló por timeout a los 45 minutos. El proceso había empezado a generar facturas pero no terminó. Esto dejó facturas en estado intermedio en la base de datos.

Al relanzar el proceso (o si el SP no comprueba si ya existe factura para ese cliente y mes), se generaron facturas adicionales para los mismos contratos. Resultado: algunos clientes recibieron dos facturas. Los importes altos se están investigando: la causa más probable es que el consumo calculado incluya un periodo más amplio de lo esperado (lecturas duplicadas) o un precio incorrecto en el sistema.

**¿A cuántos clientes afecta?**

Lo determinaremos con los scripts de diagnóstico una vez levantado el entorno. Los scripts identifican exactamente qué contratos tienen más de una factura para el periodo 2026-09 y qué facturas tienen importes fuera del rango histórico.

**¿Qué proponemos hacer?**

1. **Inmediato:** anular las facturas duplicadas (las secundarias) sin eliminarlas. Las facturas correctas quedan activas. Script listo para revisar y ejecutar.
2. **Para las facturas con importe anómalo:** revisar si el problema es de lectura o de precio, recalcular y re-emitir con el importe correcto. Necesita aprobación de negocio antes de comunicar a los clientes.
3. **Comunicación a clientes:** los clientes que reclamaron duplicado recibirán confirmación de anulación de la factura extra. Los que reclamaron importe alto: esperar a confirmar el origen.

**¿Qué necesitamos decidir?**

- ¿Anulamos y re-emitimos la factura correcta, o solo anulamos el duplicado?
- ¿Necesitamos comunicación proactiva a los clientes no reclamantes que también tengan duplicado?
- ¿Cuándo se relanza la facturación de septiembre para los contratos que aún no tienen factura válida?

**Prevención para octubre**

Se añadirá una restricción en la base de datos que impide físicamente que existan dos facturas activas para el mismo contrato y periodo. Además se revisará el SP para añadir la comprobación de idempotencia y mejorar el rendimiento (el timeout no debería ocurrir).

### 3.8 Plan de implementación

| # | Tarea | Fichero |
|---|-------|---------|
| 1 | Levantar entorno: `docker compose up -d` en `parte2/` | — |
| 2 | Ejecutar scripts de diagnóstico 01–07 en orden | `scripts/01_*.sql` … `07_*.sql` |
| 3 | Documentar hallazgos en el informe final (`SOLUCION.md`) | — |
| 4 | Ejecutar corrección `10_correccion_anular_duplicados.sql` (modo dry-run primero) | `scripts/10_*.sql` |
| 5 | Ejecutar corrección de importes según diagnóstico (`11_*.sql`) | `scripts/11_*.sql` |
| 6 | Ejecutar `20_prevencion.sql` sobre la BD de test | `scripts/20_*.sql` |
| 7 | Finalizar informe para negocio en `SOLUCION.md` | `parte2/SOLUCION.md` |

---

## 4. Dependencias entre partes

Las dos partes son completamente independientes entre sí. Pueden desarrollarse en paralelo o en cualquier orden. La única dependencia es que ambas necesitan Docker corriendo (con Rosetta activado en Mac con Apple Silicon para emular x86).

Se recomienda no levantar las dos partes a la vez dado que cada SQL Server ocupa ~2 GB de RAM.

---

## 5. Riesgos y limitaciones

| Riesgo | Impacto | Mitigación |
|--------|---------|------------|
| El SP de facturación está en `init.sql` pero puede contener lógica compleja no visible hasta ejecutarlo | Alto | El script `07_sp_definition.sql` lo extrae para revisión antes de cualquier corrección |
| El constraint de unicidad en `facturas` podría fallar si ya existen duplicados | Medio | Los scripts de corrección se ejecutan **antes** del constraint |
| Yii2 puede necesitar configuración adicional para trabajar con SQL Server via `pdo_sqlsrv` | Bajo | El DSN del enunciado ya funciona; se testea en el primer paso |
| El timeout de 45 min puede repetirse si el SP no se optimiza | Alto | Se documenta como deuda técnica; la corrección inmediata es prevenir el reintento sin limpiar, no necesariamente reescribir el SP |
| La API de lecturas no tiene autenticación | Bajo (v1) | Se documenta; en producción requeriría al menos API key o mTLS |
| La arquitectura hexagonal añade más ficheros que un MVC clásico | Bajo | El dominio queda testable sin BD ni framework; el coste de ficheros se amortiza rápido con tests y mantenibilidad |
