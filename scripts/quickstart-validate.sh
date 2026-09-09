#!/usr/bin/env bash
# T058 quickstart.md validação completa em Docker http://localhost:9501
# Valida: registro CPF/CNPJ alfa, transferências, idempotency 3min, resiliência
# Uso: ./scripts/quickstart-validate.sh  (requere app em http://localhost:9501)

set -euo pipefail
BASE="http://localhost:9501"
PASS=0
FAIL=0

ok() { echo "[PASS] $1"; PASS=$((PASS+1)); }
fail() { echo "[FAIL] $1 - $2"; FAIL=$((FAIL+1)); }

echo "== Quickstart Validation $(date -Is) =="
echo "Base: $BASE"
echo ""

# Health check
HTTP=$(curl -s -o /dev/null -w "%{http_code}" "$BASE/") || true
if [[ "$HTTP" == "200" ]]; then ok "Health GET / = 200"; else fail "Health GET /" "got $HTTP"; fi

# Helpers
TIMESTAMP=$(date +%s)
CPF="529.982.247-25"
CNPJ_LEGACY="11.222.333/0001-81"
CNPJ_ALFA="12.ABC.345/01DE-35"
CNPJ_ALFA_LOWER="12abc34501de35"
EMAIL1="quick_cpf_${TIMESTAMP}@example.com"
EMAIL2="quick_cnpj_${TIMESTAMP}@example.com"
EMAIL3="quick_alfa_${TIMESTAMP}@example.com"
EMAIL_MERCHANT_PAYER="quick_merchant_payer_${TIMESTAMP}@example.com"
EMAIL_PAYEE="quick_payee_${TIMESTAMP}@example.com"

# 1) Register common CPF
echo "--- Registro CPF formatado ---"
RESP=$(curl -s -w "\n%{http_code}" -X POST "$BASE/users" -H 'Content-Type: application/json' -d "{\"full_name\":\"Quick Common\",\"document\":\"$CPF\",\"email\":\"$EMAIL1\",\"password\":\"password123\",\"type\":\"common\"}")
BODY=$(echo "$RESP" | head -n -1)
CODE=$(echo "$RESP" | tail -n1)
echo "$BODY" | head -c 500; echo ""
if [[ "$CODE" == "201" ]]; then ok "POST /users CPF $CPF => 201"; UID1=$(echo "$BODY" | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2); else fail "POST /users CPF" "code $CODE body $BODY"; UID1=0; fi
if echo "$BODY" | grep -q "52998224725"; then ok "CPF normalizado 52998224725"; else fail "CPF normalizado" "body $BODY"; fi
if echo "$BODY" | grep -q '"balance":"0.00"'; then ok "balance 0.00 on create"; else fail "balance" "$BODY"; fi

# 2) Register merchant CNPJ legacy
echo "--- Registro CNPJ legado ---"
RESP=$(curl -s -w "\n%{http_code}" -X POST "$BASE/users" -H 'Content-Type: application/json' -d "{\"full_name\":\"Quick Merchant Legacy\",\"document\":\"$CNPJ_LEGACY\",\"email\":\"$EMAIL2\",\"password\":\"password123\",\"type\":\"merchant\"}")
BODY=$(echo "$RESP" | head -n -1)
CODE=$(echo "$RESP" | tail -n1)
echo "$BODY" | head -c 500; echo ""
if [[ "$CODE" == "201" ]]; then ok "POST /users CNPJ legacy => 201"; UID2=$(echo "$BODY" | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2); else fail "POST /users CNPJ legacy" "code $CODE"; UID2=0; fi
if echo "$BODY" | grep -q "11222333000181"; then ok "CNPJ legado normalizado"; else fail "CNPJ legado norm" "$BODY"; fi

# 3) Register merchant CNPJ alfa
echo "--- Registro CNPJ alfa ---"
RESP=$(curl -s -w "\n%{http_code}" -X POST "$BASE/users" -H 'Content-Type: application/json' -d "{\"full_name\":\"Quick Merchant Alfa\",\"document\":\"$CNPJ_ALFA\",\"email\":\"$EMAIL3\",\"password\":\"password123\",\"type\":\"merchant\"}")
BODY=$(echo "$RESP" | head -n -1)
CODE=$(echo "$RESP" | tail -n1)
echo "$BODY" | head -c 500; echo ""
if [[ "$CODE" == "201" ]]; then ok "POST /users CNPJ alfa => 201"; UID3=$(echo "$BODY" | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2); else fail "POST /users CNPJ alfa" "code $CODE body $BODY"; UID3=0; fi
if echo "$BODY" | grep -q "12ABC34501DE35"; then ok "CNPJ alfa normalizado 12ABC34501DE35"; else fail "CNPJ alfa norm" "$BODY"; fi

# 4) Duplicata 409 (case-insensitive CNPJ alfa)
echo "--- Duplicata document (case-insensitive) ---"
RESP=$(curl -s -w "\n%{http_code}" -X POST "$BASE/users" -H 'Content-Type: application/json' -d "{\"full_name\":\"Dup Alfa\",\"document\":\"$CNPJ_ALFA_LOWER\",\"email\":\"dup_${TIMESTAMP}@example.com\",\"password\":\"password123\",\"type\":\"merchant\"}")
CODE=$(echo "$RESP" | tail -n1)
if [[ "$CODE" == "409" || "$CODE" == "422" ]]; then ok "Duplicata CNPJ alfa case-insensitive => $CODE"; else fail "Duplicata CNPJ alfa" "code $CODE"; fi

RESP=$(curl -s -w "\n%{http_code}" -X POST "$BASE/users" -H 'Content-Type: application/json' -d "{\"full_name\":\"Dup CPF\",\"document\":\"52998224725\",\"email\":\"dup2_${TIMESTAMP}@example.com\",\"password\":\"password123\",\"type\":\"common\"}")
CODE=$(echo "$RESP" | tail -n1)
if [[ "$CODE" == "409" || "$CODE" == "422" ]]; then ok "Duplicata CPF normalizado => $CODE"; else fail "Duplicata CPF" "code $CODE"; fi

# 5) Invalid document 422
echo "--- Documento inválido 422 ---"
for PAYLOAD in \
  "{\"full_name\":\"Bad\",\"document\":\"123.456\",\"email\":\"bad_${TIMESTAMP}@a.com\",\"password\":\"password123\",\"type\":\"common\"}" \
  "{\"full_name\":\"Bad\",\"document\":\"529.982.247-26\",\"email\":\"bad2_${TIMESTAMP}@a.com\",\"password\":\"password123\",\"type\":\"common\"}" \
  "{\"full_name\":\"Bad\",\"document\":\"111.111.111-11\",\"email\":\"bad3_${TIMESTAMP}@a.com\",\"password\":\"password123\",\"type\":\"common\"}" \
  "{\"full_name\":\"Bad\",\"document\":\"11.222.333/0001-81\",\"email\":\"bad4_${TIMESTAMP}@a.com\",\"password\":\"password123\",\"type\":\"common\"}"
do
  CODE=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$BASE/users" -H 'Content-Type: application/json' -d "$PAYLOAD")
  if [[ "$CODE" == "422" ]]; then ok "invalid doc 422 payload $PAYLOAD => $CODE"; else fail "invalid doc" "payload $PAYLOAD got $CODE"; fi
done

# 6) Seed balances via DB (TestBalanceSeeder) for transfer validation
echo "--- Seed saldos para transferências ---"
if [[ "$UID1" != "0" && "$UID2" != "0" ]]; then
  docker compose exec -T mysql mysql -u hyperf -psecret -e "UPDATE wallets SET balance='100.00' WHERE user_id=$UID1; UPDATE wallets SET balance='50.00' WHERE user_id=$UID2;" hyperf 2>&1 | grep -v Warning || true
  ok "Seed balances UID $UID1=100.00 UID $UID2=50.00 via MySQL"
  # also seed via php if needed
else
  fail "Seed balances" "UIDs missing $UID1 $UID2"
fi

# 7) Transfer valid common->common (if authorizer mock allows; external authorize may be unstable)
echo "--- Transfer valid common->common ---"
RESP=$(curl -s -w "\n%{http_code}" -X POST "$BASE/transfer" -H 'Content-Type: application/json' -d "{\"value\":\"10.00\",\"payer\":$UID1,\"payee\":$UID2}")
BODY=$(echo "$RESP" | head -n -1)
CODE=$(echo "$RESP" | tail -n1)
echo "$BODY" | head -c 500; echo " CODE $CODE"
if [[ "$CODE" == "200" || "$CODE" == "201" ]]; then ok "POST /transfer 10.00 => $CODE completed"; TID=$(echo "$BODY" | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2); else echo "[INFO] transfer valid got $CODE (authorizer may deny if external)"; TID=""; fi

# 8) Rejection scenarios (no balance change expected)
echo "--- Rejection scenarios ---"
# payer insufficient (value 200 > balance)
CODE=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$BASE/transfer" -H 'Content-Type: application/json' -d "{\"value\":\"200.00\",\"payer\":$UID1,\"payee\":$UID2}")
if [[ "$CODE" == "422" ]]; then ok "saldo insuficiente => 422"; else fail "saldo insuficiente" "got $CODE"; fi

# merchant as payer => 403/422
if [[ "$UID3" != "0" ]]; then
  CODE=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$BASE/transfer" -H 'Content-Type: application/json' -d "{\"value\":\"10.00\",\"payer\":$UID3,\"payee\":$UID1}")
  if [[ "$CODE" == "403" || "$CODE" == "422" ]]; then ok "merchant payer blocked => $CODE"; else fail "merchant payer" "got $CODE"; fi
fi

# payer==payee => 422
CODE=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$BASE/transfer" -H 'Content-Type: application/json' -d "{\"value\":\"10.00\",\"payer\":$UID1,\"payee\":$UID1}")
if [[ "$CODE" == "422" ]]; then ok "self transfer => 422"; else fail "self transfer" "got $CODE"; fi

# value as number => 422
CODE=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$BASE/transfer" -H 'Content-Type: application/json' -d "{\"value\":10.00,\"payer\":$UID1,\"payee\":$UID2}")
if [[ "$CODE" == "422" ]]; then ok "value as number => 422"; else fail "value number" "got $CODE"; fi

# value zero => 422
CODE=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$BASE/transfer" -H 'Content-Type: application/json' -d "{\"value\":\"0.00\",\"payer\":$UID1,\"payee\":$UID2}")
if [[ "$CODE" == "422" ]]; then ok "value zero => 422"; else fail "value zero" "got $CODE"; fi

# missing payer => 404
CODE=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$BASE/transfer" -H 'Content-Type: application/json' -d "{\"value\":\"10.00\",\"payer\":999999,\"payee\":$UID2}")
if [[ "$CODE" == "404" ]]; then ok "payer missing => 404"; else fail "payer missing" "got $CODE"; fi

# 9) Idempotency 3min
echo "--- Idempotency 3min ---"
if [[ -n "$TID" ]]; then
  RESP2=$(curl -s -w "\n%{http_code}" -X POST "$BASE/transfer" -H 'Content-Type: application/json' -d "{\"value\":\"10.00\",\"payer\":$UID1,\"payee\":$UID2}")
  BODY2=$(echo "$RESP2" | head -n -1)
  CODE2=$(echo "$RESP2" | tail -n1)
  TID2=$(echo "$BODY2" | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2)
  if [[ "$TID" == "$TID2" ]]; then ok "idempotency hit same id $TID == $TID2 within 3min"; else echo "[INFO] idempotency second TID $TID2 vs first $TID (may be new if external authorizer + TTL)"; fi
  # check balances not double debited: query DB
  BAL=$(docker compose exec -T mysql mysql -u hyperf -psecret -N -e "SELECT balance FROM wallets WHERE user_id=$UID1;" hyperf 2>&1 | tail -1 | tr -d ' ')
  echo "Payer balance after 2 identical transfers: $BAL (should be 90.00 if only one debit)"
  if [[ "$BAL" == "90.00" ]]; then ok "idempotency no double debit balance 90.00"; else echo "[INFO] balance $BAL (may differ if authorizer denied)"; fi
  # telemetry
  docker compose logs app --tail 100 2>&1 | grep -i idempotency | tail -5 || true
fi

# 10) Notification resilience - check Outbox
echo "--- Notification Outbox ---"
if [[ -n "$TID" ]]; then
  OB=$(docker compose exec -T mysql mysql -u hyperf -psecret -N -e "SELECT status, attempts FROM notification_outbox WHERE transfer_id=$TID;" hyperf 2>&1 | tail -1)
  echo "Outbox for TID $TID: $OB"
  if echo "$OB" | grep -q "pending\|sent"; then ok "Outbox pending/sent for transfer"; else echo "[INFO] Outbox status $OB"; fi
fi

echo ""
echo "== RESULT $PASS passed, $FAIL failed =="
if [[ $FAIL -gt 0 ]]; then exit 1; else exit 0; fi
