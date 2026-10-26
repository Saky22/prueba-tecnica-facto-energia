-- Ver todas las ejecuciones del proceso de facturación
SELECT id, proceso, parametros, inicio, fin, estado,
       filas_procesadas, usuario, mensaje,
       DATEDIFF(SECOND, inicio, ISNULL(fin, GETDATE())) AS segundos
FROM   dbo.log_procesos
WHERE  proceso = 'facturacion_mensual'
ORDER  BY inicio DESC;