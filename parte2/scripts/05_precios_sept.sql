SELECT t.codigo, tp.precio_kwh, tp.termino_fijo_mes,
       tp.vigente_desde, tp.vigente_hasta
FROM   dbo.tarifas_precios tp
JOIN   dbo.tarifas t ON t.id = tp.tarifa_id
WHERE  tp.vigente_desde <= '2026-09-30'
  AND (tp.vigente_hasta IS NULL OR tp.vigente_hasta >= '2026-09-01')
ORDER  BY t.codigo, tp.vigente_desde;