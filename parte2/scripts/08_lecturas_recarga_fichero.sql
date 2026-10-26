-- DIAGNÓSTICO: origen de las lecturas duplicadas de septiembre.
-- Solo lectura. Resultado esperado: SUR_20261003_01.csv repite las 44 filas de SUR_20261002_01.csv.
SET NOCOUNT ON;

-- 1) Cargas de octubre: SUR aparece dos veces (2 y 3 de octubre), NORTE y ESTE una.
SELECT id, proceso, parametros AS fichero, inicio, estado, filas_procesadas, usuario
FROM   dbo.log_procesos
WHERE  proceso = 'CARGA_LECTURAS'
  AND  inicio >= '2026-10-01'
ORDER  BY inicio;

-- 2) Filas idénticas (mismo contrato, periodo, tipo, contador y consumo) cargadas desde dos ficheros.
SELECT d.fichero_origen AS fichero_repetido,
       o.fichero_origen AS fichero_original,
       COUNT(*)                      AS filas_identicas,
       COUNT(DISTINCT d.contrato_id) AS contratos,
       SUM(d.consumo_kwh)            AS kwh_contados_dos_veces
FROM   dbo.lecturas d
JOIN   dbo.lecturas o
       ON  o.contrato_id      = d.contrato_id
       AND o.fecha_desde      = d.fecha_desde
       AND o.fecha_hasta      = d.fecha_hasta
       AND o.tipo             = d.tipo
       AND o.lectura_contador = d.lectura_contador
       AND o.consumo_kwh      = d.consumo_kwh
       AND o.id < d.id
GROUP  BY d.fichero_origen, o.fichero_origen;

-- 3) El SP suma todas las lecturas del mes (SUM(consumo_kwh) ... fecha_hasta BETWEEN ...),
--    así que esos 44 contratos se facturaron con el doble de consumo.
