# SDD: Prueba técnica PHP

**Documento:** diseño y planificación
**Fecha:** 2026-10-23 (actualizado con el estado final de la implementación)
**Autor:** Isaac Moreno Moreno
**Proyecto:** Prueba técnica, comercializadora de electricidad

---

## Índice

1. [Visión general](#1-visión-general)
2. [Parte 1: API de recepción de lecturas](#2-parte-1-api-de-recepción-de-lecturas)
   2.1 [Contexto y objetivo](#21-contexto-y-objetivo)
   2.2 [Modelo de datos existente](#22-modelo-de-datos-existente)
   2.3 [Contrato de la API](#23-contrato-de-la-api)
   2.4 [Validaciones y reglas de negocio](#24-validaciones-y-reglas-de-negocio)
   2.5 [Idempotencia y reintentos](#25-idempotencia-y-reintentos)
   2.6 [Arquitectura](#26-arquitectura)
   2.7 [Estructura de directorios](#27-estructura-de-directorios)
   2.8 [Flujo de una petición](#28-flujo-de-una-petición)
   2.9 [Decisiones de diseño](#29-decisiones-de-diseño)
3. [Parte 2: Incidencia de facturación](#3-parte-2-incidencia-de-facturación)
   3.1 [Contexto](#31-contexto)
   3.2 [Hipótesis y resultado](#32-hipótesis-y-resultado)
   3.3 [Scripts](#33-scripts)
   3.4 [Corrección](#34-corrección)
   3.5 [Prevención](#35-prevención)
4. [Riesgos y limitaciones](#4-riesgos-y-limitaciones)

---

## 1. Visión general

| Parte | Tipo | Objetivo | Documentación |
|-------|------|----------|---------------|
| 1 | Desarrollo | API REST en PHP para recibir lecturas de contadores de una distribuidora | `parte1/app/SOLUCION.md` |
| 2 | Análisis / SQL | Diagnosticar y corregir la incidencia de facturación de septiembre 2026 | `parte2/SOLUCION.md` |

Las dos partes comparten dominio (contratos, CUPS, lecturas, facturación), pero usan bases de datos distintas y son independientes. El estado de cada tarea está en `TASKS.md`.

---

## 2. Parte 1: API de recepción de lecturas

### 2.1 Contexto y objetivo

Una distribuidora deja de enviar las lecturas en ficheros y pasa a enviarlas por API REST. Primera versión que:

- Recibe lecturas individuales (CUPS, fecha, kWh y tipo).
- Valida que existe un contrato que acepta esa lectura.
- Es idempotente frente a reintentos de la distribuidora.
- Responde con claridad cuando la lectura no se guarda y por qué.

### 2.2 Modelo de datos existente

**Tabla `contratos`**

| Columna | Tipo | Notas |
|---------|------|-------|
| `id` | INT PK | |
| `cups` | VARCHAR(22) | Punto de suministro. Puede tener varios contratos (cambio de titular) |
| `titular`, `nif`, `tarifa` | | No se usan en la API |
| `estado` | VARCHAR(10) | `activo` / `baja` |
| `fecha_alta` | DATE | Inicio de vigencia |
| `fecha_baja` | DATE NULL | Fin de vigencia |
| `digitos_contador` | TINYINT | 5 o 6. Todavía no se usa (ver backlog en `TASKS.md`) |

**Tabla `lecturas`**

| Columna | Tipo | Notas |
|---------|------|-------|
| `id` | INT PK | |
| `contrato_id` | INT FK | |
| `fecha_lectura` | DATE | |
| `lectura_kwh` | INT | Valor acumulado del contador |
| `tipo` | VARCHAR(10) | `real` / `estimada` |
| `fecha_recepcion` | DATETIME2(0) | Momento de la inserción |

Índices: `ix_contratos_cups (cups)`, `ix_lecturas_contrato_fecha (contrato_id, fecha_lectura)`. No hay índice único sobre las lecturas.

**Observaciones sobre los datos de muestra:**

- El CUPS `ES0904688281126584QG` tiene dos contratos: el 12 (de baja el 2026-07-15) y el 24 (activo desde el 2026-07-16). Hay que resolver el contrato vigente en la fecha de la lectura, no el activo hoy.
- El contrato 3 tiene la misma lectura real del 2026-06-01 dos veces (ids 121 y 137). Por eso no se puede crear directamente un índice único sobre la clave de idempotencia.
- Los contratos 5 y 12 tienen una lectura en su fecha de baja recibida al día siguiente: la lectura de cierre llega después de la baja.

### 2.3 Contrato de la API

```
POST /api/lecturas
Content-Type: application/json
```

```json
{
  "cups":          "ES0904998698797309NB",
  "fecha_lectura": "2026-10-01",
  "lectura_kwh":   10849,
  "tipo":          "real"
}
```

| Campo | Tipo | Validación |
|-------|------|------------|
| `cups` | string | `ES` + 18 a 20 caracteres alfanuméricos |
| `fecha_lectura` | string `YYYY-MM-DD` | No puede ser futura |
| `lectura_kwh` | integer | ≥ 0 |
| `tipo` | string | `real` o `estimada` |

**Respuestas**

| HTTP | `error` | Cuándo | ¿Se guarda? |
|------|---------|--------|-------------|
| 201 | | Lectura nueva | Sí |
| 200 | | Reintento: misma clave y mismo kWh. La respuesta incluye `"duplicado": true` | Ya estaba |
| 400 | `VALIDACION` | JSON inválido o campos con formato incorrecto (`campos` detalla cada uno) | No |
| 409 | `LECTURA_CONFLICTIVA` | Misma clave con otro kWh. Devuelve `lectura_existente_id` y `lectura_kwh_existente` | No |
| 422 | `CUPS_NO_ENCONTRADO` | No hay ningún contrato con ese CUPS | No |
| 422 | `CONTRATO_BAJA` | El contrato que cubre la fecha está dado de baja | No |
| 422 | `FECHA_FUERA_VIGENCIA` | Ningún contrato del CUPS cubre la fecha | No |
| 422 | `FECHA_FUTURA` | Fecha posterior a hoy | No |
| 500 | `ERROR_INTERNO` | Error no previsto (se registra en el log) | No |

Cuerpo de éxito (201/200): `id`, `cups`, `contrato_id`, `fecha_lectura`, `lectura_kwh`, `tipo`, `fecha_recepcion` y, si es un reintento, `duplicado`.
Cuerpo de error: `{"error": "...", "mensaje": "..."}`. El mensaje siempre indica que la lectura no se ha guardado.

### 2.4 Validaciones y reglas de negocio

Orden real de ejecución:

1. **Formato** (`LecturaController::validateRequest`): presencia, tipo y formato de los cuatro campos → 400.
2. **Tipos** (`Cups`, `LecturaKwh`, `TipoLectura`): mismas reglas al construirse (la validación está repetida; ver 2.6).
3. **Fecha futura** (handler) → `FechaFuturaException`.
4. **Contratos del CUPS** (`ContratoRepository::findByCups`): ninguno → `CupsNoEncontradoException`.
5. **Contrato vigente en la fecha** (`Contrato::estaVigenteEn`): `fecha_alta ≤ fecha ≤ fecha_baja` (o sin baja). Ninguno → `FechaFueraVigenciaException`.
6. **Estado del contrato vigente**: si está de baja → `ContratoBajaException`. Es la aplicación literal del requisito ("los contratos dados de baja no aceptan lecturas").
7. **Idempotencia** dentro de una transacción (ver 2.5).

No se valida que el acumulado no retroceda: el contador da la vuelta (`digitos_contador`) y las estimadas se regularizan después. El consumo se calcula aguas abajo.

### 2.5 Idempotencia y reintentos

**Clave:** `(contrato_id, fecha_lectura, tipo)`.

| Llega… | Resultado |
|--------|-----------|
| Misma clave, mismo `lectura_kwh` | 200 con la lectura existente y `duplicado: true` |
| Misma clave, distinto `lectura_kwh` | 409 `LECTURA_CONFLICTIVA` |
| Misma fecha, distinto `tipo` (estimada y después real) | 201: es una regularización |

No se usa cabecera `Idempotency-Key`: la distribuidora no tiene que gestionar identificadores y el duplicado se detecta con los datos de negocio.

**Concurrencia:** la búsqueda y la inserción se ejecutan en `LecturaRepository::transaccional()`. La `SELECT` usa `WITH (UPDLOCK, HOLDLOCK)`, que bloquea la clave hasta el commit, así que dos reintentos simultáneos no pueden insertar los dos.

### 2.6 Arquitectura

Lógica de negocio separada del framework para poder testearla sin base de datos:

| Pieza | Responsabilidad |
|-------|-----------------|
| `LecturaController` (Yii2) | HTTP: validar el formato, llamar al caso de uso y traducir errores a códigos HTTP |
| `RegistrarLecturaHandler` | Reglas de negocio. Depende solo de las interfaces `ContratoRepository` y `LecturaRepository` |
| Repositorios SQL Server | Consultas con PDO |
| `Cups`, `LecturaKwh`, `TipoLectura` | Tipos que validan su formato al construirse |

**Coste asumido:** para un único endpoint es más estructura de la necesaria. La alternativa proporcionada sería controlador, servicio y repositorio, con la validación en `Model::rules()` de Yii (ahora está repetida entre el controlador y los tipos). Queda anotado como simplificación futura; no se cambia en la entrega para no tocar código ya probado.

**Wiring:** `config/web.php` crea el PDO, los dos repositorios y el handler, y registra el handler como componente `lecturaHandler`. No se usa el contenedor de Yii2: con un único caso de uso basta el wiring explícito.

**Stack:** PHP 8.3, Yii2, PDO `pdo_sqlsrv`, SQL Server 2022, PHPUnit.

**Fuera de alcance (v1):** ActiveRecord, ORM, colas, autenticación.

### 2.7 Estructura de directorios

```
parte1/
├── compose.yaml                         (instala dependencias al arrancar el contenedor)
├── db/init.sql
└── app/
    ├── Dockerfile
    ├── composer.json / composer.lock
    ├── phpunit.xml
    ├── config/
    │   ├── web.php                      (rutas, formato JSON, wiring)
    │   └── db.php                       (DSN por variables de entorno)
    ├── web/index.php
    ├── src/
    │   ├── Domain/
    │   │   ├── Contrato/
    │   │   │   ├── Contrato.php
    │   │   │   ├── Cups.php
    │   │   │   ├── ContratoRepository.php
    │   │   │   └── Exception/
    │   │   │       ├── CupsNoEncontradoException.php
    │   │   │       ├── ContratoBajaException.php
    │   │   │       └── FechaFueraVigenciaException.php
    │   │   └── Lectura/
    │   │       ├── Lectura.php
    │   │       ├── LecturaKwh.php
    │   │       ├── TipoLectura.php
    │   │       ├── LecturaRepository.php
    │   │       └── Exception/
    │   │           ├── FechaFuturaException.php
    │   │           └── LecturaConflictivaException.php
    │   ├── Application/RegistrarLectura/
    │   │   ├── RegistrarLecturaCommand.php
    │   │   ├── RegistrarLecturaHandler.php
    │   │   └── LecturaRegistradaResponse.php
    │   └── Infrastructure/
    │       ├── Http/LecturaController.php
    │       └── Persistence/
    │           ├── SqlServerContratoRepository.php
    │           └── SqlServerLecturaRepository.php
    ├── tests/Application/RegistrarLecturaHandlerTest.php
    └── SOLUCION.md
```

### 2.8 Flujo de una petición

```
POST /api/lecturas
  │
  ▼
LecturaController::actionCreate()
  ├─ JSON inválido o campos incorrectos ............ 400 VALIDACION
  │
  ▼
RegistrarLecturaHandler::handle(RegistrarLecturaCommand)
  ├─ fecha > hoy ................................... 422 FECHA_FUTURA
  ├─ ContratoRepository::findByCups()
  │    ├─ sin contratos ............................ 422 CUPS_NO_ENCONTRADO
  │    ├─ ninguno vigente en la fecha .............. 422 FECHA_FUERA_VIGENCIA
  │    └─ vigente pero de baja ..................... 422 CONTRATO_BAJA
  │
  └─ LecturaRepository::transaccional()
       ├─ findByClave()  (UPDLOCK, HOLDLOCK)
       │    ├─ mismo kWh ........................... 200 duplicado: true
       │    └─ otro kWh ............................ 409 LECTURA_CONFLICTIVA
       └─ save() ................................... 201 Created
```

### 2.9 Decisiones de diseño

| Decisión | Elección | Motivo |
|----------|----------|--------|
| Framework | Yii2 en la capa HTTP, reglas en un handler propio | Framework preferente del equipo; las reglas se testean sin base de datos |
| Resolución de contrato | Por vigencia en la fecha | Un CUPS puede cambiar de titular |
| Contrato de baja | Se rechaza (`CONTRATO_BAJA`) | Requisito literal. La lectura de cierre queda como decisión de negocio |
| Idempotencia | Clave `(contrato, fecha, tipo)` + comparación de kWh | Distingue reintento, conflicto y regularización |
| Concurrencia | Transacción + `UPDLOCK, HOLDLOCK` | Sin índice único, porque los datos ya traen un duplicado |
| Wiring | Explícito en `config/web.php` | Un único caso de uso; sin contenedor |
| Tests | PHPUnit con mocks de los repositorios | El caso de uso se prueba sin base de datos |
| Autenticación | Fuera de alcance v1 | Documentado como siguiente paso |

---

## 3. Parte 2: Incidencia de facturación

### 3.1 Contexto

El 5/10/2026 Jenkins (job `facturacion-mensual #48`) lanzó `sp_facturar_periodo '2026-09'`. Cortó por timeout a los 45 minutos, cuando el mes anterior tardó 90 segundos. Desde entonces hay reclamaciones por facturas duplicadas y por importes muy altos.

### 3.2 Hipótesis y resultado

| Hipótesis | Resultado | Evidencia |
|-----------|-----------|-----------|
| H1: ejecución parcial y relanzamiento sin limpiar | **Confirmada** | Ejecución 30 en `EN_CURSO` con 61 facturas; ejecución 31 (manual, `jlopez`) con 142 |
| H2: el SP no tiene guarda de idempotencia | **Confirmada** | No comprueba si existe factura y no usa transacción |
| H3: lecturas duplicadas en septiembre | **Confirmada** | `SUR_20261003_01.csv` repite las 44 filas de `SUR_20261002_01.csv` |
| H4: precio erróneo en septiembre | **Confirmada en otra forma** | El precio está bien, pero el SP no prorratea el cambio de TEMPO_FIJA del 15/09 (44 contratos) |
| H5: el cursor explica el timeout | **Descartada** | El mismo SP tarda unos 90 s todos los meses y en el relanzamiento. La causa del timeout no se puede determinar con los datos |
| Hallazgo adicional | No es error | Contrato 97 (reclamación 7): regularización tras tres meses de lecturas estimadas |

Alcance: 97 contratos (94 clientes). 46 facturas cobradas dos veces (2.956,19 €). 74 facturas con importe incorrecto (1.971,13 € cobrados de más).

### 3.3 Scripts

| Script | Tipo | Contenido |
|--------|------|-----------|
| `01`–`07` | Diagnóstico | Ejecuciones, duplicados, importes, lecturas, precios, reclamaciones y definición del SP |
| `08_lecturas_recarga_fichero.sql` | Diagnóstico | Evidencia de la doble carga del fichero de SUR |
| `10_correccion_anular_duplicados.sql` | Corrección | Anula una factura por contrato duplicado, conservando la pagada |
| `11_correccion_lecturas_duplicadas.sql` | Corrección | Mueve las copias exactas a `lecturas_descartadas` |
| `12_sp_idempotencia.sql` | Prevención | Nueva versión del SP |
| `13_recalculo_propuesto.sql` | Propuesta (solo lectura) | Importe correcto por contrato y causa de cada reclamación |
| `20_prevencion.sql` | Prevención | Índices únicos y comprobación post-facturación |

Se ejecutan en orden con `sqlcmd -I`. Los scripts de corrección y prevención son idempotentes.

### 3.4 Corrección

- Las facturas no se borran: se anulan (`estado = 'anulada'`) conservando `fecha_pago`, que identifica los cobros a devolver.
- Las lecturas repetidas se mueven a una tabla de descarte, con instrucciones para revertirlo.
- Los importes de las facturas emitidas no se modifican: requieren rectificativas o abonos, que son decisión de negocio. El script `13` deja calculada la cifra de cada contrato.

### 3.5 Prevención

| Medida | Dónde |
|--------|-------|
| SP en una transacción con `XACT_ABORT`: un corte no deja facturas a medias | `12` |
| Guarda de idempotencia en el SP: relanzar es seguro | `12` |
| Precio y término fijo prorrateados por días | `12` |
| Errores registrados en `log_procesos` | `12` |
| Índice único filtrado `facturas(contrato_id, periodo) WHERE estado <> 'anulada'` | `20` |
| Índice único `lecturas(contrato_id, fecha_desde, fecha_hasta, tipo)` | `20` |
| Comprobación post-facturación para Jenkins | `20` |

---

## 4. Riesgos y limitaciones

| Riesgo | Impacto | Mitigación |
|--------|---------|------------|
| La API no tiene autenticación | Medio | Siguiente paso: API key o mTLS para la distribuidora |
| Sin índice único en `lecturas` (parte 1) | Bajo | Transacción con bloqueo. Índice tras depurar el duplicado histórico del contrato 3 |
| La lectura de cierre se rechaza | Medio | Pendiente de negocio; el cambio es de una línea |
| Los repositorios SQL no tienen tests de integración | Medio | Verificación manual con curl. Tests contra SQL Server como siguiente paso |
| Un timeout de Jenkins aborta el lote sin pasar por `CATCH` | Bajo | Las facturas se deshacen; el log puede quedar `EN_CURSO` y la comprobación post-facturación lo detecta |
| Las ejecuciones deshechas dejan huecos en `seq_factura` | Medio | Revisar con Administración si la numeración debe ser correlativa |
| Se desconoce la causa del timeout del 5/10 | Medio | Revisar bloqueos de esa noche. Con los cambios del SP, aunque se repita ya no genera duplicados |
