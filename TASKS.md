# TASKS: Prueba técnica PHP

**Proyecto:** Comercializadora de electricidad
**Fecha:** 2026-10-23 (estado actualizado)
**Autor:** Isaac Moreno Moreno

Leyenda: `[x]` hecho · `[ ]` pendiente

---

## PARTE 1: API de recepción de lecturas

### T1 · Infraestructura base

- [x] **T1.1** `Dockerfile`: PHP 8.3 + drivers SQL Server; instala dependencias desde `composer.lock`
- [x] **T1.2** `compose.yaml`: `composer install` al arrancar (el bind mount oculta el `vendor/` de la imagen) y servidor PHP en el puerto 8000
- [x] **T1.3** `config/db.php`: DSN por variables de entorno, con los valores del compose por defecto
- [x] **T1.4** `config/web.php`: ruta `POST api/lecturas → lectura/create`, respuesta JSON y wiring explícito del handler
- [x] **T1.5** `web/index.php`: punto de entrada de Yii2
- [x] **T1.6** `.gitignore`: versiona `composer.lock`, `phpunit.xml` y `parte2/logs/`

### T2 · Dominio

- [x] **T2.1** Value Objects: `Cups` (`^ES[A-Z0-9]{18,20}$`), `LecturaKwh` (≥ 0) y `TipoLectura` (`real` | `estimada`)
- [x] **T2.2** `Contrato` con `estaVigenteEn(fecha)`; `Lectura` como entidad inmutable
- [x] **T2.3** Excepciones: `CupsNoEncontrado`, `ContratoBaja`, `FechaFueraVigencia`, `FechaFutura` y `LecturaConflictiva`
- [x] **T2.4** Puertos:
  - `ContratoRepository::findByCups(Cups): Contrato[]`
  - `LecturaRepository::findByClave(contratoId, fecha, tipo): ?Lectura`, `save(Lectura): Lectura` y `transaccional(callable)`

### T3 · Aplicación

- [x] **T3.1** `RegistrarLecturaCommand` y `LecturaRegistradaResponse` (con `duplicado`)
- [x] **T3.2** `RegistrarLecturaHandler`: fecha futura → contrato vigente en la fecha → estado de baja → idempotencia en transacción (reintento → 200, otro kWh → 409, nueva → 201)

### T4 · Infraestructura

- [x] **T4.1** `SqlServerContratoRepository`: todos los contratos del CUPS ordenados por `fecha_alta`
- [x] **T4.2** `SqlServerLecturaRepository`: `findByClave` con `UPDLOCK, HOLDLOCK`, `INSERT ... OUTPUT INSERTED.id` y transacción PDO
- [x] **T4.3** `LecturaController`: validación de formato (400), traducción de excepciones (409, 422, 500) y respuesta 201/200

### T5 · Tests unitarios

- [x] **T5.1** Lectura nueva → `duplicado: false`
- [x] **T5.2** Reintento con el mismo kWh → `duplicado: true`, sin `save`
- [x] **T5.3** Misma clave con otro kWh → `LecturaConflictivaException`, sin `save`
- [x] **T5.4** Real después de una estimada en la misma fecha → se guarda
- [x] **T5.5** CUPS inexistente, contrato de baja, fecha fuera de vigencia y fecha futura → excepción correspondiente
- [x] **T5.6** CUPS con dos contratos → se asigna al vigente en la fecha (24, no 12)
- [x] **T5.7** Ejecutar la suite: 9 tests en verde

### T6 · Verificación end-to-end (curl contra el entorno levantado)

- [ ] **T6.1** `201`: lectura nueva
- [ ] **T6.2** `200`: reenvío exacto → `"duplicado": true`
- [ ] **T6.3** `409 LECTURA_CONFLICTIVA`: contrato 24, 2026-08-01, con un kWh distinto de 25613
- [ ] **T6.4** `422 CONTRATO_BAJA`: `ES0904229486628360ZC` con fecha 2026-06-01
- [ ] **T6.5** `422 CUPS_NO_ENCONTRADO`, `FECHA_FUERA_VIGENCIA` y `FECHA_FUTURA`
- [ ] **T6.6** `400 VALIDACION`: campos inválidos
- [ ] **T6.7** Dos peticiones simultáneas iguales → una sola fila en `lecturas`

### T7 · Documentación

- [x] **T7.1** `parte1/app/SOLUCION.md`: ejecución, ejemplos curl, decisiones y siguientes pasos alineados con el código

### Backlog parte 1 (fuera del alcance de v1)

- [ ] Decisión de negocio: aceptar la lectura de cierre (fecha = `fecha_baja`, recibida después de la baja)
- [ ] Validar `lectura_kwh` contra `digitos_contador` (máximo 99.999 o 999.999)
- [ ] Rechazar fechas imposibles (`2026-02-30`): validar con `'!Y-m-d'` y comprobar que se conserva el mismo texto
- [ ] Índice único `lecturas(contrato_id, fecha_lectura, tipo)` tras depurar el duplicado del contrato 3
- [ ] Autenticación de la distribuidora (API key o mTLS)
- [ ] Tests de integración de los repositorios contra SQL Server

---

## PARTE 2: Incidencia de facturación

### T8 · Diagnóstico

- [x] **T8.1** `01`: ejecución 30 en `EN_CURSO` y ejecución 31 manual para `2026-09`
- [x] **T8.2** `02`: 61 contratos con dos facturas activas (46 cobradas dos veces)
- [x] **T8.3** `03`, `04` y `08`: consumo duplicado en 44 contratos de SUR por la recarga del fichero
- [x] **T8.4** `05`: cambio de precio de TEMPO_FIJA el 15/09 sin prorratear (44 contratos)
- [x] **T8.5** `06`: las 7 reclamaciones cruzadas con su causa; la 7 (contrato 97) es una regularización correcta
- [x] **T8.6** `07`: el SP no tiene guarda, no usa transacción y toma el precio con `vigente_hasta IS NULL`
- [x] **T8.7** Hipótesis H1–H4 confirmadas y H5 descartada (ver `SDD.md` §3.2)

### T9 · Corrección

- [x] **T9.1** `10`: anular la factura sobrante, conservando la pagada (61 facturas)
- [x] **T9.2** `11`: mover las 44 lecturas repetidas a `lecturas_descartadas`
- [x] **T9.3** `13`: importe correcto por contrato (74 incorrectas, 1.971,13 €), solo lectura

### T10 · Prevención

- [x] **T10.1** `12`: SP con transacción + `XACT_ABORT`, guarda de idempotencia, precio prorrateado y registro de errores
- [x] **T10.2** `20`: índices únicos en `facturas` (filtrado) y en `lecturas`, más la comprobación post-facturación

### T11 · Informe

- [x] **T11.1** `parte2/SOLUCION.md`: diagnóstico, alcance, corrección, prevención e informe para negocio con las decisiones pendientes

### T12 · Verificación

- [ ] **T12.1** Ejecutar `10`, `11`, `12`, `13` y `20` sobre una base de datos recién creada con `docker compose up` y comprobar las cifras (61 / 44 / 74)
- [ ] **T12.2** Relanzar `sp_facturar_periodo '2026-09'` tras la corrección → 0 facturas nuevas y log `OK` con los omitidos
- [ ] **T12.3** Ejecutar `20` dos veces → sin errores (idempotente)

### Pendiente de negocio

- [ ] Devolución o compensación de los 46 cobros duplicados (2.956,19 €)
- [ ] Rectificativas o regularización de las 74 facturas con importe incorrecto
- [ ] Comunicación proactiva a los 94 clientes afectados o solo a los reclamantes
- [ ] Explicación de la regularización al cliente 97
- [ ] Pedir explicación del doble envío a la distribuidora SUR

---

## Dependencias

```
T1 ─► T2 ─► T3 ─┬─► T4 ─► T6
                └─► T5            (sin Docker: solo mocks)

T8 ─► T9 ─► T10 ─► T11 ─► T12     (independiente de la parte 1)
```
