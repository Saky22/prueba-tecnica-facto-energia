#!/usr/bin/env bash
# Ejecuta la corrección y la prevención en orden y termina con la comprobación post-facturación
# (la misma que iría en Jenkins). Requiere: docker compose up -d en parte2/.
#
# Uso: ./run.sh                 corrección (10, 11), SP (12), propuesta (13) y prevención (20)
#      ./run.sh --diagnostico   ejecuta antes los scripts de diagnóstico 01-08
set -euo pipefail
cd "$(dirname "$0")"

sql() {
    docker compose exec -T db /opt/mssql-tools18/bin/sqlcmd -S localhost -U sa -P 'Prueba.Tecnica.2026' \
        -C -I -b -d facturacion "$@"
}
valor() {  # devuelve el resultado de una consulta escalar
    sql -h -1 -W -Q "SET NOCOUNT ON; $1" | tr -d '[:space:]'
}

echo "Esperando a que termine la carga de la base de datos..."
for _ in $(seq 1 60); do
    docker compose logs db-init 2>/dev/null | grep -q "cargada\|ya existe" && break
    sleep 3
done

if [ "${1:-}" = "--diagnostico" ]; then
    for f in scripts/0*.sql; do
        echo; echo "=== $f"
        sql < "$f"
    done
fi

for f in scripts/10_*.sql scripts/11_*.sql scripts/12_*.sql scripts/13_*.sql scripts/20_*.sql; do
    echo; echo "=== $f"
    sql < "$f"
done

echo; echo "=== Relanzar la facturación de septiembre (debe ser inofensivo)"
antes=$(valor "SELECT COUNT(*) FROM dbo.facturas WHERE periodo = '2026-09'")
sql -Q "EXEC dbo.sp_facturar_periodo @periodo = '2026-09';"
despues=$(valor "SELECT COUNT(*) FROM dbo.facturas WHERE periodo = '2026-09'")
echo "Facturas de 2026-09: antes $antes, después $despues"

echo; echo "=== Comprobación post-facturación"
duplicadas=$(valor "SELECT COUNT(*) FROM (SELECT contrato_id FROM dbo.facturas WHERE periodo = '2026-09' AND estado <> 'anulada' GROUP BY contrato_id HAVING COUNT(*) > 1) d")
sin_factura=$(valor "SELECT COUNT(*) FROM dbo.contratos c WHERE c.fecha_alta <= '2026-09-30' AND (c.fecha_baja IS NULL OR c.fecha_baja >= '2026-09-01') AND NOT EXISTS (SELECT 1 FROM dbo.facturas f WHERE f.contrato_id = c.id AND f.periodo = '2026-09' AND f.estado <> 'anulada')")
lecturas_dup=$(valor "SELECT COUNT(*) FROM (SELECT 1 AS x FROM dbo.lecturas GROUP BY contrato_id, fecha_desde, fecha_hasta, tipo HAVING COUNT(*) > 1) d")
echo "Contratos con factura duplicada: $duplicadas"
echo "Contratos vigentes sin factura:  $sin_factura"
echo "Lecturas duplicadas:             $lecturas_dup"

if [ "$antes" = "$despues" ] && [ "$duplicadas" = "0" ] && [ "$sin_factura" = "0" ] && [ "$lecturas_dup" = "0" ]; then
    echo; echo "OK: relanzar no crea facturas y no quedan duplicados."
else
    echo; echo "FALLO: revisar la salida anterior." >&2
    exit 1
fi
