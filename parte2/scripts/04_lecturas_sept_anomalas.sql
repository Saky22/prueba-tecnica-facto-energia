-- Lecturas duplicadas para septiembre
SELECT contrato_id, fecha_desde, fecha_hasta,
       COUNT(*) AS num_lecturas,
       SUM(consumo_kwh) AS consumo_total
FROM   dbo.lecturas
WHERE  fecha_hasta >= '2026-09-01' AND fecha_desde < '2026-10-01'
GROUP  BY contrato_id, fecha_desde, fecha_hasta
HAVING COUNT(*) > 1;

-- Consumo de septiembre vs agosto por contrato
SELECT l_sep.contrato_id,
       l_sep.consumo_kwh AS consumo_sep,
       l_ago.consumo_kwh AS consumo_ago,
       ROUND(l_sep.consumo_kwh / NULLIF(l_ago.consumo_kwh, 0), 1) AS ratio
FROM  (SELECT contrato_id, SUM(consumo_kwh) AS consumo_kwh
       FROM   dbo.lecturas
       WHERE  fecha_hasta >= '2026-09-01' AND fecha_desde < '2026-10-01'
       GROUP  BY contrato_id) l_sep
LEFT JOIN
      (SELECT contrato_id, SUM(consumo_kwh) AS consumo_kwh
       FROM   dbo.lecturas
       WHERE  fecha_hasta >= '2026-08-01' AND fecha_desde < '2026-09-01'
       GROUP  BY contrato_id) l_ago
ON l_sep.contrato_id = l_ago.contrato_id
ORDER BY ratio DESC;