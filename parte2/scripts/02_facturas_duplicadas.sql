-- Detectar facturas duplicadas por contrato y periodo
SELECT contrato_id, periodo,
       COUNT(*)          AS num_facturas,
       SUM(total)        AS total_facturado,
       STRING_AGG(CAST(id AS VARCHAR), ', ') AS ids_facturas,
       STRING_AGG(estado, ', ')              AS estados
FROM   dbo.facturas
WHERE  periodo = '2026-09'
  AND  estado <> 'anulada'
GROUP  BY contrato_id, periodo
HAVING COUNT(*) > 1
ORDER  BY num_facturas DESC;