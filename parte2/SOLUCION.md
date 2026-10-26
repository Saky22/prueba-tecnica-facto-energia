# Parte 2: Incidencia en la facturación de septiembre 2026

**Autor:** Isaac Moreno Moreno

Requisitos y arranque rápido en [`ENTREGA.md`](../ENTREGA.md).

---

## 1. Resumen

Son **tres problemas independientes** que coincidieron en la misma factura, más un caso que parece un error y no lo es:

| # | Qué pasó | Contratos | Efecto |
|---|----------|-----------|--------|
| A | El proceso de facturación se cortó a medias (timeout de Jenkins) y se relanzó a mano. El SP no comprueba si ya hay factura | **61** | Dos facturas del mismo mes. En **46** contratos las dos están **cobradas** |
| B | El fichero de lecturas de la distribuidora SUR se cargó dos veces (2 y 3 de octubre) | **44** | Consumo de septiembre contado **dos veces** |
| C | El SP aplica a todo el mes el precio vigente a fin de mes; TEMPO_FIJA subió el 15/09 | **44** | Del 1 al 14/09 se cobra al precio nuevo (diferencias pequeñas, ~1–2 €) |
| D | Contrato 97: tres meses de lecturas estimadas y en septiembre llega la real | 1 | **No es error**: regularización correcta |

En total hay **97 contratos (94 clientes)** con alguna incidencia. Las cifras salen de los scripts y se reproducen sobre una base de datos recién creada.

---

## 2. Ejecutar

```bash
cd parte2
docker compose up -d
./run.sh --diagnostico   # diagnósticos 01-08 y después corrección y prevención
./run.sh                 # solo corrección y prevención
```

| Script | Tipo | Qué hace |
|--------|------|----------|
| `01`–`08` | Diagnóstico (solo lectura) | Evidencias de cada causa |
| `10_correccion_anular_duplicados.sql` | Corrección | Anula la factura sobrante de los 61 contratos, conservando la pagada |
| `11_correccion_lecturas_duplicadas.sql` | Corrección | Mueve las 44 lecturas repetidas a `lecturas_descartadas` |
| `12_sp_idempotencia.sql` | Prevención | Nueva versión de `sp_facturar_periodo` |
| `13_recalculo_propuesto.sql` | Propuesta (solo lectura) | Importe correcto por contrato y causa de cada reclamación |
| `20_prevencion.sql` | Prevención | Índices únicos y comprobación post-facturación |

`run.sh` los ejecuta en orden con `sqlcmd -I -b` y termina relanzando la facturación de septiembre para comprobar que no crea facturas ni deja duplicados. Todos los scripts de corrección y prevención son idempotentes. Para ejecutar uno suelto, usar siempre `sqlcmd -I` (los índices filtrados necesitan `QUOTED_IDENTIFIER ON`).

---

## 3. Diagnóstico

### Cómo lo investigué

Partí de los dos síntomas (facturas duplicadas e importes altos) y del timeout del log de Jenkins, y contrasté cada hipótesis con los datos:

| Hipótesis | Resultado | Evidencia |
|-----------|-----------|-----------|
| La ejecución se cortó y se relanzó sin limpiar | Confirmada | Ejecución 30 en `EN_CURSO` y ejecución 31 manual (scripts `01`, `02`) |
| El SP no comprueba si ya existe factura | Confirmada | Definición del SP (`07`) |
| Hay lecturas duplicadas en septiembre | Confirmada | Recarga del fichero de SUR (`04`, `08`) |
| Hay un precio erróneo en septiembre | Confirmada en otra forma | El precio es correcto, pero el SP no prorratea el cambio (`05`) |
| El cursor del SP explica el timeout | Descartada | El mismo SP tarda unos 90 s todos los meses y en el relanzamiento |
| Todas las subidas de importe son error | Descartada | Contrato 97: regularización tras lecturas estimadas (`13`) |


### A. Facturas duplicadas: ejecución cortada + SP no idempotente

Cronología (`log_procesos`, log de Jenkins):

1. **05/10 02:00**: Jenkins lanza `sp_facturar_periodo '2026-09'` (ejecución 30).
2. **02:45**: el driver ODBC corta por timeout. El SP no usa transacción, así que las **61 facturas** ya insertadas se quedan y el log queda en `EN_CURSO`.
3. **08:15**: el usuario `jlopez` lo relanza a mano (ejecución 31). El SP no comprueba si ya existe factura y crea las **142**.
4. **06/10**: la emisión procesa **203** facturas (142 + 61). Las domiciliadas se cobran dos veces.

De los 61 pares: en 46 las dos están pagadas, en 9 las dos emitidas y en 6 hay una pagada y otra emitida (scripts `01`, `02`).

**Por qué tardó 45 minutos no se puede saber con estos datos.** El mismo SP tardó unos 90 s todos los meses anteriores y también en el relanzamiento. No lo atribuyo al cursor; habría que revisar si había bloqueos esa noche. Lo importante es que, aunque vuelva a pasar, no genere facturas a medias ni duplicadas (punto 5).

### B. Lecturas de SUR cargadas dos veces

El 03/10 se cargó `SUR_20261003_01.csv` con **las mismas 44 filas** que `SUR_20261002_01.csv`: mismo contrato, periodo, contador y consumo (script `08`). El SP suma todas las lecturas del mes, así que esos 44 contratos se facturaron con el doble de kWh. Ejemplo, reclamación 3: 632 kWh facturados frente a 316 reales. Ninguna otra distribuidora ni ningún otro mes tiene duplicados.

### C. Cambio de precio a mitad de mes

TEMPO_FIJA pasó de 0,135 a 0,149 €/kWh el 15/09. El SP toma el precio con `vigente_hasta IS NULL`, es decir, el nuevo para todo el mes. Lo correcto es prorratear: 14 días a 0,135 y 16 a 0,149 (precio medio 0,142467). Afecta a los 44 contratos TEMPO_FIJA. Ejemplo, reclamación 4: 45,53 € facturados frente a 43,75 €.

### No es error: regularización tras lecturas estimadas

El contrato 97 (reclamación 7) tuvo lecturas **estimadas** en junio, julio y agosto (113, 125 y 156 kWh). La distribuidora se quedó corta, y la lectura **real** de septiembre recoge todo lo pendiente: 745 kWh. La factura de 110,29 € es correcta. Hay que explicárselo al cliente, no corregirlo. El script `13` (bloque 4) lista estos casos.

### Las 7 reclamaciones

| Rec. | Cliente | Causa | Facturado | Correcto |
|------|---------|-------|-----------|----------|
| 1 | 23 | A: duplicada (dos emitidas, sin cobrar) | 2 × 52,49 € | 52,49 € |
| 2 | 88 | B: consumo duplicado (406 frente a 203 kWh) | 62,15 € | 33,33 € |
| 3 | 119 | B: consumo duplicado (632 frente a 316 kWh) | 81,41 € | 43,80 € |
| 4 | 104 | C: precio sin prorratear | 45,53 € | 43,75 € |
| 5 | 7 | A: duplicada y **cobrada dos veces** | 2 × 35,88 € | 35,88 € |
| 6 | 41 | A + B: duplicada, cobrada dos veces y con consumo doble | 2 × 88,56 € | 46,53 € |
| 7 | 97 | Ninguna: regularización tras estimadas | 110,29 € | 110,29 € |

---

## 4. Corrección

- **Facturas duplicadas (`10`):** se anula una factura por contrato (61). Se conserva la **pagada** si la hay; en los contratos 59 y 60 la pagada es la de id mayor, así que conservar "la primera" anularía un cobro. No se borra nada: es auditable y reversible. Las anuladas con `fecha_pago` son los **46 cobros duplicados, 2.956,19 €**, que hay que devolver.
- **Lecturas repetidas (`11`):** se mueven, no se borran, las 44 copias a `dbo.lecturas_descartadas`, con el motivo y la forma de revertirlo en el propio script.
- **Importes (`13`):** **no se modifican las facturas.** Ya están emitidas y en su mayoría cobradas, así que corregirlas exige facturas rectificativas o abonos, que es decisión de negocio. El script da, por contrato, el importe facturado, el correcto, la diferencia y la causa: **74 facturas con importe incorrecto y 1.971,13 € facturados de más**: 1.912,03 € por la recarga de SUR y 59,10 € por el precio sin prorratear.

---

## 5. Prevención

| Medida | Dónde | Evita |
|--------|-------|-------|
| SP en una transacción con `XACT_ABORT` | `12` | Facturas a medias si Jenkins corta la ejecución: se deshace todo |
| Guarda de idempotencia (salta contratos ya facturados) | `12` | Duplicados al relanzar: relanzar pasa a ser seguro |
| Precio y término fijo prorrateados por días | `12` | Causa C, también al refacturar meses pasados |
| Errores registrados en `log_procesos` (`ERROR` + mensaje) | `12` | Ejecuciones que quedan `EN_CURSO` sin explicación |
| Índice único filtrado `facturas(contrato_id, periodo) WHERE estado <> 'anulada'` | `20` | Segunda factura activa, venga de donde venga |
| Índice único `lecturas(contrato_id, fecha_desde, fecha_hasta, tipo)` | `20` | Recargar un fichero: la carga falla en vez de duplicar consumos |
| Comprobación post-facturación en Jenkins (debe devolver 0 filas) | `20` | Detectar el problema antes de la emisión del día siguiente |

Sin cambios de arquitectura: el SP mantiene el cursor y su lógica. Reescribirlo de forma set-based podría hacerlo más rápido, pero no es necesario para resolver esta incidencia.

**Paso propuesto para el job de Jenkins** (no hay un Jenkins de pruebas en el entorno: `run.sh` reproduce la misma comprobación en local):

```groovy
stage('Facturación') {
    steps { sh "php yii facturacion/mensual --periodo=${PERIODO}" }   // con el SP nuevo, relanzar es seguro
}
stage('Comprobación') {
    steps {
        // misma consulta que el bloque 3 de 20_prevencion.sql; debe devolver 0
        sh '''
          n=$(sqlcmd ... -h -1 -W -Q "SET NOCOUNT ON; SELECT COUNT(*) FROM (<comprobación>) x" | tr -d '[:space:]')
          test "$n" = "0" || { echo "Facturación $PERIODO con incidencias: no se emite"; exit 1; }
        '''
    }
}
// La emisión (EMISION_FACTURAS) solo se lanza si esta etapa pasa.
```

**Limitaciones conocidas:**

- Si Jenkins corta la ejecución, SQL Server aborta el lote sin pasar por el `CATCH`. Las facturas se deshacen, pero la fila de log puede quedar `EN_CURSO`; la comprobación post-facturación la detecta.
- Los números consumidos de `seq_factura` en una ejecución deshecha dejan huecos en la numeración. Hay que revisarlo con Administración si la numeración debe ser estrictamente correlativa.

---

## 6. Informe para el responsable de Facturación

> **Asunto:** Incidencia en la facturación de septiembre: qué ha pasado y qué proponemos

**Qué ha pasado.** Han coincidido tres fallos:

1. El proceso de facturación del 5/10 se cortó a mitad y, al relanzarlo, generó una segunda factura a 61 clientes.
2. La distribuidora SUR nos envió dos veces el mismo fichero de lecturas y se cargaron las dos, así que 44 clientes tienen el consumo de septiembre contado dos veces.
3. A los 44 clientes de TEMPO_FIJA se les aplicó la subida del 15/09 a todo el mes, en lugar de solo desde el 15.

**A quién afecta.** 97 contratos (94 clientes):

- 61 tienen factura duplicada; a **46 se les ha cobrado dos veces** (2.956,19 € en total).
- 74 tienen un importe incorrecto, con **1.971,13 € facturados de más** en total. Casi todo es por la recarga de SUR; lo del precio son 1–2 € por cliente.
- De las 7 reclamaciones, 6 corresponden a estos fallos. La del cliente 97 no: su factura es correcta. Tras tres meses de lecturas estimadas bajas, la lectura real recoge el consumo pendiente.

**Qué proponemos hacer:**

- Anular las 61 facturas sobrantes, conservando siempre la cobrada.
- Retirar las 44 lecturas repetidas.
- Cambiar el proceso para que un corte no deje facturas a medias, relanzarlo sea seguro y los cambios de precio se prorrateen.
- Impedir en base de datos una segunda factura activa por cliente y mes, y la recarga de un mismo fichero.

**Qué necesitamos que decidáis:**

1. **Devolución de los 46 cobros duplicados:** ¿devolución inmediata o compensación en la próxima factura?
2. **Las 74 facturas con importe incorrecto:** ¿factura rectificativa ahora o regularización en octubre? El importe exacto por cliente está en el script `13`. Conviene confirmar con Administración el procedimiento de rectificativas.
3. **Comunicación:** ¿escribimos de forma proactiva a los 94 clientes afectados o solo a los que han reclamado?
4. **Cliente 97:** explicarle la regularización. ¿Le ofrecemos fraccionar el pago?
5. **Distribuidora SUR:** pedirle explicación del doble envío.

---

## 7. Decisiones técnicas

| Decisión | Motivo |
|----------|--------|
| Anular (`estado = 'anulada'`) en vez de borrar facturas | Auditoría, reversibilidad y trazabilidad de los cobros a devolver (`fecha_pago` se conserva) |
| Conservar la factura pagada, no la de menor id | No anular un cobro dejando viva una factura pendiente |
| Mover lecturas a `lecturas_descartadas` en vez de borrarlas | `lecturas` no tiene estado; así se conserva el dato y se puede revertir |
| No reescribir importes de facturas emitidas | Requiere rectificativas: es decisión de negocio y de Administración. El script `13` deja la cifra preparada |
| Índice único **filtrado** en `facturas` | Un `UNIQUE` normal fallaría por las anuladas, que deben coexistir con la buena |
| `tipo` dentro de la clave única de `lecturas` | Una lectura real que regulariza una estimada del mismo periodo debe poder entrar |
| Mantener el cursor en el SP | El problema no era el rendimiento sino la falta de transacción e idempotencia; cambio mínimo y revisable |
