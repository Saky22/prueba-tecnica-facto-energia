-- CORRECCIÓN: retirar las lecturas que son copia exacta de otra ya cargada
-- (las 44 de SUR_20261003_01.csv, recarga de SUR_20261002_01.csv).
--
-- lecturas no tiene columna de estado, así que las filas se MUEVEN a dbo.lecturas_descartadas
-- (misma estructura + motivo) en una sola transacción: nada se pierde y se puede revertir.
-- Solo se tocan copias exactas (mismo contrato, periodo, tipo, contador y consumo); se conserva la de menor id.
-- Idempotente.
SET NOCOUNT ON;
SET XACT_ABORT ON;
SET QUOTED_IDENTIFIER ON;
SET ANSI_NULLS ON;

IF OBJECT_ID('dbo.lecturas_descartadas') IS NULL
    CREATE TABLE dbo.lecturas_descartadas (
        id               INT            NOT NULL PRIMARY KEY,
        contrato_id      INT            NOT NULL,
        fecha_desde      DATE           NOT NULL,
        fecha_hasta      DATE           NOT NULL,
        lectura_contador INT            NOT NULL,
        consumo_kwh      DECIMAL(12,3)  NOT NULL,
        tipo             VARCHAR(10)    NOT NULL,
        fichero_origen   VARCHAR(60)    NOT NULL,
        fecha_carga      DATETIME2(0)   NOT NULL,
        motivo           NVARCHAR(200)  NOT NULL,
        fecha_descarte   DATETIME2(0)   NOT NULL
    );

DECLARE @movidas INT;

BEGIN TRANSACTION;

DELETE d
OUTPUT deleted.id, deleted.contrato_id, deleted.fecha_desde, deleted.fecha_hasta,
       deleted.lectura_contador, deleted.consumo_kwh, deleted.tipo, deleted.fichero_origen,
       deleted.fecha_carga,
       N'Copia exacta de una lectura ya cargada (recarga de fichero)', SYSDATETIME()
INTO   dbo.lecturas_descartadas (id, contrato_id, fecha_desde, fecha_hasta, lectura_contador,
                                 consumo_kwh, tipo, fichero_origen, fecha_carga, motivo, fecha_descarte)
FROM   dbo.lecturas d
WHERE  EXISTS (SELECT 1
               FROM   dbo.lecturas o
               WHERE  o.contrato_id      = d.contrato_id
                 AND  o.fecha_desde      = d.fecha_desde
                 AND  o.fecha_hasta      = d.fecha_hasta
                 AND  o.tipo             = d.tipo
                 AND  o.lectura_contador = d.lectura_contador
                 AND  o.consumo_kwh      = d.consumo_kwh
                 AND  o.id < d.id);

SET @movidas = @@ROWCOUNT;

COMMIT TRANSACTION;

PRINT CONCAT('Lecturas movidas a lecturas_descartadas: ', @movidas);   -- 44 la primera vez, 0 si se repite

SELECT fichero_origen, COUNT(*) AS lecturas, COUNT(DISTINCT contrato_id) AS contratos
FROM   dbo.lecturas_descartadas
GROUP  BY fichero_origen;

-- Revertir (si hiciera falta):
-- SET IDENTITY_INSERT dbo.lecturas ON;
-- INSERT INTO dbo.lecturas (id, contrato_id, fecha_desde, fecha_hasta, lectura_contador, consumo_kwh, tipo, fichero_origen, fecha_carga)
-- SELECT id, contrato_id, fecha_desde, fecha_hasta, lectura_contador, consumo_kwh, tipo, fichero_origen, fecha_carga
-- FROM dbo.lecturas_descartadas;
-- SET IDENTITY_INSERT dbo.lecturas OFF;
