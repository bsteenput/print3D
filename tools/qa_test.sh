#!/bin/bash
# ============================================================
#  QA regression test — API Print3D
#  Usage :
#    ./tools/qa_test.sh
#
#  Prérequis :
#    - docker compose up -d (containers php + db actifs)
#    - Le mot de passe de l'admin QA doit être connu (voir
#      QA_ADMIN_EMAIL / QA_ADMIN_PASSWORD ci-dessous), typiquement
#      remis à zéro juste avant via :
#        docker exec print3d-php-1 php docker/reset_password.php \
#          bertrand@example.com <mdp_temporaire>
#
#  Le script crée ses propres données de test (client, printer,
#  filament, jobs...) et les supprime à la fin (y compris en cas
#  d'échec). Il ne touche à rien d'autre dans la base.
#
#  Code de sortie : 0 si tout passe, 1 si au moins un test échoue.
# ============================================================
set -uo pipefail

QA_ADMIN_EMAIL="${QA_ADMIN_EMAIL:-bertrand@example.com}"
QA_ADMIN_PASSWORD="${QA_ADMIN_PASSWORD:?Variable QA_ADMIN_PASSWORD requise (mot de passe admin local connu)}"

RUN_ID=$(date +%s)
PORT="${QA_PORT:-$(docker compose port php 80 2>/dev/null | cut -d: -f2)}"
if [ -z "$PORT" ]; then
  echo "Impossible de déterminer le port du service php. Le container tourne-t-il ? (docker compose up -d)"
  exit 1
fi
BASE="http://localhost:$PORT/api"

PASS=0
FAIL=0
FAILED_TESTS=()

# ── IDs créés pendant le run, nettoyés à la sortie (même en cas d'échec) ──
CLEANUP_JOB_IDS=()
CLEANUP_CLIENT_IDS=()
CLEANUP_PRINTER_IDS=()
CLEANUP_FILAMENT_IDS=()

cleanup() {
  for id in "${CLEANUP_JOB_IDS[@]:-}"; do
    [ -n "$id" ] && curl -s "$BASE/jobs/$id" -X DELETE -H "Authorization: Bearer $ADMIN_TOKEN" >/dev/null
  done
  for id in "${CLEANUP_FILAMENT_IDS[@]:-}"; do
    [ -n "$id" ] && curl -s "$BASE/filaments/$id" -X DELETE -H "Authorization: Bearer $ADMIN_TOKEN" >/dev/null
  done
  for id in "${CLEANUP_PRINTER_IDS[@]:-}"; do
    [ -n "$id" ] && curl -s "$BASE/printers/$id" -X DELETE -H "Authorization: Bearer $ADMIN_TOKEN" >/dev/null
  done
  for id in "${CLEANUP_CLIENT_IDS[@]:-}"; do
    [ -n "$id" ] && curl -s "$BASE/clients/$id" -X DELETE -H "Authorization: Bearer $ADMIN_TOKEN" >/dev/null
  done
}
trap cleanup EXIT

jget() { python3 -c "import sys,json
try:
    d=json.load(sys.stdin)
    print(d$1)
except Exception:
    print('')" 2>/dev/null; }

check() {
  local desc="$1" actual="$2" expected="$3"
  if [ "$actual" == "$expected" ]; then
    PASS=$((PASS+1)); echo "PASS: $desc"
  else
    FAIL=$((FAIL+1)); FAILED_TESTS+=("$desc (got: [$actual], expected: [$expected])")
    echo "FAIL: $desc -- got [$actual] expected [$expected]"
  fi
}

echo "=== AUTH ==="
LOGIN=$(curl -s "$BASE/auth/login" -X POST -H "Content-Type: application/json" -d "{\"email\":\"$QA_ADMIN_EMAIL\",\"password\":\"$QA_ADMIN_PASSWORD\"}")
ADMIN_TOKEN=$(echo "$LOGIN" | jget "['data']['token']")
check "admin login ok" "$(echo "$LOGIN" | jget "['ok']")" "True"
[ -z "$ADMIN_TOKEN" ] && { echo "Impossible d'obtenir un token admin, arrêt."; exit 1; }

BADCODE=$(curl -s -o /dev/null -w "%{http_code}" "$BASE/auth/login" -X POST -H "Content-Type: application/json" -d "{\"email\":\"$QA_ADMIN_EMAIL\",\"password\":\"wrong\"}")
check "bad login rejected (401)" "$BADCODE" "401"

ME=$(curl -s "$BASE/auth/me" -H "Authorization: Bearer $ADMIN_TOKEN")
check "auth/me returns admin role" "$(echo "$ME" | jget "['data']['role']")" "admin"

echo "=== CLIENTS ==="
CJSON=$(curl -s "$BASE/auth/register" -X POST -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" \
  -d "{\"name\":\"QA Client\",\"email\":\"qa_client_$RUN_ID@example.com\",\"password\":\"qaClientPass1\",\"role\":\"client\"}")
CLIENT_ID=$(echo "$CJSON" | jget "['data']['id']")
CLEANUP_CLIENT_IDS+=("$CLIENT_ID")
check "client created" "$(echo "$CJSON" | jget "['ok']")" "True"

CLOGIN=$(curl -s "$BASE/auth/login" -X POST -H "Content-Type: application/json" -d "{\"email\":\"qa_client_$RUN_ID@example.com\",\"password\":\"qaClientPass1\"}")
CLIENT_TOKEN=$(echo "$CLOGIN" | jget "['data']['token']")
check "client login ok" "$(echo "$CLOGIN" | jget "['ok']")" "True"

CLIST=$(curl -s "$BASE/clients" -H "Authorization: Bearer $ADMIN_TOKEN")
check "clients list contains new client" "$(echo "$CLIST" | python3 -c "import sys,json; d=json.load(sys.stdin)['data']; print(any(c['id']==$CLIENT_ID for c in d))" 2>/dev/null)" "True"

echo "=== PRINTERS ==="
PJSON=$(curl -s "$BASE/printers" -X POST -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" -d '{"name":"QA Printer","active":1}')
PRINTER_ID=$(echo "$PJSON" | jget "['data']['id']")
CLEANUP_PRINTER_IDS+=("$PRINTER_ID")
check "printer created" "$(echo "$PJSON" | jget "['ok']")" "True"

echo "=== FILAMENTS ==="
FJSON=$(curl -s "$BASE/filaments" -X POST -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" \
  -d '{"material":"PLA","color":"QA-Color","color_hex":"#123456","price_per_kg":20,"stock_grams":1000,"active":1}')
FIL_ID=$(echo "$FJSON" | jget "['data']['id']")
CLEANUP_FILAMENT_IDS+=("$FIL_ID")
check "filament created" "$(echo "$FJSON" | jget "['ok']")" "True"

echo "=== SETTINGS ==="
check "settings GET ok" "$(curl -s "$BASE/settings" -H "Authorization: Bearer $ADMIN_TOKEN" | jget "['ok']")" "True"
ORIG_RATE=$(curl -s "$BASE/settings" -H "Authorization: Bearer $ADMIN_TOKEN" | jget "['data']['hourly_rate']")
SPOST=$(curl -s "$BASE/settings" -X POST -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" -d '{"hourly_rate":"0.90"}')
check "settings POST ok" "$(echo "$SPOST" | jget "['ok']")" "True"
curl -s "$BASE/settings" -X POST -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" -d "{\"hourly_rate\":\"${ORIG_RATE:-0.80}\"}" >/dev/null

echo "=== JOBS — cycle de vie complet ==="
JJSON=$(curl -s "$BASE/jobs" -X POST -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" \
  -d "{\"title\":\"QA Job FDM\",\"client_id\":$CLIENT_ID,\"printer_id\":$PRINTER_ID,\"filament_id\":$FIL_ID,\"print_type\":\"fdm\",\"quantity\":1}")
JOB_ID=$(echo "$JJSON" | jget "['data']['id']")
CLEANUP_JOB_IDS+=("$JOB_ID")
check "job created" "$(echo "$JJSON" | jget "['ok']")" "True"

CJOBS=$(curl -s "$BASE/jobs" -H "Authorization: Bearer $CLIENT_TOKEN")
check "client sees own job in list" "$(echo "$CJOBS" | python3 -c "import sys,json; d=json.load(sys.stdin)['data']; print(any(j['id']==$JOB_ID for j in d))" 2>/dev/null)" "True"

CODE=$(curl -s -o /dev/null -w "%{http_code}" "$BASE/jobs/$JOB_ID" -H "Authorization: Bearer $CLIENT_TOKEN")
check "client can view own job detail (200)" "$CODE" "200"

UPD=$(curl -s "$BASE/jobs/$JOB_ID" -X PUT -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" -d '{"grams_used":150,"print_hours":3}')
check "job update ok" "$(echo "$UPD" | jget "['ok']")" "True"
JDETAIL=$(curl -s "$BASE/jobs/$JOB_ID" -H "Authorization: Bearer $ADMIN_TOKEN")
# 150g * 20€/kg + 3h * 0.80€/h = 3.00 + 2.40 = 5.40
check "price_auto correct (matière figée)" "$(echo "$JDETAIL" | jget "['data']['price_auto']")" "5.40"
check "material_price snapshot correct" "$(echo "$JDETAIL" | jget "['data']['material_price']")" "20.00"

# le prix de la bobine change : le job déjà créé ne doit PAS bouger
curl -s "$BASE/filaments/$FIL_ID" -X PUT -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" \
  -d '{"material":"PLA","color":"QA-Color","color_hex":"#123456","price_per_kg":999,"stock_grams":1000,"active":1}' >/dev/null
curl -s "$BASE/jobs/$JOB_ID" -X PUT -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" -d '{"grams_used":150,"print_hours":3}' >/dev/null
JDETAIL2=$(curl -s "$BASE/jobs/$JOB_ID" -H "Authorization: Bearer $ADMIN_TOKEN")
check "prix matière reste figé après hausse du prix bobine" "$(echo "$JDETAIL2" | jget "['data']['price_auto']")" "5.40"
# remise à un prix propre pour la suite
curl -s "$BASE/filaments/$FIL_ID" -X PUT -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" \
  -d '{"material":"PLA","color":"QA-Color","color_hex":"#123456","price_per_kg":20,"stock_grams":1000,"active":1}' >/dev/null

ITEM=$(curl -s "$BASE/jobs/$JOB_ID/items" -X POST -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" -d '{"name":"Piece QA","quantity":2}')
check "job item created" "$(echo "$ITEM" | jget "['ok']")" "True"
ITEM_ID=$(echo "$ITEM" | jget "['data']['id']")
ITEMU=$(curl -s "$BASE/jobs/$JOB_ID/items/$ITEM_ID" -X PUT -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" -d '{"status":"done","name":"Piece QA","quantity":2}')
check "job item update ok" "$(echo "$ITEMU" | jget "['ok']")" "True"

check "status -> printing" "$(curl -s "$BASE/jobs/$JOB_ID/status" -X PATCH -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" -d '{"status":"printing"}' | jget "['ok']")" "True"

STOCK_BEFORE=$(curl -s "$BASE/filaments/$FIL_ID" -H "Authorization: Bearer $ADMIN_TOKEN" | jget "['data']['stock_grams']")
check "status -> done" "$(curl -s "$BASE/jobs/$JOB_ID/status" -X PATCH -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" -d '{"status":"done"}' | jget "['ok']")" "True"
STOCK_AFTER=$(curl -s "$BASE/filaments/$FIL_ID" -H "Authorization: Bearer $ADMIN_TOKEN" | jget "['data']['stock_grams']")
check "stock déduit de 150g à la complétion" "$STOCK_AFTER" "$((STOCK_BEFORE - 150))"

check "status -> picked_up" "$(curl -s "$BASE/jobs/$JOB_ID/status" -X PATCH -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" -d '{"status":"picked_up"}' | jget "['ok']")" "True"

check "payment toggle ok" "$(curl -s "$BASE/jobs/$JOB_ID/payment" -X PATCH -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" -d '{"paid":1}' | jget "['ok']")" "True"
check "job shows paid=1" "$(curl -s "$BASE/jobs/$JOB_ID" -H "Authorization: Bearer $ADMIN_TOKEN" | jget "['data']['paid']")" "1"

GJSON=$(curl -s "$BASE/jobs" -X POST -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" \
  -d "{\"title\":\"QA Job Gift\",\"client_id\":$CLIENT_ID,\"print_type\":\"fdm\",\"quantity\":1}")
GJOB_ID=$(echo "$GJSON" | jget "['data']['id']")
CLEANUP_JOB_IDS+=("$GJOB_ID")
check "gift toggle ok" "$(curl -s "$BASE/jobs/$GJOB_ID/gift" -X PATCH -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" -d '{"gifted":1}' | jget "['ok']")" "True"
check "gifted job price_final = 0" "$(curl -s "$BASE/jobs/$GJOB_ID" -H "Authorization: Bearer $ADMIN_TOKEN" | jget "['data']['price_final']")" "0.00"

TOK=$(curl -s "$BASE/jobs/$JOB_ID/token" -X POST -H "Authorization: Bearer $ADMIN_TOKEN")
TRACK_TOKEN=$(echo "$TOK" | jget "['data']['tracking_token']")
[ -z "$TRACK_TOKEN" ] && TRACK_TOKEN=$(curl -s "$BASE/jobs/$JOB_ID" -H "Authorization: Bearer $ADMIN_TOKEN" | jget "['data']['tracking_token']")
check "tracking token generated" "$([ -n "$TRACK_TOKEN" ] && echo True || echo False)" "True"
check "public track endpoint 200" "$(curl -s -o /dev/null -w "%{http_code}" "$BASE/track/$TRACK_TOKEN")" "200"

check "gallery toggle ok" "$(curl -s "$BASE/jobs/$JOB_ID/gallery" -X PATCH -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" -d '{"in_gallery":1}' | jget "['ok']")" "True"
PUBGAL=$(curl -s "$BASE/gallery")
check "public gallery contains job" "$(echo "$PUBGAL" | python3 -c "import sys,json; d=json.load(sys.stdin)['data']; print(any(j['id']==$JOB_ID for j in d))" 2>/dev/null)" "True"

echo "=== FICHIERS STL — upload + contrôle d'accès ==="
TMP_STL=$(mktemp /tmp/qa_XXXX.stl)
echo "qa test content" > "$TMP_STL"
UPLOAD=$(curl -s "$BASE/jobs/$JOB_ID/files" -X POST -H "Authorization: Bearer $ADMIN_TOKEN" -F "stl[]=@$TMP_STL;filename=qa.stl")
rm -f "$TMP_STL"
check "file upload ok" "$(echo "$UPLOAD" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['ok'] and len(d['data'])>0)" 2>/dev/null)" "True"

FILE_URL=$(curl -s "$BASE/jobs/$JOB_ID" -H "Authorization: Bearer $ADMIN_TOKEN" | jget "['data']['files'][0]['url']")
check "admin can download job file (200)" "$(curl -s -o /dev/null -w "%{http_code}" "http://localhost:$PORT$FILE_URL" -H "Authorization: Bearer $ADMIN_TOKEN")" "200"

C2JSON=$(curl -s "$BASE/auth/register" -X POST -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" \
  -d "{\"name\":\"QA Client 2\",\"email\":\"qa_client2_$RUN_ID@example.com\",\"password\":\"qaClientPass2\",\"role\":\"client\"}")
C2_ID=$(echo "$C2JSON" | jget "['data']['id']")
CLEANUP_CLIENT_IDS+=("$C2_ID")
CLIENT2_TOKEN=$(curl -s "$BASE/auth/login" -X POST -H "Content-Type: application/json" -d "{\"email\":\"qa_client2_$RUN_ID@example.com\",\"password\":\"qaClientPass2\"}" | jget "['data']['token']")
check "autre client refusé sur le fichier (403)" "$(curl -s -o /dev/null -w "%{http_code}" "http://localhost:$PORT$FILE_URL" -H "Authorization: Bearer $CLIENT2_TOKEN")" "403"
check "autre client refusé sur le détail du job (403)" "$(curl -s -o /dev/null -w "%{http_code}" "$BASE/jobs/$JOB_ID" -H "Authorization: Bearer $CLIENT2_TOKEN")" "403"

echo "=== FILE D'ATTENTE ==="
check "queue endpoint ok (admin)" "$(curl -s "$BASE/jobs/queue" -H "Authorization: Bearer $ADMIN_TOKEN" | jget "['ok']")" "True"
check "client refusé sur la file d'attente (403)" "$(curl -s -o /dev/null -w "%{http_code}" "$BASE/jobs/queue" -H "Authorization: Bearer $CLIENT_TOKEN")" "403"

REORDER_ID2=$(curl -s "$BASE/jobs" -X POST -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" \
  -d "{\"title\":\"QA Reorder 2\",\"client_id\":$CLIENT_ID,\"printer_id\":$PRINTER_ID,\"print_type\":\"fdm\",\"quantity\":1}" | jget "['data']['id']")
CLEANUP_JOB_IDS+=("$REORDER_ID2")
check "reorder endpoint ok" "$(curl -s "$BASE/jobs/reorder" -X PATCH -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" -d "{\"order\":[$REORDER_ID2,$GJOB_ID]}" | jget "['ok']")" "True"

echo "=== DASHBOARD / STATS ==="
check "dashboard ok" "$(curl -s "$BASE/dashboard" -H "Authorization: Bearer $ADMIN_TOKEN" | jget "['ok']")" "True"
check "stats ok" "$(curl -s "$BASE/stats" -H "Authorization: Bearer $ADMIN_TOKEN" | jget "['ok']")" "True"
check "client refusé sur dashboard (403)" "$(curl -s -o /dev/null -w "%{http_code}" "$BASE/dashboard" -H "Authorization: Bearer $CLIENT_TOKEN")" "403"

echo ""
echo "=================================="
echo "RESULTS: $PASS passed, $FAIL failed"
if [ $FAIL -gt 0 ]; then
  echo "FAILED:"
  for t in "${FAILED_TESTS[@]}"; do echo "  - $t"; done
  exit 1
fi
exit 0
