#!/bin/bash
# End to end test on a developer machine: the gateway, a billing dashboard acting
# as its merchant, and the webhooks between them. It assumes XAMPP on F:, the
# gateway exposed at http://localhost/zz_gateway and the dashboard at
# http://localhost/radius. It seeds clearly named rows and removes them, and
# restores every dashboard setting it touches.
MYSQL="/f/xampp/mysql/bin/mysql.exe -uroot -N -B"
R="$MYSQL radius"; G="$MYSQL zz_gateway_test"
GW="http://localhost/zz_gateway/index.php"
DASH="http://localhost/radius"
PASS=0; FAIL=0
ok()  { PASS=$((PASS+1)); echo "  ok   $1"; }
bad() { FAIL=$((FAIL+1)); echo "  FAIL $1 :: $2"; }
has() { if echo "$2" | grep -q "$3"; then ok "$1"; else bad "$1" "$2"; fi; }
eq()  { if [ "$2" = "$3" ]; then ok "$1 ($2)"; else bad "$1" "got [$2] want [$3]"; fi; }
page() { curl -s -X POST -H 'Content-Type: text/plain' -d "$2" "$DASH/directpay/$1"; }
sms()  { curl -s -X POST -H "X-Device-Key: $DKEY" -H 'Content-Type: application/json' -d "{\"from\":\"$1\",\"text\":\"$2\",\"sentStamp\":$(date +%s)000}" "$GW/v1/device/messages"; }
api()  { curl -s -X "$1" -H "Authorization: Bearer $AKEY" -H 'Content-Type: application/json' ${3:+-d "$3"} "$GW$2"; }
settle() { sleep "${1:-2}"; }   # webhooks are handled after the 200 is sent

$MYSQL -e "DROP DATABASE IF EXISTS zz_gateway_test; CREATE DATABASE zz_gateway_test CHARACTER SET utf8mb4;"
KEYS="'country','country_code_phone','currency_code','payment_gateway','payment_notifications_hotspot','hotspot_sms','directpay_api_key','directpay_webhook_secret','directpay_merchant_id','directpay_gateway_url','directpay_join_nonce','directpay_join_until'"
$R -e "DROP TABLE IF EXISTS zz_dp_saved; CREATE TABLE zz_dp_saved AS SELECT setting, value FROM tbl_appconfig WHERE setting IN ($KEYS);"
restore() {
  $R -e "DELETE FROM tbl_appconfig WHERE setting IN ($KEYS); INSERT INTO tbl_appconfig (setting, value) SELECT setting, value FROM zz_dp_saved; DROP TABLE zz_dp_saved;
         DELETE FROM tbl_user_recharges WHERE username LIKE '2332440000%'; DELETE FROM tbl_transactions WHERE method LIKE 'Direct Number-ZZT%';
         DELETE FROM tbl_payment_gateway WHERE username LIKE '2332440000%'; DELETE FROM tbl_customers WHERE username LIKE '2332440000%';
         DELETE FROM tbl_plans WHERE name_plan='ZZ DirectPay Test Plan'; DELETE FROM tbl_routers WHERE name='ZZ DirectPay Test Router';
         DELETE FROM tbl_active_sessions WHERE session_id='dpe2e'; DELETE FROM tbl_logs WHERE description LIKE '%Direct Number%' AND date > NOW() - INTERVAL 1 HOUR;
         DROP TABLE IF EXISTS tbl_directpay_events, tbl_directpay_payers, tbl_directpay_purchases, tbl_directpay_payments, tbl_directpay_claims, tbl_directpay_listeners;"
  $MYSQL -e "DROP DATABASE IF EXISTS zz_gateway_test;"
  rm -f /f/xampp/tmp/sess_dpe2e /f/xampp/htdocs/radius/directpay/directpay.log
  echo "restored dashboard settings, removed test rows and the test database"
}
trap restore EXIT

$R -e "DELETE FROM tbl_appconfig WHERE setting IN ($KEYS);
       INSERT INTO tbl_appconfig (setting, value) VALUES ('country','ghana'),('country_code_phone','233'),('currency_code','GHS'),('payment_gateway','DirectNumber'),('payment_notifications_hotspot','no'),('hotspot_sms','no');
       INSERT INTO tbl_routers (name, ip_address, username, password, description, enabled) VALUES ('ZZ DirectPay Test Router','127.0.0.1:1','x','x','test',1);"
RID=$($R -e "SELECT id FROM tbl_routers WHERE name='ZZ DirectPay Test Router'")
$R -e "INSERT INTO tbl_plans (name_plan, id_bw, price, type, typebp, validity, validity_unit, routers, enabled, is_radius, pool, shared_users)
       SELECT 'ZZ DirectPay Test Plan', id_bw, 10, 'Hotspot', 'Unlimited', 1, 'Hrs', 'ZZ DirectPay Test Router', 1, 0, '', 1 FROM tbl_plans LIMIT 1;"
PID=$($R -e "SELECT id FROM tbl_plans WHERE name_plan='ZZ DirectPay Test Plan'")
printf 'aid|i:1;_bound_host|s:9:"localhost";_ip_bucket|s:4:"noip";' > /f/xampp/tmp/sess_dpe2e
$R -e "INSERT INTO tbl_active_sessions (session_id, user_id, ip_address, last_activity) VALUES ('dpe2e', 1, '127.0.0.1', NOW());"
COOKIE="PHPSESSID=dpe2e; paymentgateway_authenticated=true"
echo "router=$RID plan=$PID"

echo "== 1. joining the gateway"
R1=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"name\":\"Evil\",\"dial_code\":\"233\",\"webhook_url\":\"$DASH/directpay/webhook.php\"}" "$GW/v1/merchants/register")
has "a stranger cannot register the dashboard's address" "$R1" "challenge_failed"
R1=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"name\":\"K\",\"dial_code\":\"254\",\"webhook_url\":\"$DASH/directpay/webhook.php\"}" "$GW/v1/merchants/register")
has "Kenya is refused" "$R1" "country_not_supported"
CSRF=$(curl -s -b "$COOKIE" "$DASH/index.php?_route=paymentgateway/DirectNumber" | grep -o 'name="csrf" value="[a-f0-9]*"' | head -1 | sed -E 's/.*value="([a-f0-9]+)"/\1/')
curl -s -o /dev/null -b "$COOKIE" --data-urlencode "csrf=$CSRF" --data-urlencode "action=connect" --data-urlencode "gateway_url=$GW" "$DASH/index.php?_route=paymentgateway/DirectNumber"
AKEY=$($R -e "SELECT value FROM tbl_appconfig WHERE setting='directpay_api_key'")
has "dashboard connected and holds an API key" "$AKEY" "^sk_live_"
eq "gateway stores only a hash of the key" "$($G -e "SELECT COUNT(*) FROM api_keys WHERE key_hash = SHA2('$AKEY',256)")" "1"
has "no key, no access" "$(curl -s "$GW/v1/me")" "unauthorized"
curl -s -o /dev/null -b "$COOKIE" --data-urlencode "csrf=$CSRF" --data-urlencode "action=add" --data-urlencode "provider=telecel_gh" --data-urlencode "receiving_number=0244999888" --data-urlencode "receiving_name=ZZ TEST ISP" --data-urlencode "label=Test phone" "$DASH/index.php?_route=paymentgateway/DirectNumber"
DEV=$(api GET /v1/devices | grep -o '"id":"dev_[a-f0-9]*"' | head -1 | cut -d'"' -f4)
has "phone added from the dashboard" "$DEV" "^dev_"
DKEY=$(api POST "/v1/devices/$DEV/rotate" '{}' | grep -o '"device_key":"[a-f0-9]*"' | cut -d'"' -f4)
has "heartbeat accepted" "$(curl -s -X POST -H "X-Device-Key: $DKEY" -d '{"ping":1,"version":"1.0.0"}' "$GW/v1/device/messages")" "pong"
has "wrong device key refused" "$(curl -s -X POST -H "X-Device-Key: 0000000000000000000000000000000000000000" -d '{"ping":1}' "$GW/v1/device/messages")" "unknown_key"

echo "== 2. private and outgoing messages never stored"
has "personal sms ignored" "$(sms "+233201112223" "Hi, are you coming today?")" '"ignored"'
has "outgoing ignored" "$(sms "VodaCash" "You have sent GHS10.00 to KOFI. Transaction ID: ZZT0000001.")" '"ignored"'
eq "gateway stored nothing" "$($G -e "SELECT COUNT(*) FROM payments")" "0"

echo "== 3. customer buys, pays, webhook activates"
P=$(page purchase.php "{\"name\":\"Ama\",\"phone\":\"024 400 0001\",\"plan_id\":$PID,\"router_id\":$RID,\"mac_address\":\"AA:BB:CC:DD:EE:01\"}")
has "purchase created" "$P" '"status":"success"'; has "number to pay comes from the gateway" "$P" "0244999888"
TOK1=$(echo "$P" | sed -E 's/.*"token":"([a-f0-9]{32})".*/\1/')
has "second tap is the same purchase" "$(page purchase.php "{\"name\":\"Ama\",\"phone\":\"0244000001\",\"plan_id\":$PID,\"router_id\":$RID,\"mac_address\":\"AA:BB:CC:DD:EE:01\"}")" "$TOK1"
has "waiting before payment" "$(page status.php "{\"token\":\"$TOK1\"}")" '"waiting"'
has "payment recorded" "$(sms "VodaCash" "ZZT0000002 Confirmed. You have received GHS10.00 from 233244000001 - AMA SERWAA on 2026-09-20 at 10:11:12.")" '"recorded"'
settle 4
eq "gateway matched it" "$($G -e "SELECT CONCAT(status,'|',match_rule,'|',name_check) FROM payments WHERE trx_id='ZZT0000002'")" "matched|number|match"
eq "webhook delivered" "$($G -e "SELECT CONCAT(type,'|',status) FROM webhook_deliveries ORDER BY id DESC LIMIT 1")" "payment.matched|sent"
eq "dashboard activated it (router is unreachable on purpose)" "$($R -e "SELECT CONCAT(status,'|',stage,'|',conn_status,'|',username) FROM tbl_directpay_payments WHERE trx_id='ZZT0000002'")" "associated|provisioned|router_failed|233244000001-E:01"
eq "one package" "$($R -e "SELECT COUNT(*) FROM tbl_user_recharges WHERE username='233244000001-E:01' AND status='on'")" "1"
has "same message again is a duplicate" "$(sms "VodaCash" "ZZT0000002 Confirmed. You have received GHS10.00 from 233244000001 - AMA SERWAA on 2026-09-20 at 10:11:12.")" '"duplicate"'
$R -e "UPDATE tbl_directpay_payments SET conn_tried_at = NOW() - INTERVAL 60 SECOND WHERE trx_id='ZZT0000002'"
has "page sees connecting" "$(page status.php "{\"token\":\"$TOK1\"}")" '"connecting"'
eq "retry added no second transaction" "$($R -e "SELECT COUNT(*) FROM tbl_transactions WHERE invoice='ZZT0000002'")" "1"
eq "payment gateway row paid" "$($R -e "SELECT CONCAT(status,'|',gateway_trx_id) FROM tbl_payment_gateway WHERE username='233244000001-E:01'")" "2|ZZT0000002"

echo "== 4. webhook security"
BODY='{"id":"evt_forged","type":"payment.matched","created":1,"data":{}}'
eq "unsigned webhook refused" "$(curl -s -o /dev/null -w '%{http_code}' -X POST -d "$BODY" "$DASH/directpay/webhook.php")" "401"
eq "wrongly signed webhook refused" "$(curl -s -o /dev/null -w '%{http_code}' -X POST -H "X-Gateway-Signature: t=$(date +%s),v1=$(printf 'a%.0s' {1..64})" -d "$BODY" "$DASH/directpay/webhook.php")" "401"
eq "challenge outside a join refused" "$(curl -s -o /dev/null -w '%{http_code}' -X POST -d '{"type":"challenge","challenge":"x","nonce":"y"}' "$DASH/directpay/webhook.php")" "403"

echo "== 5. amount alone never activates (Alice and Bob)"
has "Alice waiting" "$(page purchase.php "{\"name\":\"Alice\",\"phone\":\"0244000002\",\"plan_id\":$PID,\"router_id\":$RID,\"mac_address\":\"AA:BB:CC:DD:EE:02\"}")" success
sms "VodaCash" "ZZT0000003 Confirmed. You have received GHS10.00 from 233244000003 - BOB MENSAH on 2026-09-20 at 10:20:00." >/dev/null; settle 3
eq "Bob's money stayed unmatched" "$($G -e "SELECT CONCAT(status,'|',hold_reason) FROM payments WHERE trx_id='ZZT0000003'")" "unmatched|no_waiting_intent"
eq "dashboard shows it as unassociated" "$($R -e "SELECT status FROM tbl_directpay_payments WHERE trx_id='ZZT0000003'")" "unassociated"
eq "Alice not activated" "$($R -e "SELECT COUNT(*) FROM tbl_user_recharges WHERE username='233244000002-E:02'")" "0"

echo "== 6. paid first, opened the page afterwards"
has "Bob opens the page" "$(page purchase.php "{\"name\":\"Bob\",\"phone\":\"0244000003\",\"plan_id\":$PID,\"router_id\":$RID,\"mac_address\":\"AA:BB:CC:DD:EE:03\"}")" success
settle 3
eq "earlier payment picked up" "$($R -e "SELECT CONCAT(status,'|',username) FROM tbl_directpay_payments WHERE trx_id='ZZT0000003'")" "associated|233244000003-E:03"

echo "== 7. renewal from a known number, without opening the page"
E1=$($R -e "SELECT CONCAT(expiration,' ',time) FROM tbl_user_recharges WHERE username='233244000001-E:01'")
sms "VodaCash" "ZZT0000004 Confirmed. You have received GHS10.00 from 233244000001 - AMA SERWAA on 2026-09-20 at 11:00:00." >/dev/null; settle 5
eq "dashboard recognised the renewal" "$($R -e "SELECT CONCAT(status,'|',match_rule) FROM tbl_directpay_payments WHERE trx_id='ZZT0000004'")" "associated|remembered"
eq "gateway agrees it is taken" "$($G -e "SELECT CONCAT(status,'|',match_rule,'|',reference) FROM payments WHERE trx_id='ZZT0000004'")" "matched|remembered|233244000001-E:01"
E2=$($R -e "SELECT CONCAT(expiration,' ',time) FROM tbl_user_recharges WHERE username='233244000001-E:01'")
eq "running package extended by its validity" "$($R -e "SELECT TIMESTAMPDIFF(MINUTE,'$E1','$E2')")" "60"

echo "== 8. typing someone else's number"
page purchase.php "{\"name\":\"Mallory\",\"phone\":\"0244000001\",\"plan_id\":$PID,\"router_id\":$RID,\"mac_address\":\"AA:BB:CC:DD:EE:66\"}" >/dev/null
sms "VodaCash" "ZZT0000005 Confirmed. You have received GHS10.00 from 233244000001 - AMA SERWAA on 2026-09-20 at 11:30:00." >/dev/null; settle 3
eq "held at the gateway" "$($G -e "SELECT CONCAT(status,'|',hold_reason) FROM payments WHERE trx_id='ZZT0000005'")" "unmatched|number_held_by_another_reference"
eq "Mallory not activated" "$($R -e "SELECT COUNT(*) FROM tbl_user_recharges WHERE username='233244000001-E:66'")" "0"

echo "== 9. network sends no payer number, customer claims by transaction ID"
P=$(page purchase.php "{\"name\":\"Kwame\",\"phone\":\"0244000004\",\"plan_id\":$PID,\"router_id\":$RID,\"mac_address\":\"AA:BB:CC:DD:EE:04\"}"); TOK4=$(echo "$P" | sed -E 's/.*"token":"([a-f0-9]{32})".*/\1/')
$G -e "UPDATE devices SET extra_senders='MobileMoney'"
sms "MobileMoney" "Payment received for GHS 10.00 from KWAME MENSAH Current Balance: GHS 105.00 . Reference: 1. Transaction ID: ZZT0000006. TRANSACTION FEE: 0.00" >/dev/null; settle 3
eq "held for lack of a number" "$($G -e "SELECT CONCAT(status,'|',hold_reason) FROM payments WHERE trx_id='ZZT0000006'")" "unmatched|no_number"
has "invented ID finds nothing" "$(page claim.php "{\"token\":\"$TOK4\",\"trx_id\":\"ZZT9999999\"}")" "not received"
has "wrong person cannot claim it" "$(page claim.php "{\"token\":\"$TOK1\",\"trx_id\":\"ZZT0000006\"}")" error
has "rightful claim succeeds" "$(page claim.php "{\"token\":\"$TOK4\",\"trx_id\":\"zzt0000006\"}")" '"ok":true'
has "claiming twice gives the same answer" "$(page claim.php "{\"token\":\"$TOK4\",\"trx_id\":\"ZZT0000006\"}")" '"ok":true'
settle 2
eq "one package only" "$($R -e "SELECT COUNT(*) FROM tbl_transactions WHERE invoice='ZZT0000006'")" "1"

echo "== 10. assigning by hand from the dashboard"
LID=$($R -e "SELECT id FROM tbl_directpay_payments WHERE trx_id='ZZT0000005'")
curl -s -o /dev/null -b "$COOKIE" --data-urlencode "csrf=$CSRF" --data-urlencode "username=233244000001-E:01" --data-urlencode "plan_id=$PID" "$DASH/index.php?_route=directpay/assign/$LID"
eq "assigned locally" "$($R -e "SELECT CONCAT(status,'|',match_rule,'|',assigned_by) FROM tbl_directpay_payments WHERE id=$LID")" "associated|manual|1"
eq "and taken at the gateway" "$($G -e "SELECT CONCAT(status,'|',match_rule) FROM payments WHERE trx_id='ZZT0000005'")" "matched|manual"
PAYID=$($G -e "SELECT public_id FROM payments WHERE trx_id='ZZT0000005'")
has "cannot be given to anyone else afterwards" "$(api POST "/v1/payments/$PAYID/assign" '{"reference":"someone-else"}')" "already_matched"

echo "== 11. reversal"
has "reversal recorded" "$(sms "VodaCash" "Transaction ZZT0000003 has been reversed. Transaction ID: ZZT0000003")" reversal; settle 3
eq "dashboard flagged it" "$($R -e "SELECT reversed FROM tbl_directpay_payments WHERE trx_id='ZZT0000003'")" "1"

echo "== 12. one merchant cannot see another's payments"
M2=$(cd /f/paymentapp/server && /f/xampp/php/php.exe bin/create-merchant.php "ZZ Other ISP" ghana 233 GHS "http://localhost/zz_none" | grep "api key" | awk '{print $3}')
eq "other merchant sees nothing" "$(curl -s -H "Authorization: Bearer $M2" "$GW/v1/payments" | grep -o 'pay_' | wc -l | tr -d ' ')" "0"
has "and cannot assign ours" "$(curl -s -X POST -H "Authorization: Bearer $M2" -d '{"reference":"x"}' "$GW/v1/payments/$PAYID/assign")" "not_found"

echo
echo "PASS=$PASS FAIL=$FAIL"
