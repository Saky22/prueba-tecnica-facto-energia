# Parte 1: API de recepción de lecturas

**Autor:** Isaac Moreno Moreno

Requisitos y arranque rápido en [`ENTREGA.md`](../ENTREGA.md).

---

## 1. Ejecutar y probar

```bash
cd parte1
docker compose up -d --build                    # API en http://localhost:8000/api/lecturas
docker compose exec app ./vendor/bin/phpunit    # tests unitarios (sin base de datos)
./smoke.sh                                      # todos los casos contra la API levantada
```

`smoke.sh` comprueba los 11 casos de la sección 2 y envía 5 veces a la vez la misma lectura, verificando en la base de datos que solo queda una fila. Muestra OK o FALLO por caso y se puede repetir.

Base de datos: `localhost,14331`, usuario `sa`, contraseña `Prueba.Tecnica.2026`, base `lecturas`. Desde el contenedor:

```bash
docker compose exec db /opt/mssql-tools18/bin/sqlcmd -S localhost -U sa -P 'Prueba.Tecnica.2026' -C -I -d lecturas
```

---

## 2. Contrato de la API

`POST /api/lecturas` con cuerpo JSON:

| Campo | Tipo | Validación |
|-------|------|------------|
| `cups` | string | `ES` + 18 a 20 caracteres alfanuméricos |
| `fecha_lectura` | `YYYY-MM-DD` | No posterior a hoy |
| `lectura_kwh` | entero | ≥ 0 |
| `tipo` | string | `real` o `estimada` |

| HTTP | `error` | Cuándo | ¿Se guarda? |
|------|---------|--------|-------------|
| 201 | | Lectura nueva | Sí |
| 200 | | Reintento: misma lectura ya recibida (`"duplicado": true`) | Ya estaba |
| 400 | `VALIDACION` | JSON inválido o campos incorrectos (`campos` detalla cada uno) | No |
| 409 | `LECTURA_CONFLICTIVA` | Misma fecha y tipo con otro valor de kWh | No |
| 422 | `CUPS_NO_ENCONTRADO` | No hay ningún contrato con ese CUPS | No |
| 422 | `CONTRATO_BAJA` | El contrato que cubre la fecha está dado de baja | No |
| 422 | `FECHA_FUERA_VIGENCIA` | Ningún contrato del CUPS cubre la fecha | No |
| 422 | `FECHA_FUTURA` | Fecha posterior a hoy | No |
| 500 | `ERROR_INTERNO` | Error no previsto (queda en el log) | No |

En los errores, el `mensaje` indica siempre que la lectura no se ha guardado y por qué.

**Ejemplos:**

```bash
# 201 (la primera vez) y 200 con "duplicado": true (si se repite)
curl -s -X POST http://localhost:8000/api/lecturas -H 'Content-Type: application/json' \
  -d '{"cups":"ES0904998698797309NB","fecha_lectura":"2026-10-01","lectura_kwh":11000,"tipo":"real"}'

# 201 asignada al contrato 24: el CUPS cambió de titular el 2026-07-16
curl -s -X POST http://localhost:8000/api/lecturas -H 'Content-Type: application/json' \
  -d '{"cups":"ES0904688281126584QG","fecha_lectura":"2026-08-15","lectura_kwh":25700,"tipo":"real"}'

# 409: ya existe la lectura real del 2026-08-01 con 25613 kWh
curl -s -X POST http://localhost:8000/api/lecturas -H 'Content-Type: application/json' \
  -d '{"cups":"ES0904688281126584QG","fecha_lectura":"2026-08-01","lectura_kwh":25900,"tipo":"real"}'

# 422 CONTRATO_BAJA
curl -s -X POST http://localhost:8000/api/lecturas -H 'Content-Type: application/json' \
  -d '{"cups":"ES0904229486628360ZC","fecha_lectura":"2026-06-01","lectura_kwh":11324,"tipo":"real"}'
```

---

## 3. Decisiones

### 3.1 Contrato vigente en la fecha, no el activo hoy

Un CUPS puede tener varios contratos (cambio de titular). El CUPS `ES0904688281126584QG` tiene el contrato 12 (de baja el 2026-07-15) y el 24 (activo desde el 2026-07-16). La lectura se imputa al contrato cuya vigencia (`fecha_alta ≤ fecha ≤ fecha_baja`) incluye la fecha de la lectura.

Si ese contrato está hoy de baja, la lectura se rechaza con `CONTRATO_BAJA`: es la aplicación literal del requisito ("los contratos dados de baja no aceptan lecturas").

**Pendiente de negocio: la lectura de cierre.** En los datos, los contratos 5 y 12 tienen una lectura en su fecha de baja recibida al día siguiente. Con la regla actual se rechazaría. Si se quiere aceptar, el cambio es una condición en el handler.

### 3.2 Idempotencia por datos de negocio

La clave es `(contrato, fecha_lectura, tipo)`; la distribuidora no tiene que generar identificadores de petición.

| Llega… | Resultado |
|--------|-----------|
| Misma clave y mismo kWh | Reintento: 200 con la lectura existente |
| Misma clave y otro kWh | Dato contradictorio: 409, no se guarda |
| Misma fecha con otro tipo (estimada y después real) | Regularización: 201 |

**Reintentos simultáneos:** la comprobación y la inserción van en una transacción y la consulta usa `WITH (UPDLOCK, HOLDLOCK)`. El segundo reintento espera al primero y encuentra la fila ya insertada (lo verifica `smoke.sh`). No se añadió un índice único porque los datos ya contienen un duplicado (contrato 3, 2026-06-01, ids 121 y 137).

### 3.3 No se valida que el acumulado no retroceda

El contador da la vuelta al superar sus dígitos (99.999 → 0) y una lectura real puede ser menor que una estimada anterior. Rechazarlas daría falsos positivos; el consumo se calcula después, a partir de las lecturas guardadas.

### 3.4 Estructura del código

| Pieza | Qué hace |
|-------|----------|
| `LecturaController` (Yii2) | Valida el formato, llama al caso de uso y traduce cada error a su código HTTP |
| `RegistrarLecturaHandler` | Reglas de negocio. Solo depende de las interfaces de repositorio |
| `SqlServer*Repository` | Consultas SQL con PDO |
| `Cups`, `LecturaKwh`, `TipoLectura` | Tipos que validan su formato al construirse |

Gracias a esta separación, los 9 tests unitarios cubren todas las reglas sin base de datos ni Docker. El wiring está en `config/web.php`, sin contenedor de dependencias.

**Coste asumido:** para un único endpoint es más estructura de la necesaria. En una versión real lo simplificaría a controlador, servicio y repositorio, con la validación en `Model::rules()` de Yii, que ahora está repetida entre el controlador y los tipos. No lo he cambiado para no tocar código ya probado.

---

## 4. Limitaciones y siguientes pasos

| Ítem | Prioridad |
|------|-----------|
| Autenticación de la distribuidora (API key o mTLS) | Alta |
| Decisión de negocio sobre la lectura de cierre (3.1) | Alta |
| Validar `lectura_kwh` contra `digitos_contador` (máximo 99.999 o 999.999) | Media |
| Rechazar fechas imposibles como `2026-02-30` (ahora PHP las convierte en 2 de marzo) | Media |
| Índice único en `lecturas` tras depurar el duplicado del contrato 3 | Media |
| Tests de integración de los repositorios contra SQL Server | Media |
| Envío por lotes si la distribuidora manda muchas lecturas | Baja |
| Simplificar la estructura (3.4) | Baja |
