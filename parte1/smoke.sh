#!/usr/bin/env bash
# Pruebas de humo de la API contra el entorno levantado (docker compose up -d en parte1/).
# Uso: ./smoke.sh [URL]       URL por defecto: http://localhost:8000/api/lecturas
# Se puede ejecutar varias veces: las altas aceptan 201 (primera vez) o 200 (reintento).
set -u
cd "$(dirname "$0")" || exit 1

URL="${1:-http://localhost:8000/api/lecturas}"
BODY=$(mktemp)
trap 'rm -f "$BODY"' EXIT
ok=0
ko=0

post() {
    curl -s -o "$BODY" -w '%{http_code}' -X POST "$URL" -H 'Content-Type: application/json' -d "$1"
}

# check <descripción> <códigos esperados (regex)> <texto esperado en la respuesta> <json>
check() {
    local code
    code=$(post "$4")
    if [[ "$code" =~ ^($2)$ ]] && grep -q -- "$3" "$BODY"; then
        printf 'OK    %-55s %s\n' "$1" "$code"
        ok=$((ok + 1))
    else
        printf 'FALLO %-55s %s  %s\n' "$1" "$code" "$(head -c 200 "$BODY")"
        ko=$((ko + 1))
    fi
}

lectura() {  # cups fecha kwh tipo
    printf '{"cups":"%s","fecha_lectura":"%s","lectura_kwh":%s,"tipo":"%s"}' "$1" "$2" "$3" "$4"
}

echo "Esperando a la API en $URL ..."
for _ in $(seq 1 60); do
    [ "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$URL" -d '{}')" = "400" ] && break
    sleep 2
done

CUPS=ES0904998698797309NB   # contrato 1, activo desde 2024-02-01

check "Alta de lectura nueva"                    "201|200" '"id"'                 "$(lectura $CUPS 2026-10-15 11000 real)"
check "Reintento identico -> duplicado"          "200"     '"duplicado":true'     "$(lectura $CUPS 2026-10-15 11000 real)"
check "Misma fecha y tipo, otro kWh -> 409"      "409"     'LECTURA_CONFLICTIVA'  "$(lectura $CUPS 2026-10-15 11001 real)"
check "Estimada en la misma fecha -> se acepta"  "201|200" '"tipo":"estimada"'    "$(lectura $CUPS 2026-10-15 10990 estimada)"
check "CUPS con dos contratos -> contrato 24"    "201|200" '"contrato_id":24'     "$(lectura ES0904688281126584QG 2026-08-15 25700 real)"
check "Contrato de baja -> 422"                  "422"     'CONTRATO_BAJA'        "$(lectura ES0904229486628360ZC 2026-06-01 11324 real)"
check "CUPS inexistente -> 422"                  "422"     'CUPS_NO_ENCONTRADO'   "$(lectura ES0000000000000000XX 2026-06-01 100 real)"
check "Fecha anterior al alta -> 422"            "422"     'FECHA_FUERA_VIGENCIA' "$(lectura $CUPS 2023-01-01 100 real)"
check "Fecha futura -> 422"                      "422"     'FECHA_FUTURA'         "$(lectura $CUPS 2030-01-01 100 real)"
check "Campos invalidos -> 400"                  "400"     'VALIDACION'           '{"cups":"MALO","fecha_lectura":"x","lectura_kwh":-1,"tipo":"otro"}'
check "JSON invalido -> 400"                     "400"     'VALIDACION'           'no-es-json'

# Concurrencia: 5 envíos simultáneos de la misma lectura nueva deben dejar una sola fila.
# (compose arranca el servidor con PHP_CLI_SERVER_WORKERS=4 para que las peticiones se atiendan en paralelo)
CONC=$(lectura $CUPS 2026-10-20 11050 real)
for _ in 1 2 3 4 5; do
    curl -s -o /dev/null -X POST "$URL" -H 'Content-Type: application/json' -d "$CONC" &
done
wait
filas=$(docker compose exec -T db /opt/mssql-tools18/bin/sqlcmd -S localhost -U sa -P 'Prueba.Tecnica.2026' \
    -C -h -1 -W -d lecturas -Q "SET NOCOUNT ON; SELECT COUNT(*) FROM dbo.lecturas WHERE contrato_id = 1 AND fecha_lectura = '2026-10-20' AND tipo = 'real';" \
    2>/dev/null | tr -d '[:space:]')
if [ "$filas" = "1" ]; then
    printf 'OK    %-55s %s\n' "5 envios simultaneos -> 1 fila" "filas=$filas"
    ok=$((ok + 1))
else
    printf 'FALLO %-55s %s\n' "5 envios simultaneos -> 1 fila" "filas=${filas:-?}"
    ko=$((ko + 1))
fi

echo
echo "Resultado: $ok OK, $ko fallos"
[ "$ko" -eq 0 ]
