# SOLUCION.md — Prueba Técnica PHP

**Autor:** Ismael Moreno  
**Fecha:** 2026-10-22

---

## Índice

1. [Cómo ejecutar (Parte 1)](#1-cómo-ejecutar-parte-1)
2. [Ejemplos de uso — curl](#2-ejemplos-de-uso--curl)
3. [Decisiones técnicas — Parte 1](#3-decisiones-técnicas--parte-1)
4. [Soluciones a problemas concretos](#4-soluciones-a-problemas-concretos)
5. [Decisiones técnicas — Parte 2](#5-decisiones-técnicas--parte-2)
6. [Uso de IA durante el desarrollo](#6-uso-de-ia-durante-el-desarrollo)
7. [Estructura de ficheros](#7-estructura-de-ficheros)
8. [Deuda técnica y próximos pasos](#8-deuda-técnica-y-próximos-pasos)

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
docker compose exec app composer install  # primera vez
docker compose exec app ./vendor/bin/phpunit
```

---

## 2. Ejemplos de uso — curl

### 201 — Lectura nueva aceptada

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

### 200 — Reintento (idempotencia)

```bash
# Ejecutar el mismo curl anterior dos veces: la segunda responde 200 + "duplicado": true
```

### 422 — CUPS con dos contratos: resolución por vigencia

```bash
# ES0904688281126584QG tiene contrato 12 (baja 2026-07-15) y contrato 24 (activo desde 2026-07-16).
# Una lectura del 2026-08-01 debe resolverse al contrato 24, no al 12.
curl -s -X POST http://localhost:8000/api/lecturas \
  -H 'Content-Type: application/json' \
  -d '{
    "cups":          "ES0904688281126584QG",
    "fecha_lectura": "2026-08-01",
    "lectura_kwh":   25900,
    "tipo":          "real"
  }' | jq
```

### 422 — CUPS dado de baja en la fecha

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

### 422 — Fecha futura

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

### 400 — Validación de formato

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

## 3. Decisiones técnicas — Parte 1

### 3.1 Por qué Arquitectura Hexagonal y no MVC clásico de Yii2

El enunciado especifica Yii2 como framework pero no impone una estructura interna concreta. La decisión fue usar Yii2 **exclusivamente como adaptador HTTP** (routing, parsing del body, respuesta JSON) y construir el núcleo con Arquitectura Hexagonal + DDD.

**Alternativa descartada:** un controlador Yii2 clásico con ActiveRecord habría sido más rápido de escribir. El problema es que mezcla en una sola clase la validación HTTP, las reglas de negocio y las consultas SQL. Resultado: imposible testear sin base de datos, imposible cambiar el framework sin reescribir la lógica.

**Beneficio concreto obtenido:** `RegistrarLecturaHandler` (el caso de uso) no importa ninguna clase de Yii2, PDO ni SQL Server. Los 7 tests unitarios del handler se ejecutan en menos de un segundo sin Docker, sin red y sin base de datos. Esto permite validar toda la lógica de negocio en CI/CD sin infraestructura.

**Coste asumido:** más ficheros (17 vs ~4 en MVC clásico) y la curva de aprendizaje del patrón. Se considera un coste justificado para una base de código que crecerá.

---

### 3.2 Por qué Value Objects para Cups, LecturaKwh y TipoLectura

**Problema evitado: Primitive Obsession.** Sin VOs, el código pasa `string $cups` de función en función. Cada función que recibe ese string podría recibir `"INVALIDO"`, `""` o `null` y nadie garantiza que la validación se haya ejecutado antes.

Con `Cups::fromString("ES0904998698797309NB")` la invariante se ejecuta **en el momento de construcción**. Si el objeto existe, es válido. No hay que repetir la validación en el handler, en el repositorio ni en los tests.

```php
// Sin VO: la validación puede estar en cualquier sitio (o en ninguno)
function guardarLectura(string $cups, int $kwh): void { ... }

// Con VO: si el objeto existe, el dato es válido
function guardarLectura(Cups $cups, LecturaKwh $kwh): void { ... }
```

---

### 3.3 Por qué resolución del contrato por vigencia en la fecha y no por estado actual

Los datos de muestra contienen el CUPS `ES0904688281126584QG` con dos contratos:

- Contrato 12: activo hasta `2026-07-15` (estado `baja`)
- Contrato 24: activo desde `2026-07-16` (estado `activo`)

Si se filtrara por `estado = 'activo'`, una lectura con `fecha_lectura = "2026-06-01"` se asignaría erróneamente al contrato 24 o no encontraría contrato. La lectura correcta corresponde al contrato 12, que estaba vigente en esa fecha aunque ahora esté de baja.

La consulta SQL busca el contrato donde `fecha_alta <= fecha_lectura` y (`fecha_baja IS NULL` o `fecha_baja >= fecha_lectura`). El estado `baja` solo se usa para determinar el código de error en la respuesta, no para filtrar.

**Consecuencia:** la API acepta lecturas históricas legítimas de contratos ya finalizados, lo cual es correcto en el dominio eléctrico (una distribuidora puede enviar lecturas con retraso).

---

### 3.4 Por qué idempotencia por clave natural y no por Idempotency-Key header

Dos opciones comunes para idempotencia en APIs REST:

1. **Header `Idempotency-Key`:** el cliente genera un UUID por petición y lo envía en una cabecera. El servidor guarda el resultado asociado a esa clave.
2. **Clave natural del dominio:** el servidor detecta el duplicado comparando los datos de negocio.

Se eligió la opción 2 porque la clave natural `(contrato_id, fecha_lectura, lectura_kwh, tipo)` identifica una lectura de forma unívoca en el dominio eléctrico. La distribuidora no necesita gestionar UUIDs. El comportamiento es predecible y auditables sin tablas adicionales.

**Caso borde manejado:** si llega la misma fecha y CUPS pero con distinto `lectura_kwh` o `tipo`, se inserta como nueva lectura. Se interpreta como una corrección legítima (p.ej. la distribuidora envía una estimada y luego la real).

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

**Problema:** el esquema permite que un CUPS tenga varios contratos (cambios de titular). Una query `WHERE cups = ? AND estado = 'activo'` devolvería el contrato actual pero fallaría para lecturas históricas de contratos anteriores.

**Solución:** la query del repositorio devuelve todos los contratos del CUPS ordenados por `fecha_alta DESC` y selecciona el que cubre la `fecha_lectura`. Si hay solapamiento de vigencias en los datos (error de datos), se usa el de mayor id como criterio de desempate.

```sql
SELECT TOP 1 *
FROM   contratos
WHERE  cups = :cups
  AND  fecha_alta <= :fecha
  AND  (fecha_baja IS NULL OR fecha_baja >= :fecha)
ORDER  BY fecha_alta DESC, id DESC
```

### 4.2 Distinción entre CUPS_NO_ENCONTRADO, CONTRATO_BAJA y FECHA_FUERA_VIGENCIA

Tres errores distintos con la misma causa aparente ("no se encontró contrato válido"). La distinción es importante para que la distribuidora sepa exactamente qué corregir:

| Situación | Error devuelto |
|-----------|---------------|
| No existe ningún contrato con ese CUPS | `CUPS_NO_ENCONTRADO` |
| Existe contrato pero estaba de baja en esa fecha | `CONTRATO_BAJA` |
| Existe contrato pero la fecha no cae dentro de su vigencia (`fecha_alta`/`fecha_baja`) | `FECHA_FUERA_VIGENCIA` |

El repositorio hace dos consultas: primero verifica si el CUPS existe en absoluto, luego busca la vigencia en la fecha concreta. Esto permite devolver el error más específico.

### 4.3 Inyección de dependencias en Yii2 sin contenedor externo

Yii2 tiene su propio DI container (`Yii::$container`). Para no introducir una librería de DI adicional (p.ej. PHP-DI) y mantener las dependencias del proyecto al mínimo, se registran los bindings en `config/web.php`:

```php
'container' => [
    'definitions' => [
        \App\Domain\Contrato\ContratoRepository::class =>
            \App\Infrastructure\Persistence\SqlServerContratoRepository::class,
        \App\Domain\Lectura\LecturaRepository::class =>
            \App\Infrastructure\Persistence\SqlServerLecturaRepository::class,
    ],
],
```

El controlador recibe el handler por constructor injection; Yii2 lo resuelve automáticamente.

---

## 5. Decisiones técnicas — Parte 2

### 5.1 Por qué la hipótesis principal es H1 (reintento sin limpiar estado)

El log de Jenkins muestra timeout a los 45 minutos. Un proceso que timeout sin transacción completa deja filas en estado intermedio. La combinación de síntomas —facturas duplicadas Y facturas con importes anómalos para el mismo periodo— es coherente con dos ejecuciones parciales del SP, no con un simple error de datos.

**H2 (SP sin guarda de idempotencia)** sería la causa estructural que convierte H1 en un problema: si el SP tuviera la guarda, el reintento sería inofensivo.

### 5.2 Por qué la corrección no usa DELETE

Los scripts de corrección cambian `estado = 'anulada'` en lugar de hacer `DELETE`. Motivos:

1. **Auditoría:** las facturas anuladas quedan como evidencia del error para auditorías regulatorias (sector eléctrico regulado).
2. **Reversibilidad:** si la corrección es incorrecta, se puede deshacer cambiando el estado de vuelta.
3. **Integridad referencial:** otras tablas pueden tener FKs apuntando a esas facturas (p.ej. `reclamaciones`).

### 5.3 Por qué el constraint UNIQUE se aplica después de la corrección

`ALTER TABLE facturas ADD CONSTRAINT uq_facturas_contrato_periodo UNIQUE (contrato_id, periodo)` fallaría si hay duplicados activos en la tabla. El orden correcto es: anular duplicados (T8) → aplicar constraint (T9). El constraint actúa como red de seguridad para impedir que el problema se repita, no como corrección.

### 5.4 Por qué se recomienda hacer el SP set-based

El timeout de 45 minutos (vs ~1 min en agosto) sugiere que el SP procesa fila a fila con un cursor. Con set-based SQL, la facturación de miles de contratos se convierte en una sola operación de inserción masiva en lugar de N inserciones individuales con N bloqueos de fila. La mejora de rendimiento esperada es de uno a dos órdenes de magnitud.

---

## 6. Uso de IA durante el desarrollo

### 6.1 Qué se usó

Se utilizó un asistente de IA (Kiro) durante todo el proceso de desarrollo como herramienta de apoyo.

### 6.2 Cómo se usó y para qué

**Diseño arquitectónico:**  
Se usó la IA para validar la estructura hexagonal propuesta antes de escribir código: confirmar que los puertos (`ContratoRepository`, `LecturaRepository`) estaban bien definidos y que la dirección de dependencias era correcta (infraestructura → aplicación → dominio, nunca al revés).

**Generación de código repetitivo:**  
Los Value Objects (`Cups`, `LecturaKwh`, `TipoLectura`) siguen un patrón idéntico (constructor privado, factory estática, validación, getter). Se generaron los tres con la IA a partir de un ejemplo y se revisaron manualmente antes de incorporarlos.

**Casos de error en el controlador:**  
El mapeo de excepciones de dominio a códigos HTTP (try/catch con múltiples bloques) se generó con ayuda de la IA y se revisó para asegurar que no había excepciones sin capturar.

**Tests unitarios:**  
El esqueleto de los tests PHPUnit (setup de mocks, estructura de los casos) se generó con la IA. Cada caso de test fue revisado para confirmar que el mock respondía correctamente y que el assertion era significativo.

**SQL de diagnóstico (Parte 2):**  
Las queries de `02_facturas_duplicadas.sql` y `03_facturas_importe_anomalo.sql` se generaron con ayuda de la IA y se revisaron para adaptarlas al esquema real de la BD.

**Documentación:**  
El SDD, el TASKS.md y este SOLUCION.md se redactaron con asistencia de IA, partiendo de las decisiones técnicas tomadas previamente y estructurándolas de forma coherente.

### 6.3 Qué no se delegó en la IA

- **Análisis del esquema de BD:** leer `init.sql` (19.000 líneas en parte1, 313.000 en parte2) y extraer las tablas relevantes fue un paso manual de comprensión del dominio.
- **Detección del caso `ES0904688281126584QG`:** identificar que un CUPS podía tener dos contratos históricos y que eso rompía la lógica `WHERE estado = 'activo'` fue una decisión de diseño propia, no generada por la IA.
- **Hipótesis de la incidencia de facturación:** el razonamiento sobre por qué el timeout + reintento es más probable que un error de datos fue un análisis propio, basado en el log de Jenkins y el patrón de síntomas.
- **Revisión de todo el código generado:** cada fichero generado fue leído y entendido antes de considerarlo parte de la solución.

### 6.4 Criterio general de uso

La IA se usó como acelerador para código estructural y repetitivo, y como segundo par de ojos para confirmar decisiones de diseño. Las decisiones de negocio (qué validar, qué error devolver, cómo modelar el dominio) fueron siempre propias. Todo el código generado fue revisado; nada se incorporó sin entenderlo.

---

## 7. Estructura de ficheros

```
parte1/app/
├── src/
│   ├── Domain/
│   │   ├── Contrato/
│   │   │   ├── Cups.php                          ← VO: validación ES + longitud
│   │   │   ├── Contrato.php                      ← Entity: lógica de vigencia
│   │   │   ├── ContratoRepository.php            ← Puerto (interface)
│   │   │   └── Exception/
│   │   │       ├── CupsNoEncontradoException.php
│   │   │       ├── ContratoBajaException.php
│   │   │       └── FechaFueraVigenciaException.php
│   │   └── Lectura/
│   │       ├── Lectura.php                       ← Aggregate Root
│   │       ├── LecturaKwh.php                    ← VO: entero ≥ 0
│   │       ├── TipoLectura.php                   ← VO: real|estimada
│   │       ├── LecturaRepository.php             ← Puerto (interface)
│   │       └── Exception/
│   │           └── FechaFuturaException.php
│   ├── Application/
│   │   └── RegistrarLectura/
│   │       ├── RegistrarLecturaCommand.php       ← DTO entrada
│   │       ├── RegistrarLecturaHandler.php       ← Use Case
│   │       └── LecturaRegistradaResponse.php     ← DTO salida
│   └── Infrastructure/
│       ├── Persistence/
│       │   ├── SqlServerContratoRepository.php   ← Adaptador BD (PDO)
│       │   └── SqlServerLecturaRepository.php    ← Adaptador BD (PDO)
│       └── Http/
│           └── LecturaController.php             ← Adaptador HTTP (Yii2)
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

## 8. Deuda técnica y próximos pasos

| # | Ítem | Prioridad |
|---|------|-----------|
| 1 | Autenticación: API key en `X-Api-Key` o mTLS para la distribuidora | Alta |
| 2 | Tests de integración de repositorios (SQL real con Testcontainers) | Media |
| 3 | Paginación o batch endpoint para envíos masivos | Media |
| 4 | Logging estructurado (p.ej. Monolog) para trazabilidad de peticiones | Media |
| 5 | Validación de coherencia del acumulado kWh (opcional, configurable) | Baja |
| 6 | Hacer el SP de facturación set-based para evitar timeouts | Alta (Parte 2) |
| 7 | Añadir test de integración E2E con Docker Compose en CI | Media |
