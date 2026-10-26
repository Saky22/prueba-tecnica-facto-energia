-- PREVENCIÓN: nueva versión de dbo.sp_facturar_periodo.
--
-- Cambios respecto al original (mismo cursor y misma lógica de consumo; nada más):
--   1. Todo o nada: transacción + XACT_ABORT. Si Jenkins corta la llamada por timeout o hay un
--      error, se deshacen TODAS las facturas de esa ejecución (el 5/10 quedaron 61 a medias).
--   2. Idempotente: si el contrato ya tiene factura activa del periodo, se salta. Relanzar es seguro.
--   3. Precio prorrateado por días cuando la tarifa cambia dentro del mes (antes tomaba
--      siempre el precio con vigente_hasta IS NULL, también al refacturar meses pasados).
--   4. Errores registrados: log_procesos pasa a 'ERROR' con el mensaje.
--      (Un corte del cliente aborta el lote sin pasar por CATCH: la transacción se deshace igual,
--      pero el log puede quedar en EN_CURSO. No genera facturas, solo una fila de log huérfana.)
--
-- Ejecutar con sqlcmd -I (QUOTED_IDENTIFIER ON): el SP inserta en una tabla con índice filtrado (script 20).
SET QUOTED_IDENTIFIER ON;
SET ANSI_NULLS ON;
GO

CREATE OR ALTER PROCEDURE dbo.sp_facturar_periodo
    @periodo CHAR(7) -- 'YYYY-MM'
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @inicio       DATE = CONVERT(DATE, @periodo + '-01', 23);
    DECLARE @fin          DATE = EOMONTH(@inicio);
    DECLARE @dias_periodo INT  = DATEDIFF(DAY, @inicio, @fin) + 1;
    DECLARE @ejecucion_id INT;
    DECLARE @procesados   INT = 0;
    DECLARE @omitidos     INT = 0;
    DECLARE @contrato_id  INT;
    DECLARE @tarifa_id    INT;
    DECLARE @consumo      DECIMAL(12,3);
    DECLARE @precio       DECIMAL(10,6);
    DECLARE @fijo         DECIMAL(10,2);
    DECLARE @dias_precio  INT;
    DECLARE @importe      DECIMAL(12,2);
    DECLARE @numero       INT;
    DECLARE @msg          NVARCHAR(400);

    -- Fuera de la transacción para que quede rastro aunque se deshaga todo lo demás
    INSERT INTO dbo.log_procesos (proceso, parametros, inicio, estado, usuario)
    VALUES ('FACTURACION_MENSUAL', @periodo, SYSDATETIME(), 'EN_CURSO', SUSER_SNAME());
    SET @ejecucion_id = SCOPE_IDENTITY();

    BEGIN TRY
        BEGIN TRANSACTION;

        DECLARE contratos_cur CURSOR LOCAL FAST_FORWARD FOR
            SELECT id, tarifa_id
            FROM dbo.contratos
            WHERE fecha_alta <= @fin
              AND (fecha_baja IS NULL OR fecha_baja >= @inicio)
            ORDER BY id;

        OPEN contratos_cur;
        FETCH NEXT FROM contratos_cur INTO @contrato_id, @tarifa_id;

        WHILE @@FETCH_STATUS = 0
        BEGIN
            -- (2) Guarda de idempotencia
            IF EXISTS (SELECT 1
                       FROM dbo.facturas
                       WHERE contrato_id = @contrato_id
                         AND periodo     = @periodo
                         AND estado     <> 'anulada')
            BEGIN
                SET @omitidos += 1;
                FETCH NEXT FROM contratos_cur INTO @contrato_id, @tarifa_id;
                CONTINUE;
            END

            -- Consumo del periodo (sin cambios)
            SELECT @consumo = ISNULL(SUM(consumo_kwh), 0)
            FROM dbo.lecturas
            WHERE contrato_id = @contrato_id
              AND fecha_hasta BETWEEN @inicio AND @fin;

            -- (3) Precio y término fijo ponderados por los días de vigencia de cada precio en el periodo
            SELECT @precio      = ROUND(SUM(tp.precio_kwh       * d.dias) / @dias_periodo, 6),
                   @fijo        = ROUND(SUM(tp.termino_fijo_mes * d.dias) / @dias_periodo, 2),
                   @dias_precio = SUM(d.dias)
            FROM dbo.tarifas_precios tp
            CROSS APPLY (SELECT DATEDIFF(DAY,
                                         GREATEST(tp.vigente_desde, @inicio),
                                         LEAST(ISNULL(tp.vigente_hasta, @fin), @fin)) + 1 AS dias) d
            WHERE tp.tarifa_id = @tarifa_id
              AND tp.vigente_desde <= @fin
              AND (tp.vigente_hasta IS NULL OR tp.vigente_hasta >= @inicio);

            IF @dias_precio IS NULL OR @dias_precio <> @dias_periodo
            BEGIN
                SET @msg = CONCAT(N'La tarifa ', @tarifa_id, N' no tiene precio para todos los días de ', @periodo,
                                  N' (contrato ', @contrato_id, N').');
                THROW 50020, @msg, 1;
            END

            SET @importe = ROUND(@consumo * @precio, 2);
            SET @numero  = NEXT VALUE FOR dbo.seq_factura;

            INSERT INTO dbo.facturas (numero, contrato_id, periodo, fecha_desde, fecha_hasta, consumo_kwh,
                                      precio_kwh, importe_energia, importe_fijo, total, estado,
                                      ejecucion_id, fecha_creacion)
            VALUES (CONCAT('F', LEFT(@periodo, 4), '-', FORMAT(@numero, '000000')),
                    @contrato_id, @periodo, @inicio, @fin, @consumo,
                    @precio, @importe, @fijo, @importe + @fijo, 'borrador',
                    @ejecucion_id, SYSDATETIME());

            SET @procesados += 1;
            FETCH NEXT FROM contratos_cur INTO @contrato_id, @tarifa_id;
        END

        CLOSE contratos_cur;
        DEALLOCATE contratos_cur;

        COMMIT TRANSACTION;

        UPDATE dbo.log_procesos
        SET fin              = SYSDATETIME(),
            estado           = 'OK',
            filas_procesadas = @procesados,
            mensaje          = CASE WHEN @omitidos > 0
                                    THEN CONCAT(N'Omitidos ', @omitidos, N' contratos ya facturados')
                               END
        WHERE id = @ejecucion_id;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0
            ROLLBACK TRANSACTION;

        UPDATE dbo.log_procesos
        SET fin     = SYSDATETIME(),
            estado  = 'ERROR',
            mensaje = LEFT(ERROR_MESSAGE(), 400)
        WHERE id = @ejecucion_id;

        THROW;
    END CATCH
END
GO
