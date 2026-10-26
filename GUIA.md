# Guía de instalación y uso

**Autor:** Isaac Moreno Moreno

Cómo levantar, probar y reiniciar las dos partes de la prueba. Las decisiones técnicas están en `parte1/app/SOLUCION.md` y `parte2/SOLUCION.md`.

---

## 1. Requisitos

| Requisito | Detalle |
|-----------|---------|
| Docker + Docker Compose | Docker Desktop en macOS o Windows; Docker Engine en Linux |
| Memoria | Unos 2 GB por SQL Server. No hace falta tener las dos partes levantadas a la vez |
| Mac con Apple Silicon | Docker Desktop → Settings → General → activar *Use Rosetta for x86/amd64 emulation*. SQL Server solo existe para x86 |
| Puertos libres | `8000` (API), `14331` (BD parte 1), `14332` (BD parte 2) |
| `curl` | Para las pruebas. Opcionales: `jq`, Postman o un cliente SQL (DBeaver, SSMS, VS Code) |

No hace falta tener PHP ni Composer instalados en local: todo se ejecuta dentro de los contenedores.

Si la imagen de PHP ya estaba descargada, actualízala antes de construir, para tener la versión con el parche de seguridad de octubre de 2026 (PHP 8.3.36 o superior):

```bash
docker pull php:8.3-cli-bookworm
```

---

## 2. Parte 1: API de recepción de lecturas

### 2.1 Arrancar

```bash
cd parte1
docker compose up -d --build
```

La primera vez tarda unos minutos: descarga SQL Server, construye la imagen de PHP, carga la base de datos `lecturas` e instala las dependencias con Composer.

Para saber si está lista:

```bash
docker compose logs -f app        # esperar a "PHP Development Server ... started"
docker compose exec app php -v    # debe ser 8.3.36 o superior
```

La API queda en `http://localhost:8000/api/lecturas`.

### 2.2 Probar

**Tests unitarios** (reglas de negocio, sin base de datos):

```bash
docker compose exec app ./vendor/bin/phpunit
```

**Pruebas de humo** (todos los casos contra la API levantada):

```bash
chmod +x smoke.sh      # solo la primera vez
./smoke.sh
```

Muestra OK o FALLO por caso y termina con `Resultado: 12 OK, 0 fallos`. Se puede repetir: las altas aceptan 201 la primera vez y 200 en las siguientes.

**Petición manual:**

```bash
curl -s -X POST http://localhost:8000/api/lecturas \
  -H 'Content-Type: application/json' \
  -d '{"cups":"ES0904998698797309NB","fecha_lectura":"2026-10-01","lectura_kwh":11000,"tipo":"real"}'
```

### 2.3 Contrato de la API (resumen)

`POST /api/lecturas` con `cups`, `fecha_lectura` (`YYYY-MM-DD`), `lectura_kwh` (entero ≥ 0) y `tipo` (`real` | `estimada`).

| HTTP | Significado |
|------|-------------|
| 201 | Lectura guardada |
| 200 | Reintento de una lectura ya recibida (`"duplicado": true`) |
| 400 | Formato incorrecto (`VALIDACION`, con el detalle por campo) |
| 409 | Ya existe una lectura con la misma fecha y tipo pero otro valor (`LECTURA_CONFLICTIVA`) |
| 422 | Rechazada por negocio: `CUPS_NO_ENCONTRADO`, `CONTRATO_BAJA`, `FECHA_FUERA_VIGENCIA` o `FECHA_FUTURA` |

En todos los errores la respuesta indica que la lectura no se ha guardado. Hay más ejemplos en `parte1/app/SOLUCION.md`, sección 2.

### 2.4 Consultar la base de datos

```bash
docker compose exec db /opt/mssql-tools18/bin/sqlcmd -S localhost -U sa -P 'Prueba.Tecnica.2026' -C -I -d lecturas
```

```sql
SELECT TOP 10 * FROM dbo.lecturas ORDER BY id DESC;
GO
```

Desde un cliente gráfico: servidor `localhost,14331`, usuario `sa`, contraseña `Prueba.Tecnica.2026`, base de datos `lecturas` (marcar *Trust server certificate*).

### 2.5 Parar y reiniciar

```bash
docker compose stop        # parar conservando los datos
docker compose down        # borrar los contenedores: el próximo "up" recarga la base de datos desde cero
```

---

## 3. Parte 2: Incidencia de facturación

### 3.1 Arrancar

```bash
cd parte2
docker compose up -d
docker compose logs -f db-init    # esperar a "Base de datos 'facturacion' cargada."
```

### 3.2 Ejecutar diagnóstico, corrección y prevención

```bash
chmod +x run.sh                   # solo la primera vez
./run.sh --diagnostico            # diagnósticos 01-08 + corrección y prevención
./run.sh                          # solo corrección y prevención
```

Orden que sigue `run.sh`:

1. `10`: anula la factura sobrante de los 61 contratos duplicados, conservando la pagada.
2. `11`: mueve las 44 lecturas repetidas del fichero de SUR a `lecturas_descartadas`.
3. `12`: instala la nueva versión de `sp_facturar_periodo`.
4. `13`: calcula el importe correcto de cada contrato (solo lectura).
5. `20`: crea los índices únicos de prevención.
6. Relanza la facturación de septiembre y comprueba que no se crean facturas nuevas ni quedan duplicados.

Si todo va bien termina con `OK: relanzar no crea facturas y no quedan duplicados.`. Los scripts son idempotentes: ejecutar `run.sh` dos veces no cambia nada.

**Un script suelto:**

```bash
docker compose exec -T db /opt/mssql-tools18/bin/sqlcmd -S localhost -U sa -P 'Prueba.Tecnica.2026' \
  -C -I -b -d facturacion < scripts/13_recalculo_propuesto.sql
```

Usa siempre `-I`: los índices filtrados y el procedimiento nuevo necesitan `QUOTED_IDENTIFIER ON`.

### 3.3 Consultar la base de datos

Igual que en la parte 1, pero con el puerto `14332` y la base de datos `facturacion`.

### 3.4 Volver a los datos originales

```bash
docker compose down && docker compose up -d
```

La base de datos vuelve a cargarse desde `db/init.sql`, tal como la recibirán los evaluadores.

---

## 4. Problemas frecuentes

| Síntoma | Causa y solución |
|---------|------------------|
| SQL Server se reinicia o no arranca en un Mac M1/M2/M3 | Falta la emulación x86: activar Rosetta en Docker Desktop (sección 1) |
| `port is already allocated` | Otro proceso usa el 8000, el 14331 o el 14332. Liberarlo o cambiar el puerto en el `compose.yaml` |
| La API no responde justo después del `up` | Composer sigue instalando dependencias. Esperar a ver "Development Server" en `docker compose logs -f app` |
| `vendor/autoload.php` no encontrado | `docker compose exec app composer install` |
| Error 1934 (`QUOTED_IDENTIFIER`) al ejecutar SQL | Falta la opción `-I` en `sqlcmd` |
| `20_prevencion.sql` se detiene por duplicados | No se han ejecutado antes `10` y `11`: usar `run.sh` o seguir el orden de la sección 3.2 |
| `smoke.sh` falla solo en "5 envíos simultáneos" | El contenedor no tiene `PHP_CLI_SERVER_WORKERS`. Recrearlo con `docker compose up -d --force-recreate app` |
| `permission denied` al ejecutar los scripts | `chmod +x parte1/smoke.sh parte2/run.sh` |
