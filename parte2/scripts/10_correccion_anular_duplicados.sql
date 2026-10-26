-- CORRECCIÓN: anular la factura sobrante de cada contrato con dos facturas activas en 2026-09.
--
-- Criterio: se conserva la PAGADA si la hay; si no, la de menor id. Así nunca se anula una
-- factura cobrada dejando viva una pendiente (pasa en los contratos 59 y 60).
-- No se borra nada: estado = 'anulada' (auditable y reversible). fecha_pago se mantiene,
-- de modo que "anulada con fecha_pago" = cobro duplicado que hay que devolver.
-- Idempotente: una segunda ejecución no cambia nada.
SET NOCOUNT ON;
SET XACT_ABORT ON;
SET QUOTED_IDENTIFIER ON;
SET ANSI_NULLS ON;

DECLARE @anuladas INT;

-- 1) Vista previa: qué se anula y qué requiere devolución
WITH ranking AS (
    SELECT id, numero, contrato_id, total, estado, fecha_pago,
           ROW_NUMBER() OVER (PARTITION BY contrato_id, periodo
                              ORDER BY CASE WHEN estado = 'pagada' THEN 0 ELSE 1 END, id) AS rn
    FROM   dbo.facturas
    WHERE  periodo = '2026-09'
      AND  estado <> 'anulada'
)
SELECT id, numero, contrato_id, total, estado AS estado_actual,
       CASE WHEN estado = 'pagada' THEN 'SI' ELSE 'no' END AS requiere_devolucion
FROM   ranking
WHERE  rn > 1
ORDER  BY contrato_id;

-- 2) Anulación
BEGIN TRANSACTION;

WITH ranking AS (
    SELECT estado,
           ROW_NUMBER() OVER (PARTITION BY contrato_id, periodo
                              ORDER BY CASE WHEN estado = 'pagada' THEN 0 ELSE 1 END, id) AS rn
    FROM   dbo.facturas
    WHERE  periodo = '2026-09'
      AND  estado <> 'anulada'
)
UPDATE ranking
SET    estado = 'anulada'
WHERE  rn > 1;

SET @anuladas = @@ROWCOUNT;

-- Comprobación antes de confirmar: ningún contrato puede seguir con más de una factura activa
IF EXISTS (SELECT 1 FROM dbo.facturas
           WHERE periodo = '2026-09' AND estado <> 'anulada'
           GROUP BY contrato_id HAVING COUNT(*) > 1)
BEGIN
    ROLLBACK TRANSACTION;
    THROW 50001, N'Siguen quedando facturas duplicadas: se deshace la corrección.', 1;
END

COMMIT TRANSACTION;

PRINT CONCAT('Facturas anuladas: ', @anuladas);   -- 61 la primera vez, 0 si se repite

-- 3) Resumen para Facturación / Tesorería
SELECT CASE WHEN fecha_pago IS NOT NULL THEN 'Cobrada: devolver' ELSE 'No cobrada: solo anular' END AS accion,
       COUNT(*)   AS facturas,
       SUM(total) AS importe
FROM   dbo.facturas
WHERE  periodo = '2026-09'
  AND  estado  = 'anulada'
GROUP  BY CASE WHEN fecha_pago IS NOT NULL THEN 'Cobrada: devolver' ELSE 'No cobrada: solo anular' END;
