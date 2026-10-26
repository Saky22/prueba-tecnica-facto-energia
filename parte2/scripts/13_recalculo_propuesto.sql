-- DIAGNÓSTICO / PROPUESTA: importe correcto de septiembre por contrato. SOLO LECTURA.
--
-- No modifica facturas: rectificar facturas ya emitidas o cobradas es decisión de negocio
-- (factura rectificativa, abono, devolución). Este script da la cifra de cada caso.
-- Funciona antes o después de los scripts 10 y 11:
--   - consumo correcto = lecturas del mes sin copias exactas;
--   - precio correcto  = prorrateado por días de vigencia (como el SP corregido);
--   - factura de referencia = la que conserva el script 10 (pagada si la hay, si no la de menor id).
SET NOCOUNT ON;

DECLARE @periodo CHAR(7) = '2026-09';
DECLARE @inicio  DATE    = '2026-09-01';
DECLARE @fin     DATE    = '2026-09-30';
DECLARE @dias    INT     = DATEDIFF(DAY, @inicio, @fin) + 1;

IF OBJECT_ID('tempdb..#recalculo') IS NOT NULL DROP TABLE #recalculo;

WITH lect AS (
    SELECT contrato_id, consumo_kwh,
           ROW_NUMBER() OVER (PARTITION BY contrato_id, fecha_desde, fecha_hasta, tipo, lectura_contador, consumo_kwh
                              ORDER BY id) AS rn
    FROM   dbo.lecturas
    WHERE  fecha_hasta BETWEEN @inicio AND @fin
),
consumo AS (
    SELECT contrato_id, SUM(consumo_kwh) AS consumo
    FROM   lect
    WHERE  rn = 1
    GROUP  BY contrato_id
),
precio AS (
    SELECT tp.tarifa_id,
           ROUND(SUM(tp.precio_kwh       * d.dias) / @dias, 6) AS precio_kwh,
           ROUND(SUM(tp.termino_fijo_mes * d.dias) / @dias, 2) AS fijo
    FROM   dbo.tarifas_precios tp
    CROSS APPLY (SELECT DATEDIFF(DAY, GREATEST(tp.vigente_desde, @inicio),
                                      LEAST(ISNULL(tp.vigente_hasta, @fin), @fin)) + 1 AS dias) d
    WHERE  tp.vigente_desde <= @fin
      AND (tp.vigente_hasta IS NULL OR tp.vigente_hasta >= @inicio)
    GROUP  BY tp.tarifa_id
),
facturas_mes AS (
    SELECT f.*,
           COUNT(*) OVER (PARTITION BY f.contrato_id) AS num_facturas_emitidas,
           ROW_NUMBER() OVER (PARTITION BY f.contrato_id
                              ORDER BY CASE WHEN f.estado = 'pagada' OR f.fecha_pago IS NOT NULL THEN 0 ELSE 1 END,
                                       CASE WHEN f.estado = 'anulada' THEN 1 ELSE 0 END, f.id) AS rn
    FROM   dbo.facturas f
    WHERE  f.periodo = @periodo
)
SELECT f.contrato_id, c.cliente_id, f.id AS factura_id, f.numero, f.estado,
       f.num_facturas_emitidas,
       f.consumo_kwh                                AS consumo_facturado,
       ISNULL(cs.consumo, 0)                        AS consumo_correcto,
       f.precio_kwh                                 AS precio_facturado,
       p.precio_kwh                                 AS precio_correcto,
       f.total                                      AS total_facturado,
       ROUND(ISNULL(cs.consumo, 0) * p.precio_kwh, 2) + p.fijo AS total_correcto
INTO   #recalculo
FROM   facturas_mes f
JOIN   dbo.contratos c  ON c.id = f.contrato_id
JOIN   precio p         ON p.tarifa_id = c.tarifa_id
LEFT JOIN consumo cs    ON cs.contrato_id = f.contrato_id
WHERE  f.rn = 1;

-- 1) Detalle por contrato afectado
SELECT *,
       total_facturado - total_correcto AS diferencia,
       CONCAT_WS(' + ',
           CASE WHEN num_facturas_emitidas > 1          THEN 'factura duplicada' END,
           CASE WHEN consumo_facturado <> consumo_correcto THEN 'consumo duplicado (recarga SUR)' END,
           CASE WHEN precio_facturado  <> precio_correcto  THEN 'precio sin prorratear' END) AS causa
FROM   #recalculo
WHERE  num_facturas_emitidas > 1
   OR  total_facturado <> total_correcto
ORDER  BY contrato_id;

-- 2) Resumen
SELECT 'Contratos con factura duplicada'         AS concepto, COUNT(*) AS contratos, NULL AS importe
FROM   #recalculo WHERE num_facturas_emitidas > 1
UNION ALL
SELECT 'Contratos con importe incorrecto', COUNT(*), SUM(total_facturado - total_correcto)
FROM   #recalculo WHERE total_facturado <> total_correcto
UNION ALL
SELECT '  - por consumo duplicado', COUNT(*), SUM(total_facturado - total_correcto)
FROM   #recalculo WHERE consumo_facturado <> consumo_correcto
UNION ALL
SELECT '  - solo por precio sin prorratear', COUNT(*), SUM(total_facturado - total_correcto)
FROM   #recalculo WHERE consumo_facturado = consumo_correcto AND precio_facturado <> precio_correcto
UNION ALL
SELECT 'Contratos afectados (cualquier causa)', COUNT(*), NULL
FROM   #recalculo WHERE num_facturas_emitidas > 1 OR total_facturado <> total_correcto;

-- 3) Las 7 reclamaciones, con su causa
SELECT r.id AS reclamacion, r.fecha, r.cliente_id, x.contrato_id, x.num_facturas_emitidas,
       x.consumo_facturado, x.consumo_correcto, x.total_facturado, x.total_correcto,
       CASE
           WHEN x.num_facturas_emitidas = 1 AND x.total_facturado = x.total_correcto
                THEN 'Sin error: revisar caso (ver regularizaciones)'
           ELSE CONCAT_WS(' + ',
                CASE WHEN x.num_facturas_emitidas > 1           THEN 'factura duplicada' END,
                CASE WHEN x.consumo_facturado <> x.consumo_correcto THEN 'consumo duplicado' END,
                CASE WHEN x.precio_facturado  <> x.precio_correcto  THEN 'precio sin prorratear' END)
       END AS causa
FROM   dbo.reclamaciones r
JOIN   #recalculo x ON x.cliente_id = r.cliente_id
ORDER  BY r.id;

-- 4) Subidas que NO son error: lectura real tras meses estimados (regularización).
--    Caso de la reclamación 7 (contrato 97): jun-ago estimadas (113/125/156 kWh), sept real 745 kWh.
SELECT l.contrato_id,
       SUM(CASE WHEN l.fecha_hasta <  @inicio THEN 1 ELSE 0 END) AS meses_estimados_previos,
       MAX(CASE WHEN l.fecha_hasta >= @inicio THEN l.consumo_kwh END) AS consumo_sept_real
FROM   dbo.lecturas l
WHERE  l.fecha_hasta BETWEEN DATEADD(MONTH, -3, @inicio) AND @fin
GROUP  BY l.contrato_id
HAVING SUM(CASE WHEN l.fecha_hasta <  @inicio AND l.tipo = 'estimada' THEN 1 ELSE 0 END) >= 2
   AND SUM(CASE WHEN l.fecha_hasta <  @inicio AND l.tipo = 'real'     THEN 1 ELSE 0 END) = 0
   AND SUM(CASE WHEN l.fecha_hasta >= @inicio AND l.tipo = 'real'     THEN 1 ELSE 0 END) >= 1;
