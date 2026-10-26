-- Comparar importe septiembre con media de meses anteriores por contrato
WITH historico AS (
    SELECT contrato_id,
           AVG(total) AS media_historica,
           STDEV(total) AS desv_historica
    FROM   dbo.facturas
    WHERE  periodo BETWEEN '2026-04' AND '2026-08'
      AND  estado <> 'anulada'
    GROUP  BY contrato_id
),
sep AS (
    SELECT contrato_id, id, numero, total, consumo_kwh, precio_kwh
    FROM   dbo.facturas
    WHERE  periodo = '2026-09'
      AND  estado <> 'anulada'
)
SELECT s.contrato_id, s.id, s.numero, s.total,
       h.media_historica,
       ROUND(s.total / NULLIF(h.media_historica, 0), 1) AS ratio_vs_media,
       s.consumo_kwh, s.precio_kwh
FROM   sep s
JOIN   historico h ON h.contrato_id = s.contrato_id
WHERE  s.total > h.media_historica * 2   -- más del doble de la media
ORDER  BY ratio_vs_media DESC;