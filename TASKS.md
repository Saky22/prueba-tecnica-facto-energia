# TASKS — Prueba Técnica PHP

**Proyecto:** Comercializadora de electricidad  
**Fecha:** 2026-10-22  
**Autor:** Ismael Moreno

---

## PARTE 1 — API de recepción de lecturas

### T1 · Infraestructura base

**Objetivo:** Levantar el entorno Docker con PHP 8.3 + SQL Server y Yii2 instalado.

- [ ] **T1.1** Completar `Dockerfile` — añadir `WORKDIR /app`, copia de código, `composer install` y `CMD`
- [ ] **T1.2** Verificar `compose.yaml` — volúmenes, `depends_on: db`, healthcheck de SQL Server
- [ ] **T1.3** Completar `config/db.php` — DSN `sqlsrv:Server=db,1433;Database=lecturas`, usuario y contraseña del compose
- [ ] **T1.4** Completar `config/web.php` — URL rules (`POST api/lecturas → lectura/create`), DI container, modo JSON
- [ ] **T1.5** Verificar `web/index.php` — entry point Yii2 apunta a `config/web.php`
- [ ] **T1.6** Smoke test — `docker compose up --build -d` y `curl http://localhost:8000/` devuelve algo

---

### T2 · Capa de Dominio

**Objetivo:** Núcleo puro de negocio, sin dependencias de framework ni base de datos.

#### T2.1 · Value Objects

- [ ] **T2.1.1** `src/Domain/Contrato/Cups.php` — constructor privado, `fromString()`, valida `^ES[A-Z0-9]{18,20}$`, lanza `\InvalidArgumentException`
- [ ] **T2.1.2** `src/Domain/Lectura/LecturaKwh.php` — constructor privado, `fromInt()`, valida `>= 0`, `value()` retorna int
- [ ] **T2.1.3** `src/Domain/Lectura/TipoLectura.php` — enum backed `string` con casos `Real` y `Estimada`, `fromString()` case-insensitive

#### T2.2 · Entities y Aggregate Root

- [ ] **T2.2.1** `src/Domain/Contrato/Contrato.php` — propiedades `id`, `cups`, `fechaAlta`, `fechaBaja` (nullable), `estado`; métodos `estaVigenteEn(DateTimeImmutable)` y `estaDeBajaEn(DateTimeImmutable)`
- [ ] **T2.2.2** `src/Domain/Lectura/Lectura.php` — Aggregate Root; factory estática `create()` que lanza `FechaFuturaException` si `fecha_lectura > hoy`; método `esDuplicadoDe(Lectura)` compara clave natural `(contrato_id, fecha, kwh, tipo)`

#### T2.3 · Excepciones de dominio

- [ ] **T2.3.1** `src/Domain/Contrato/Exception/CupsNoEncontradoException.php`
- [ ] **T2.3.2** `src/Domain/Contrato/Exception/ContratoBajaException.php`
- [ ] **T2.3.3** `src/Domain/Contrato/Exception/FechaFueraVigenciaException.php`
- [ ] **T2.3.4** `src/Domain/Lectura/Exception/FechaFuturaException.php`

#### T2.4 · Puertos (interfaces de repositorio)

- [ ] **T2.4.1** `src/Domain/Contrato/ContratoRepository.php` — `findByCupsAndFecha(Cups, DateTimeImmutable): Contrato` (lanza excepciones de dominio si no existe o no está vigente)
- [ ] **T2.4.2** `src/Domain/Lectura/LecturaRepository.php` — `findDuplicate(Lectura): ?Lectura` y `save(Lectura): void`

---

### T3 · Capa de Aplicación

**Objetivo:** Caso de uso `RegistrarLectura` que orquesta el dominio.

- [ ] **T3.1** `src/Application/RegistrarLectura/RegistrarLecturaCommand.php` — DTO inmutable con `cups: string`, `fechaLectura: string`, `lecturaKwh: int`, `tipo: string`
- [ ] **T3.2** `src/Application/RegistrarLectura/LecturaRegistradaResponse.php` — DTO de salida con todos los campos de la respuesta JSON + flag `duplicado: bool`
- [ ] **T3.3** `src/Application/RegistrarLectura/RegistrarLecturaHandler.php` — orquesta en orden:
  - Construir Value Objects desde el command (puede lanzar `\InvalidArgumentException`)
  - Llamar `ContratoRepository::findByCupsAndFecha()` (puede lanzar excepciones de dominio)
  - Construir `Lectura::create()` (puede lanzar `FechaFuturaException`)
  - Llamar `LecturaRepository::findDuplicate()` → si existe, retornar response con `duplicado: true`
  - Llamar `LecturaRepository::save()`
  - Retornar `LecturaRegistradaResponse` con `duplicado: false`

---

### T4 · Capa de Infraestructura

**Objetivo:** Adaptadores concretos que conectan dominio con SQL Server y HTTP.

#### T4.1 · Repositorios SQL Server

- [ ] **T4.1.1** `src/Infrastructure/Persistence/SqlServerContratoRepository.php` — implementa `ContratoRepository`; consulta SQL que busca contrato por CUPS y vigencia en fecha; lanza las excepciones de dominio correspondientes
- [ ] **T4.1.2** `src/Infrastructure/Persistence/SqlServerLecturaRepository.php` — implementa `LecturaRepository`; `findDuplicate` busca por `(contrato_id, fecha_lectura, lectura_kwh, tipo)`; `save` hace `INSERT` y recupera el `id` generado

#### T4.2 · Adaptador HTTP (Yii2)

- [ ] **T4.2.1** Crear `src/Infrastructure/Http/LecturaRequest.php` (Yii2 Model) — reglas de validación: `cups` requerido string 20-22 chars `ES*`; `fecha_lectura` fecha ISO no futura; `lectura_kwh` entero `>= 0`; `tipo` in `['real', 'estimada']`
- [ ] **T4.2.2** `src/Infrastructure/Http/LecturaController.php` — `actionCreate()`:
  - Parsear JSON body → cargar en `LecturaRequest` → si inválido → 400 con `{"error":"VALIDACION","campos":{...}}`
  - Llamar `RegistrarLecturaHandler::handle()`
  - Capturar `CupsNoEncontradoException` → 422 `CUPS_NO_ENCONTRADO`
  - Capturar `ContratoBajaException` → 422 `CONTRATO_BAJA`
  - Capturar `FechaFueraVigenciaException` → 422 `FECHA_FUERA_VIGENCIA`
  - Capturar `FechaFuturaException` → 422 `FECHA_FUTURA`
  - Capturar `\Throwable` genérico → 500 `ERROR_INTERNO`
  - Respuesta 201 o 200 con el JSON de `LecturaRegistradaResponse`

#### T4.3 · Wiring DI

- [ ] **T4.3.1** En `config/web.php` registrar en el DI container de Yii2: `ContratoRepository::class → SqlServerContratoRepository::class` y `LecturaRepository::class → SqlServerLecturaRepository::class`
- [ ] **T4.3.2** Verificar que `LecturaController` recibe el handler inyectado (constructor injection o `Yii::$container->get()`)

---

### T5 · Tests

**Objetivo:** Cobertura del caso de uso sin base de datos.

- [ ] **T5.1** `tests/Application/RegistrarLecturaHandlerTest.php` — configurar PHPUnit con mocks de `ContratoRepository` y `LecturaRepository`
- [ ] **T5.2** Test: lectura nueva → 201, `duplicado: false`
- [ ] **T5.3** Test: reintento (mismo contrato + fecha + kwh + tipo) → 200, `duplicado: true`
- [ ] **T5.4** Test: CUPS no encontrado → lanza `CupsNoEncontradoException`
- [ ] **T5.5** Test: contrato de baja en la fecha → lanza `ContratoBajaException`
- [ ] **T5.6** Test: fecha fuera de vigencia → lanza `FechaFueraVigenciaException`
- [ ] **T5.7** Test: fecha futura → lanza `FechaFuturaException`
- [ ] **T5.8** Ejecutar `./vendor/bin/phpunit` dentro del contenedor → todos los tests verdes

---

### T6 · Verificación end-to-end

**Objetivo:** Confirmar todos los caminos de la API con curl contra el entorno levantado.

- [ ] **T6.1** `201` — lectura nueva (CUPS activo, fecha válida, kWh ≥ 0)
- [ ] **T6.2** `200` — reenvío exacto del mismo payload → `"duplicado": true`
- [ ] **T6.3** `422 CONTRATO_BAJA` — CUPS `ES0904229486628360ZC` con fecha en que estaba de baja
- [ ] **T6.4** `422 CUPS_NO_ENCONTRADO` — CUPS inexistente
- [ ] **T6.5** `422 FECHA_FUERA_VIGENCIA` — fecha anterior a `fecha_alta` del contrato
- [ ] **T6.6** `422 FECHA_FUTURA` — `fecha_lectura: "2030-01-01"`
- [ ] **T6.7** `400 VALIDACION` — body con campos inválidos (`cups: "MALO"`, `lectura_kwh: -1`)
- [ ] **T6.8** CUPS con dos contratos (`ES0904688281126584QG`): lectura del 2026-08-01 → contrato id 24 (activo), no id 12 (baja el 2026-07-15)

---

## PARTE 2 — Investigación de incidencia de facturación

### T7 · Diagnóstico

**Objetivo:** Determinar la causa raíz de las facturas duplicadas e importes anómalos en septiembre 2026.

- [ ] **T7.1** Levantar entorno: `docker compose up -d` en `parte2/` y esperar a que SQL Server esté listo
- [ ] **T7.2** Ejecutar `parte2/scripts/01_estado_ejecuciones.sql` — identificar ejecuciones `EN_CURSO` o múltiples ejecuciones del SP para `2026-09`
- [ ] **T7.3** Ejecutar `parte2/scripts/02_facturas_duplicadas.sql` — listar contratos con más de una factura activa en `2026-09`
- [ ] **T7.4** Ejecutar `parte2/scripts/03_facturas_importe_anomalo.sql` — identificar facturas con total > 2× media histórica
- [ ] **T7.5** Ejecutar `parte2/scripts/04_lecturas_sept_anomalas.sql` — detectar lecturas duplicadas o consumos anómalos
- [ ] **T7.6** Ejecutar `parte2/scripts/05_precios_sept.sql` — verificar que los precios vigentes en septiembre son correctos
- [ ] **T7.7** Ejecutar `parte2/scripts/06_reclamaciones_detalle.sql` — cruzar reclamaciones con las facturas afectadas
- [ ] **T7.8** Ejecutar `parte2/scripts/07_sp_definition.sql` — revisar el SP: ¿tiene guarda de idempotencia?, ¿usa cursor/bucle fila a fila?
- [ ] **T7.9** Documentar hallazgos: confirmar cuál de las hipótesis H1–H5 es la causa raíz

---

### T8 · Corrección

**Objetivo:** Anular facturas duplicadas y corregir importes anómalos sin pérdida de datos.

- [ ] **T8.1** Ejecutar `parte2/scripts/10_correccion_anular_duplicados.sql` en modo dry-run (con `ROLLBACK` activo) — revisar qué facturas se anularían
- [ ] **T8.2** Confirmar que las facturas a conservar son las correctas (menor id por `(contrato_id, periodo)`)
- [ ] **T8.3** Ejecutar con `COMMIT` — anular facturas duplicadas
- [ ] **T8.4** Ejecutar `02_facturas_duplicadas.sql` de nuevo — verificar que ya no hay duplicados activos
- [ ] **T8.5** Si hay facturas con importe anómalo: completar `parte2/scripts/11_correccion_importes_anomalos.sql` según el origen encontrado (lectura o precio) y ejecutar

---

### T9 · Prevención

**Objetivo:** Evitar que la incidencia se repita en octubre y meses siguientes.

- [ ] **T9.1** Ejecutar `parte2/scripts/20_prevencion.sql` — añadir `UNIQUE (contrato_id, periodo)` a `facturas` (después de que T8.3 elimine duplicados)
- [ ] **T9.2** Modificar `sp_facturar_periodo` — añadir guarda al inicio: `IF EXISTS (SELECT 1 FROM facturas WHERE contrato_id=... AND periodo=... AND estado<>'anulada') RETURN`
- [ ] **T9.3** Añadir verificación post-ejecución en Jenkins — query que alerta si quedan duplicados activos tras el SP
- [ ] **T9.4** Documentar en `parte2/SOLUCION.md`: recomendación de hacer el SP set-based para reducir tiempo de ejecución y evitar timeouts

---

### T10 · Informe final Parte 2

- [ ] **T10.1** Crear/completar `parte2/SOLUCION.md` con: causa raíz confirmada, contratos afectados, acciones ejecutadas, prevención aplicada
- [ ] **T10.2** Redactar la sección "Informe para negocio" con los datos reales obtenidos en T7–T9
- [ ] **T10.3** Incluir decisiones pendientes que requieren aprobación de negocio (re-emisión, comunicación proactiva a clientes no reclamantes)

---

## Resumen de dependencias

```
T1 (infraestructura)
 └── T2 (dominio)
      └── T3 (aplicación)
           ├── T4 (infraestructura)  ← depende de T1 + T3
           └── T5 (tests)            ← depende de T3; NO depende de T1 ni T4

T6 (e2e) ← depende de T1 + T2 + T3 + T4

T7 (diagnóstico) ← independiente de Parte 1
 └── T8 (corrección) ← requiere T7 completo
      └── T9 (prevención) ← requiere T8.3 ejecutado
           └── T10 (informe) ← requiere T7–T9
```

Los tests de T5 pueden ejecutarse **sin levantar Docker** (solo mocks), lo que permite desarrollar y validar el dominio y la aplicación en paralelo con T1.
