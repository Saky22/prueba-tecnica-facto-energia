# Entrega: prueba técnica PHP

**Autor:** Isaac Moreno Moreno

Este fichero es el punto de entrada. Cada parte tiene un único documento con todo lo demás:

| Parte | Qué es | Documento | Prueba automática |
|-------|--------|-----------|-------------------|
| 1 | API REST (Yii2) para recibir lecturas de contador | [`parte1/SOLUCION.md`](parte1/SOLUCION.md) | `parte1/smoke.sh` |
| 2 | Diagnóstico, corrección y prevención de la incidencia de facturación de septiembre | [`parte2/SOLUCION.md`](parte2/SOLUCION.md) | `parte2/run.sh` |

Los `README.md` son los enunciados originales, sin modificar.

---

## 1. Requisitos

- Docker con Docker Compose. Cada SQL Server necesita unos 2 GB; no hace falta tener las dos partes levantadas a la vez.
- **Mac con Apple Silicon:** activar en Docker Desktop *Settings → General → Use Rosetta for x86/amd64 emulation*. SQL Server solo existe para x86.
- Puertos libres: `8000` (API), `14331` (BD parte 1) y `14332` (BD parte 2).
- `curl`. No hace falta PHP ni Composer en local.

Si la imagen de PHP ya estaba descargada, actualízala para tener la versión con el parche de seguridad de octubre de 2026 (PHP 8.3.36 o superior): `docker pull php:8.3-cli-bookworm`.

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

## 4. Problemas frecuentes

| Síntoma | Solución |
|---------|----------|
| SQL Server no arranca en un Mac M1/M2/M3 | Activar Rosetta en Docker Desktop (sección 1) |
| `port is already allocated` | Liberar el puerto o cambiarlo en el `compose.yaml` |
| La API no responde justo después del `up` | Composer sigue instalando dependencias: esperar a "Development Server" en `docker compose logs -f app` |
| `permission denied` al lanzar los scripts | `chmod +x parte1/smoke.sh parte2/run.sh` |
| Error 1934 (`QUOTED_IDENTIFIER`) al ejecutar un `.sql` a mano | Añadir `-I` a `sqlcmd`, o usar `run.sh` |
| `20_prevencion.sql` se detiene por duplicados | Faltan los scripts `10` y `11`: usar `run.sh` |
| `smoke.sh` falla solo en "5 envíos simultáneos" | `docker compose up -d --force-recreate app` (activa `PHP_CLI_SERVER_WORKERS`) |
