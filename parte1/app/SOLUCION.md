# Solución: prueba técnica PHP

**Autor:** Isaac Moreno Moreno  
**Fecha:** 2026-10-22

---

## Índice

1. [Cómo ejecutar (Parte 1)](#1-cómo-ejecutar-parte-1)
2. [Ejemplos de uso con curl](#2-ejemplos-de-uso-con-curl)
3. [Decisiones técnicas](#3-decisiones-técnicas)
4. [Soluciones a problemas concretos](#4-soluciones-a-problemas-concretos)
5. [Parte 2](#5-parte-2)
6. [Estructura de ficheros](#6-estructura-de-ficheros)
7. [Deuda técnica y próximos pasos](#7-deuda-técnica-y-próximos-pasos)

---

## 1. Cómo ejecutar (Parte 1)

```bash
cd parte1
docker compose up --build -d
```

La API queda disponible en `http://localhost:8000/api/lecturas`.

### Verificar que el contenedor está listo

```bash
docker compose logs -f app
# Esperar a ver: PHP Development Server started
```

### Ejecutar los tests unitarios

```bash
docker compose exec app ./vendor/bin/phpunit
```

### Pruebas de humo contra la API levantada

```bash
./smoke.sh
```

Lanza todos los casos de la sección 2 (201, 200, 409, 422, 400) y 5 envíos simultáneos de la misma lectura, comprobando en la base de datos que solo queda una fila. Indica OK o FALLO por caso y termina con código distinto de 0 si algo falla. Se puede repetir: las altas aceptan 201 la primera vez y 200 después.

---

## 2. Ejemplos de uso con curl

### 201: Lectura nueva aceptada

```bash
curl -s -X POST http://localhost:8000/api/lecturas \
  -H 'Content-Type: application/json' \
  -d '{
    "cups":          "ES0904998698797309NB",
    "fecha_lectura": "2026-10-01",
    "lectura_kwh":   11000,
    "tipo":          "real"
  }' | jq
```

### 200: Reintento (idempotencia)

```bash
# Ejecutar el mismo curl anterior dos veces: la segunda responde 200 + "duplicado": true
```

### 201: CUPS con dos contratos: resolución por vigencia

```bash
# ES0904688281126584QG tiene contrato 12 (baja 2026-07-15) y contrato 24 (activo desde 2026-07-16).
# Una lectura del 2026-08-15 se asigna al contrato 24, no al 12 → 201 con "contrato_id": 24.
curl -s -X POST http://localhost:8000/api/lecturas \
  -H 'Content-Type: application/json' \
  -d '{
    "cups":          "ES0904688281126584QG",
    "fecha_lectura": "2026-08-15",
    "lectura_kwh":   25700,
    "tipo":          "real"
  }' | jq
```

### 409: Misma fecha y tipo con otro valor (lectura conflictiva)

```bash
# Ya existe una lectura real del contrato 24 el 2026-08-01 con 25613 kWh.
# Enviar otro valor para esa misma fecha y tipo no es un reintento: no se guarda.
curl -s -X POST http://localhost:8000/api/lecturas \
  -H 'Content-Type: application/json' \
  -d '{
    "cups":          "ES0904688281126584QG",
    "fecha_lectura": "2026-08-01",
    "lectura_kwh":   25900,
    "tipo":          "real"
  }' | jq
# → 409 {"error":"LECTURA_CONFLICTIVA","mensaje":"...","lectura_existente_id":164,"lectura_kwh_existente":25613}
```

### 422: CUPS dado de baja en la fecha

```bash
curl -s -X POST http://localhost:8000/api/lecturas \
  -H 'Content-Type: application/json' \
  -d '{
    "cups":          "ES0904229486628360ZC",
    "fecha_lectura": "2026-06-01",
    "lectura_kwh":   11324,
    "tipo":          "real"
  }' | jq
# → {"error":"CONTRATO_BAJA","mensaje":"..."}
```

### 422: Fecha futura

```bash
curl -s -X POST http://localhost:8000/api/lecturas \
  -H 'Content-Type: application/json' \
  -d '{
    "cups":          "ES0904998698797309NB",
    "fecha_lectura": "2030-01-01",
    "lectura_kwh":   99999,
    "tipo":          "real"
  }' | jq
```

### 400: Validación de formato

```bash
curl -s -X POST http://localhost:8000/api/lecturas \
  -H 'Content-Type: application/json' \
  -d '{
    "cups":          "INVALIDO",
    "fecha_lectura": "no-es-fecha",
    "lectura_kwh":   -5,
    "tipo":          "otro"
  }' | jq
```

---

## 3. Decisiones técnicas

### 3.1 Estructura del código

La lógica de negocio está separada del framework para poder testearla sin base de datos:

| Pieza | Qué hace |
|-------|----------|
| `LecturaController` (Yii2) | Recibe la petición, valida el formato y traduce cada error a su código HTTP |
| `RegistrarLecturaHandler` | Reglas de negocio: contrato vigente en la fecha, baja, fecha futura, reintento o conflicto |
| `SqlServerContratoRepository`, `SqlServerLecturaRepository` | Consultas SQL con PDO |

El handler no depende de Yii ni de SQL Server, solo de las dos interfaces de repositorio. Por eso los 9 tests cubren todas las reglas con repositorios simulados, en milisegundos y sin Docker.

**Coste asumido:** para un único endpoint es más estructura de la necesaria (interfaces, DTO de entrada y salida, objetos de valor). En una versión real lo simplificaría a controlador, servicio y repositorio, y usaría `Model::rules()` de Yii para la validación de formato, que ahora está repetida entre el controlador y los objetos de valor. No lo he cambiado en la entrega para no tocar código ya probado.

---

### 3.2 Tipos para CUPS, kWh y tipo de lectura

`Cups`, `LecturaKwh` y `TipoLectura` validan su formato al construirse: si el objeto existe, el dato es válido, y el handler y los repositorios no tienen que volver a comprobarlo.

---

### 3.3 Por qué resolución del contrato por vigencia en la fecha y no por estado actual

Los datos de muestra contienen el CUPS `ES0904688281126584QG` con dos contratos:

- Contrato 12: activo hasta `2026-07-15` (estado `baja`)
- Contrato 24: activo desde `2026-07-16` (estado `activo`)

Si se filtrara por `estado = 'activo'`, una lectura con `fecha_lectura = "2026-06-01"` se asignaría erróneamente al contrato 24 o no encontraría contrato. La lectura correcta corresponde al contrato 12, que estaba vigente en esa fecha aunque ahora esté de baja.

El repositorio devuelve todos los contratos del CUPS y el handler (`Contrato::estaVigenteEn`) elige el que cubre la fecha: `fecha_alta <= fecha_lectura` y (`fecha_baja IS NULL` o `fecha_baja >= fecha_lectura`). Así la lectura se imputa al titular correcto.

**Consecuencia:** si el contrato que cubre la fecha está hoy de baja, la lectura se rechaza con `CONTRATO_BAJA`, aplicando literalmente el requisito ("los contratos dados de baja no aceptan lecturas").

**Decisión pendiente de negocio:** la lectura de cierre. En los datos, el contrato 5 tiene una lectura del 2026-08-31 (su fecha de baja) recibida el 2026-09-01, y el contrato 12 una del 2026-07-15 recibida el 2026-07-16: la distribuidora envía la lectura de baja *después* de la baja. Con la regla actual se rechazaría. Si negocio quiere aceptarla, el cambio es una línea en el handler (aceptar si `fecha_lectura <= fecha_baja`).

---

### 3.4 Por qué idempotencia por clave natural y no por Idempotency-Key header

Dos opciones comunes para idempotencia en APIs REST:

1. **Header `Idempotency-Key`:** el cliente genera un UUID por petición y lo envía en una cabecera. El servidor guarda el resultado asociado a esa clave.
2. **Clave natural del dominio:** el servidor detecta el duplicado comparando los datos de negocio.

Se eligió la opción 2: la distribuidora no necesita gestionar UUIDs y el comportamiento es predecible y auditable sin tablas adicionales.

**Clave:** `(contrato_id, fecha_lectura, tipo)`. Un contador solo puede marcar un valor real (o uno estimado) en una fecha:

| Llega… | Resultado |
|--------|-----------|
| Misma clave, mismo `lectura_kwh` | Reintento → `200` con la lectura existente y `"duplicado": true` |
| Misma clave, distinto `lectura_kwh` | Dato contradictorio → `409 LECTURA_CONFLICTIVA`, no se guarda |
| Misma fecha, distinto `tipo` (estimada → real) | Regularización legítima → `201` |

Antes la clave incluía `lectura_kwh`, de modo que dos lecturas reales distintas para el mismo día se guardaban ambas y el consumo quedaba incoherente.

**Concurrencia:** comprobar y luego insertar tiene una carrera si llegan dos reintentos a la vez (justo el caso tras un timeout). La comprobación y la inserción van en una transacción y la `SELECT` usa `WITH (UPDLOCK, HOLDLOCK)`, el patrón estándar de SQL Server: el segundo reintento espera al primero y después ve la fila ya insertada.

No se añadió un índice `UNIQUE` sobre la clave porque los datos existentes ya contienen un duplicado (contrato 3, 2026-06-01, ids 121 y 137, recibidos el 2 y el 3 de junio). Crear el índice exigiría antes decidir qué hacer con esa fila; queda como siguiente paso.

---

### 3.5 Por qué no se valida la coherencia del acumulado kWh

El campo `lectura_kwh` es un valor acumulado del contador físico. Hay dos motivos por los que no se valida que no retroceda respecto a la lectura anterior:

1. **Vuelta del contador (`digitos_contador`):** un contador de 5 dígitos pasa de 99.999 a 0. La lectura nueva es menor que la anterior y es completamente válida.
2. **Lecturas estimadas:** una lectura `estimada` puede corregirse con una `real` posterior de menor valor. La lógica de consumo (diferencia entre lecturas consecutivas) la calcula otro servicio, no esta API.

Esta decisión está documentada como intencional para no introducir una validación que generaría falsos positivos y rechazaría lecturas legítimas.

---

### 3.6 Por qué PHPUnit con mocks en lugar de tests de integración

Los tests del handler usan implementaciones mock de `ContratoRepository` y `LecturaRepository` (interfaces del dominio) en lugar de conectarse a la base de datos de prueba.

**Ventaja:** los tests son deterministas, rápidos (< 1 s el conjunto) y no dependen de que Docker esté levantado, de que la BD tenga los datos correctos ni de la latencia de red.

**Lo que sí requeriría tests de integración:** verificar el SQL concreto de los repositorios. Eso queda como deuda técnica; en una iteración siguiente se añadirían tests con una BD en memoria o con Testcontainers.

---

## 4. Soluciones a problemas concretos

### 4.1 CUPS con múltiples contratos históricos

**Problema:** un CUPS puede tener varios contratos (cambio de titular). Filtrar por `estado = 'activo'` imputaría lecturas históricas al titular equivocado.

**Solución:** `SqlServerContratoRepository::findByCups()` devuelve todos los contratos del CUPS (una sola consulta, `ORDER BY fecha_alta ASC`) y el handler elige el que cubre la `fecha_lectura` con `Contrato::estaVigenteEn()`. Con los datos actuales no hay vigencias solapadas; si las hubiera, se tomaría el primero por `fecha_alta`.

### 4.2 Distinción entre CUPS_NO_ENCONTRADO, CONTRATO_BAJA y FECHA_FUERA_VIGENCIA

Para que la distribuidora sepa qué corregir, cada causa de rechazo tiene su código (todas `422`, la lectura no se guarda):

| Situación | Error devuelto |
|-----------|---------------|
| No existe ningún contrato con ese CUPS | `CUPS_NO_ENCONTRADO` |
| El contrato que cubre la fecha está hoy de baja | `CONTRATO_BAJA` |
| Ningún contrato del CUPS cubre la fecha (anterior al alta o posterior a la baja) | `FECHA_FUERA_VIGENCIA` |
| Fecha posterior a hoy | `FECHA_FUTURA` |

### 4.3 Inyección de dependencias

No se usa el contenedor de Yii2: `config/web.php` construye el PDO, los dos repositorios y el handler, y registra el handler como componente (`Yii::$app->lecturaHandler`). Son tres líneas de wiring explícito, suficiente para un único caso de uso; el controlador no conoce las implementaciones concretas.

---

## 5. Parte 2

El diagnóstico, la corrección y el informe a negocio están en [`parte2/SOLUCION.md`](../../parte2/SOLUCION.md).

---

## 6. Estructura de ficheros

```
parte1/app/
├── src/
│   ├── Domain/
│   │   ├── Contrato/
│   │   │   ├── Cups.php                          ← VO: validación ES + longitud
│   │   │   ├── Contrato.php                      ← Entity: lógica de vigencia
│   │   │   ├── ContratoRepository.php            ← Interfaz de repositorio
│   │   │   └── Exception/
│   │   │       ├── CupsNoEncontradoException.php
│   │   │       ├── ContratoBajaException.php
│   │   │       └── FechaFueraVigenciaException.php
│   │   └── Lectura/
│   │       ├── Lectura.php                       ← Entidad lectura
│   │       ├── LecturaKwh.php                    ← VO: entero ≥ 0
│   │       ├── TipoLectura.php                   ← VO: real|estimada
│   │       ├── LecturaRepository.php             ← Interfaz de repositorio
│   │       └── Exception/
│   │           ├── FechaFuturaException.php
│   │           └── LecturaConflictivaException.php
│   ├── Application/
│   │   └── RegistrarLectura/
│   │       ├── RegistrarLecturaCommand.php       ← DTO entrada
│   │       ├── RegistrarLecturaHandler.php       ← Use Case
│   │       └── LecturaRegistradaResponse.php     ← DTO salida
│   └── Infrastructure/
│       ├── Persistence/
│       │   ├── SqlServerContratoRepository.php   ← SQL Server (PDO)
│       │   └── SqlServerLecturaRepository.php    ← SQL Server (PDO)
│       └── Http/
│           └── LecturaController.php             ← Controlador Yii2
├── config/
│   ├── web.php                                   ← Wiring DI + URL rules
│   └── db.php                                    ← Conexión SQL Server
├── web/
│   └── index.php                                 ← Entry point
├── tests/
│   └── Application/
│       └── RegistrarLecturaHandlerTest.php       ← Tests del use case (sin BD)
├── composer.json
└── phpunit.xml
```

---

## 7. Deuda técnica y próximos pasos

| # | Ítem | Prioridad |
|---|------|-----------|
| 1 | Autenticación: API key en `X-Api-Key` o mTLS para la distribuidora | Alta |
| 2 | Tests de integración de repositorios (SQL real con Testcontainers) | Media |
| 3 | Paginación o batch endpoint para envíos masivos | Media |
| 4 | Logging estructurado (p.ej. Monolog) para trazabilidad de peticiones | Media |
| 5 | Validación de coherencia del acumulado kWh (opcional, configurable) | Baja |
| 6 | Índice `UNIQUE (contrato_id, fecha_lectura, tipo)` en `lecturas`, tras depurar el duplicado histórico del contrato 3 | Media |
| 7 | Añadir test de integración E2E con Docker Compose en CI | Media |
| 8 | Simplificar la estructura (controlador, servicio y repositorio) y validar con `Model::rules()` de Yii | Baja |
