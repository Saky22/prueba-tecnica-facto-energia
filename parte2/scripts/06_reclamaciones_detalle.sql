SELECT r.id, r.fecha, r.canal, c.nombre, c.email,
       co.cups, f.numero, f.total, f.estado, f.periodo,
       r.texto
FROM   dbo.reclamaciones r
JOIN   dbo.clientes c    ON c.id = r.cliente_id
JOIN   dbo.contratos co  ON co.cliente_id = c.id
LEFT JOIN dbo.facturas f ON f.contrato_id = co.id AND f.periodo = '2026-09'
ORDER  BY r.fecha;