EXEC sp_helptext 'dbo.sp_facturar_periodo';
-- O bien:
SELECT definition
FROM   sys.sql_modules
WHERE  object_id = OBJECT_ID('dbo.sp_facturar_periodo');