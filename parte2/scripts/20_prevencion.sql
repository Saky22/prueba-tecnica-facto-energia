-- PREVENCIÓN en base de datos. Ejecutar DESPUÉS de 10 y 11 (si quedan duplicados, se detiene).
-- Idempotente.
SET NOCOUNT ON;
SET QUOTED_IDENTIFIER ON;   -- obligatorio para crear índices filtrados
SET ANSI_NULLS ON;
GO

-- 1) Una sola factura activa por contrato y periodo.
--    Índice único FILTRADO: las anuladas pueden coexistir con la buena (un UNIQUE normal
--    fallaría precisamente por las 61 facturas anuladas por el script 10).
IF EXISTS (SELECT 1 FROM dbo.facturas WHERE estado <> 'anulada'
           GROUP BY contrato_id, periodo HAVING COUNT(*) > 1)
BEGIN
    ;THROW 50010, N'Hay facturas activas duplicadas: ejecuta antes 10_correccion_anular_duplicados.sql', 1;
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes
               WHERE name = 'uq_facturas_contrato_periodo_activa' AND object_id = OBJECT_ID('dbo.facturas'))
    CREATE UNIQUE INDEX uq_facturas_contrato_periodo_activa
        ON dbo.facturas (contrato_id, periodo)
        WHERE estado <> 'anulada';
GO

-- 2) Una sola lectura por contrato, periodo y tipo.
--    Si un fichero se vuelve a cargar, la carga falla en vez de duplicar consumos.
--    (Incluye tipo: una real que regulariza una estimada del mismo periodo sigue entrando.)
IF EXISTS (SELECT 1 FROM dbo.lecturas
           GROUP BY contrato_id, fecha_desde, fecha_hasta, tipo HAVING COUNT(*) > 1)
BEGIN
    ;THROW 50011, N'Hay lecturas duplicadas: ejecuta antes 11_correccion_lecturas_duplicadas.sql', 1;
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes
               WHERE name = 'uq_lecturas_contrato_periodo_tipo' AND object_id = OBJECT_ID('dbo.lecturas'))
    CREATE UNIQUE INDEX uq_lecturas_contrato_periodo_tipo
        ON dbo.lecturas (contrato_id, fecha_desde, fecha_hasta, tipo);
GO

-- 3) Comprobación post-facturación para añadir al job de Jenkins (debe devolver 0 filas).
--    Cambiar el periodo por el facturado.
DECLARE @periodo CHAR(7) = '2026-09';

SELECT 'factura duplicada' AS problema, contrato_id, COUNT(*) AS n
FROM   dbo.facturas
WHERE  periodo = @periodo AND estado <> 'anulada'
GROUP  BY contrato_id HAVING COUNT(*) > 1
UNION ALL
SELECT 'contrato vigente sin factura', c.id, 0
FROM   dbo.contratos c
WHERE  c.fecha_alta <= EOMONTH(CONVERT(DATE, @periodo + '-01', 23))
  AND (c.fecha_baja IS NULL OR c.fecha_baja >= CONVERT(DATE, @periodo + '-01', 23))
  AND NOT EXISTS (SELECT 1 FROM dbo.facturas f
                  WHERE f.contrato_id = c.id AND f.periodo = @periodo AND f.estado <> 'anulada')
UNION ALL
SELECT 'ejecucion sin terminar', id, 0
FROM   dbo.log_procesos
WHERE  proceso = 'FACTURACION_MENSUAL' AND parametros = @periodo AND estado = 'EN_CURSO';
-- Para septiembre devuelve la ejecución 30 (la que cortó Jenkins): es el histórico de la incidencia.
