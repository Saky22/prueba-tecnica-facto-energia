# Entrega: prueba técnica PHP

**Autor:** Isaac Moreno Moreno

Este fichero es el punto de entrada. Cada parte tiene un único documento con todo lo demás:

| Parte | Qué es | Documento | Prueba automática |
|-------|--------|-----------|-------------------|
| 1 | API REST (Yii2) para recibir lecturas de contador | [`parte1/SOLUCION.md`](parte1/SOLUCION.md) | `parte1/smoke.sh` |
| 2 | Diagnóstico, corrección y prevención de la incidencia de facturación de septiembre | [`parte2/SOLUCION.md`](parte2/SOLUCION.md) | `parte2/run.sh` |

Los `README.md` son los enunciados originales, sin modificar.

El proceso de trabajo está en `docs/`: el diseño previo con requisitos y trazabilidad ([`docs/SDD.md`](docs/SDD.md)) y su desglose en tareas atómicas con su estado ([`docs/TASKS.md`](docs/TASKS.md)).

---

## 1. Requisitos

- Docker con Docker Compose. Cada SQL Server necesita unos 2 GB; no hace falta tener las dos partes levantadas a la vez.
- **Mac con Apple Silicon:** activar en Docker Desktop *Settings → General → Use Rosetta for x86/amd64 emulation*. SQL Server solo existe para x86.
- Puertos libres: `8000` (API), `14331` (BD parte 1) y `14332` (BD parte 2).
- `curl`. No hace falta PHP ni Composer en local.

El `Dockerfile` fija PHP 8.3.36, la versión con el parche de seguridad de octubre de 2026 (CVE-2026-109618 y CVE-2026-109619).

---

## 2. Arranque rápido

### Parte 1

```bash
cd parte1
docker compose up -d --build                    # la primera vez tarda unos minutos
docker compose exec app ./vendor/bin/phpunit    # 9 tests de las reglas de negocio
./smoke.sh                                      # 12 casos contra la API levantada
```

Resultado esperado: `OK (9 tests ...)` y `Resultado: 12 OK, 0 fallos`.

### Parte 2

```bash
cd parte2
docker compose up -d                            # esperar a: docker compose logs db-init → "cargada"
./run.sh --diagnostico                          # diagnóstico + corrección + prevención + verificación
```

Resultado esperado: `OK: relanzar no crea facturas y no quedan duplicados.`

Para repetir desde los datos originales: `docker compose down && docker compose up -d`.

---

## 3. Resumen de resultados

**Parte 1.** `POST /api/lecturas` guarda la lectura en el contrato vigente en su fecha y rechaza con un código explícito (`CUPS_NO_ENCONTRADO`, `CONTRATO_BAJA`, `FECHA_FUERA_VIGENCIA`, `FECHA_FUTURA`) indicando que no se ha guardado. Los reintentos devuelven la lectura existente sin duplicarla, también si llegan a la vez. Una lectura contradictoria (misma fecha y tipo, otro valor) se rechaza con 409.

**Parte 2.** Coincidieron tres fallos:

| Causa | Contratos | Efecto |
|-------|-----------|--------|
| La facturación se cortó por timeout y se relanzó; el SP no es idempotente | 61 | Factura duplicada; 46 clientes cobrados dos veces (2.956,19 €) |
| El fichero de lecturas de SUR se cargó dos veces | 44 | Consumo de septiembre contado dos veces |
| El SP no prorratea la subida de TEMPO_FIJA del 15/09 | 44 | 1–2 € de más por factura |

En total, 97 contratos (94 clientes) y 1.971,13 € facturados de más. De las 7 reclamaciones, 6 corresponden a estos fallos; la del contrato 97 es una regularización correcta tras meses de lecturas estimadas. El informe para negocio y las decisiones pendientes están en `parte2/SOLUCION.md`.

---

## 4. Puntos clave

Lo que no pedía el enunciado de forma explícita y he resuelto:

**Parte 1**

- **Reintentos simultáneos.** No basta con comprobar antes de insertar: dos reintentos que llegan a la vez pasarían los dos la comprobación. La comprobación y la inserción van en una transacción con bloqueo, y `smoke.sh` lo demuestra con 5 envíos simultáneos que dejan una sola fila.
- **Lectura contradictoria.** Misma fecha y tipo con otro valor no es un reintento: se rechaza con 409 en lugar de guardar dos lecturas reales distintas del mismo día.
- **Cambio de titular.** La lectura se imputa al contrato vigente en su fecha, no al activo hoy (caso real en los datos: CUPS con los contratos 12 y 24).
- **Datos existentes.** Los datos ya traen una lectura duplicada (contrato 3); por eso la idempotencia se garantiza con bloqueo y no con un índice único que no se podría crear.

**Parte 2**

- **Tres causas, no una.** Además del relanzamiento sin control, el fichero de SUR se cargó dos veces y el SP no prorratea un cambio de precio a mitad de mes. Cada causa está cuantificada por separado.
- **Una reclamación que no es un error.** El contrato 97 es una regularización tras tres meses de lecturas estimadas: la factura es correcta y hay que explicarla, no corregirla.
- **Corrección segura con el dinero.** Al anular duplicados se conserva siempre la factura cobrada (en los contratos 59 y 60, conservar "la primera" habría anulado un cobro) y se identifican los 46 cobros a devolver. No se borra nada.
- **Lo técnico separado de lo que decide negocio.** Las facturas emitidas no se reescriben: el script `13` deja calculado el importe correcto de cada una para que negocio decida cómo rectificar.
- **Prevención en dos niveles y verificable.** El proceso (SP con transacción, guarda y prorrateo) y la base de datos (índices únicos). `run.sh` relanza la facturación y comprueba que no cambia nada.

**Ambas partes:** reproducibles con un comando sobre una base de datos recién creada, y con PHP fijado a la versión con el parche de seguridad de octubre de 2026.

---

## 5. Problemas frecuentes

| Síntoma | Solución |
|---------|----------|
| SQL Server no arranca en un Mac M1/M2/M3 | Activar Rosetta en Docker Desktop (sección 1) |
| `port is already allocated` | Liberar el puerto o cambiarlo en el `compose.yaml` |
| La API no responde justo después del `up` | Composer sigue instalando dependencias: esperar a "Development Server" en `docker compose logs -f app` |
| `permission denied` al lanzar los scripts | `chmod +x parte1/smoke.sh parte2/run.sh` |
| Error 1934 (`QUOTED_IDENTIFIER`) al ejecutar un `.sql` a mano | Añadir `-I` a `sqlcmd`, o usar `run.sh` |
| `20_prevencion.sql` se detiene por duplicados | Faltan los scripts `10` y `11`: usar `run.sh` |
| `smoke.sh` falla solo en "5 envíos simultáneos" | `docker compose up -d --force-recreate app` (activa `PHP_CLI_SERVER_WORKERS`) |
