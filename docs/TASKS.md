# TASKS: desglose del trabajo

**Autor:** Isaac Moreno Moreno

Tareas atómicas derivadas del [`SDD.md`](SDD.md). Cada una indica el fichero que la implementa o el resultado que la verifica.

Todas las tareas de la entrega están completadas. Lo que queda fuera del alcance está en `parte1/SOLUCION.md` §4 (siguientes pasos técnicos) y `parte2/SOLUCION.md` §6 (decisiones pendientes de negocio).

---

## Parte 1: API de recepción de lecturas

### T1 · Entorno

- [x] **T1.1** `Dockerfile`: PHP 8.3.36 (parche de seguridad de octubre de 2026) + drivers de SQL Server; dependencias desde `composer.lock`
- [x] **T1.2** `compose.yaml`: `composer install` al arrancar (el bind mount oculta el `vendor/` de la imagen) y `PHP_CLI_SERVER_WORKERS=4` para atender peticiones en paralelo
- [x] **T1.3** `config/db.php`: conexión por variables de entorno
- [x] **T1.4** `config/web.php`: ruta `POST api/lecturas`, respuesta JSON y creación del handler y los repositorios
- [x] **T1.5** `.gitignore`: versionar `composer.lock`, `phpunit.xml` y `parte2/logs/`

### T2 · Reglas y tipos

- [x] **T2.1** Tipos con validación: `Cups` (`ES` + 18 a 20 caracteres), `LecturaKwh` (≥ 0) y `TipoLectura` (`real` | `estimada`)
- [x] **T2.2** `Contrato::estaVigenteEn(fecha)`; `Lectura` inmutable
- [x] **T2.3** Excepciones: `CupsNoEncontrado`, `ContratoBaja`, `FechaFueraVigencia`, `FechaFutura` y `LecturaConflictiva`
- [x] **T2.4** Interfaces de repositorio: `ContratoRepository::findByCups`; `LecturaRepository::findByClave`, `save` y `transaccional`

### T3 · Caso de uso

- [x] **T3.1** `RegistrarLecturaCommand` (entrada) y `LecturaRegistradaResponse` (salida, con `duplicado`)
- [x] **T3.2** `RegistrarLecturaHandler`: fecha futura → contrato vigente en la fecha → baja → idempotencia en transacción

### T4 · Base de datos y HTTP

- [x] **T4.1** `SqlServerContratoRepository`: todos los contratos del CUPS ordenados por `fecha_alta`
- [x] **T4.2** `SqlServerLecturaRepository`: `findByClave` con `UPDLOCK, HOLDLOCK`, inserción con `OUTPUT INSERTED.id` y transacción PDO
- [x] **T4.3** `LecturaController`: validación de formato (400) y traducción de excepciones (409, 422, 500)

### T5 · Tests unitarios (sin base de datos)

- [x] **T5.1** Lectura nueva → se guarda (`duplicado: false`)
- [x] **T5.2** Reintento con el mismo kWh → devuelve la existente, sin guardar
- [x] **T5.3** Misma clave con otro kWh → `LecturaConflictivaException`, sin guardar
- [x] **T5.4** Real después de una estimada en la misma fecha → se guarda
- [x] **T5.5** CUPS inexistente, contrato de baja, fecha fuera de vigencia y fecha futura → excepción correspondiente
- [x] **T5.6** CUPS con dos contratos → se asigna al vigente en la fecha (24, no 12)
- [x] **T5.7** Suite completa: **9 tests en verde**

### T6 · Pruebas contra la API levantada (`parte1/smoke.sh`)

- [x] **T6.1** 201: lectura nueva
- [x] **T6.2** 200: reenvío idéntico (`"duplicado": true`)
- [x] **T6.3** 409: misma fecha y tipo con otro kWh
- [x] **T6.4** 201: estimada en la misma fecha que una real
- [x] **T6.5** 201: CUPS con dos contratos → contrato 24
- [x] **T6.6** 422: `CONTRATO_BAJA`, `CUPS_NO_ENCONTRADO`, `FECHA_FUERA_VIGENCIA` y `FECHA_FUTURA`
- [x] **T6.7** 400: campos inválidos y JSON inválido
- [x] **T6.8** 5 envíos simultáneos → 1 sola fila en la base de datos
- [x] **T6.9** Resultado: **12 OK, 0 fallos**

---

## Parte 2: Incidencia de facturación

### T7 · Diagnóstico

- [x] **T7.1** `01`: ejecución 30 en `EN_CURSO` y ejecución 31 relanzada a mano
- [x] **T7.2** `02`: 61 contratos con dos facturas activas
- [x] **T7.3** `03`, `04`: importes y consumos anómalos de septiembre
- [x] **T7.4** `05`: subida de TEMPO_FIJA el 15/09
- [x] **T7.5** `06`: reclamaciones cruzadas con sus facturas
- [x] **T7.6** `07`: el SP no tiene guarda ni transacción y no prorratea el precio
- [x] **T7.7** `08`: `SUR_20261003_01.csv` repite las 44 filas de `SUR_20261002_01.csv`
- [x] **T7.8** Hipótesis H1–H4 confirmadas, H5 descartada; el contrato 97 es una regularización correcta

### T8 · Corrección

- [x] **T8.1** `10`: anular la factura sobrante, conservando la pagada → **61 anuladas, 46 cobros a devolver (2.956,19 €)**
- [x] **T8.2** `11`: mover las lecturas repetidas a `lecturas_descartadas` → **44 lecturas**
- [x] **T8.3** `13`: importe correcto por contrato, solo lectura → **74 facturas, 1.971,13 € facturados de más**

### T9 · Prevención

- [x] **T9.1** `12`: SP con transacción y `XACT_ABORT`, guarda de idempotencia, precio prorrateado y registro de errores
- [x] **T9.2** `20`: índice único filtrado en `facturas`, índice único en `lecturas` y comprobación post-facturación
- [x] **T9.3** Paso propuesto para el job de Jenkins (`parte2/SOLUCION.md` §5)

### T10 · Informe

- [x] **T10.1** `parte2/SOLUCION.md`: diagnóstico, alcance, corrección, prevención e informe para negocio

### T11 · Verificación (`parte2/run.sh` sobre base de datos recién creada)

- [x] **T11.1** Cifras confirmadas: 61 / 46 / 44 / 74 / 1.971,13 €
- [x] **T11.2** Relanzar la facturación de septiembre → 203 facturas antes y después
- [x] **T11.3** Sin facturas duplicadas, sin contratos sin factura y sin lecturas duplicadas
- [x] **T11.4** Segunda ejecución sin cambios ni errores (idempotente)

---

## Documentación

- [x] **D1** `ENTREGA.md`: punto de entrada, arranque rápido y resumen
- [x] **D2** `parte1/SOLUCION.md` y `parte2/SOLUCION.md`: uso y decisiones de cada parte
- [x] **D3** `docs/SDD.md` y `docs/TASKS.md`: diseño, trazabilidad y desglose

---

## Dependencias

```
T1 ─► T2 ─► T3 ─┬─► T4 ─► T6
                └─► T5              (sin Docker: solo mocks)

T7 ─► T8 ─► T9 ─► T10 ─► T11        (independiente de la parte 1)
```
