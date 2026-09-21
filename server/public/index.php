<?php
/**
 * Every request comes through here.
 *
 *   Listener phones      POST /v1/device/messages            X-Device-Key
 *   Merchants            everything else under /v1           Authorization: Bearer sk_live_...
 *   New merchants        POST /v1/merchants/register         challenge; existing accounts also require their current key
 *
 * Replies are JSON. Errors look like { "error": { "code": "...", "message": "..." } }.
 */

// Deployment PHP settings must also disable startup error display.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require dirname(__DIR__) . '/src/Db.php';
require dirname(__DIR__) . '/src/Parser.php';
require dirname(__DIR__) . '/src/Gateway.php';

date_default_timezone_set('UTC');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    header('Strict-Transport-Security: max-age=31536000');
}

function out($data, $code = 200)
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function fail($code, $message, $http = 400)
{
    out(['error' => ['code' => $code, 'message' => $message]], $http);
}

function body()
{
    $raw = (string) file_get_contents('php://input', false, null, 0, 65537);
    if (strlen($raw) > 65536) {
        fail('request_too_large', 'Request bodies must be no larger than 64 KiB.', 413);
    }
    $data = json_decode($raw);
    if (json_last_error() !== JSON_ERROR_NONE || !is_object($data)) {
        fail('invalid_json', 'Send a valid JSON object as the request body.', 400);
    }
    return json_decode($raw, true);
}

function bearerKey()
{
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($auth === '' && function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $key => $value) {
            if (strtolower($key) === 'authorization') $auth = $value;
        }
    }
    return preg_match('/^Bearer\s+(\S+)$/i', trim($auth), $match) ? $match[1] : '';
}

function requireTextFields(array $in, array $fields)
{
    foreach ($fields as $field) {
        if (isset($in[$field]) && !is_string($in[$field])) {
            fail('bad_request', $field . ' must be a text value.');
        }
    }
}

function client_ip()
{
    foreach ((array) Config::get('trusted_ip_headers', []) as $h) {
        if (!empty($_SERVER[$h])) {
            return trim(explode(',', $_SERVER[$h])[0]);
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

function portalSession()
{
    $token = $_COOKIE['isp_pay_session'] ?? '';
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    return Db::row("SELECT u.* FROM portal_sessions s JOIN portal_users u ON u.id=s.user_id WHERE s.token_hash=? AND s.expires_at>? AND u.status='active'", [hash('sha256', $token), date('Y-m-d H:i:s')]);
}

function portalCookie($token, $expires)
{
    setcookie('isp_pay_session', $token, ['expires' => $expires, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true, 'samesite' => 'Lax']);
}

/** A result from Gateway is either data or ['error' => [...]]. */
function reply(array $res, $okCode = 200)
{
    if (isset($res['error'])) {
        $map = ['not_found' => 404, 'intent_not_found' => 404, 'already_matched' => 409, 'already_used' => 409, 'too_many_attempts' => 429];
        out($res, $map[$res['error']['code']] ?? 422);
    }
    out($res, $okCode);
}

// Works behind a rewrite (/v1/...) and without one (/index.php/v1/...).
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path === '/index.php') $path = '/';
if (strpos($path, '/index.php/v1/') === 0) $path = substr($path, strlen('/index.php'));
if ($path !== '/') $path = rtrim($path, '/');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$seg = array_values(array_filter(explode('/', $path), 'strlen'));

try {
    if ($path !== '/v1' && strpos($path, '/v1/') !== 0) {
        require dirname(__DIR__) . '/site/render.php';
        renderPublicSite($path, $method);
        exit;
    }

    // Browser session mutations require a same-origin custom header; no CORS is granted.
    if (strpos($path, '/v1/portal/') === 0 && $method !== 'GET') {
        if (($_SERVER['HTTP_X_PORTAL_REQUEST'] ?? '') !== '1' || ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') fail('forbidden', 'Use the merchant workspace to make this request.', 403);
    }
    require dirname(__DIR__) . '/src/sandbox-routes.php';

    // ------------------------------------------------------------ merchant workspace
    if ($path === '/v1/portal/login' && $method === 'POST') {
        $in = body(); requireTextFields($in, ['email', 'password']);
        $email = strtolower(trim((string) ($in['email'] ?? '')));
        $recent = Db::row("SELECT COUNT(*) c FROM signups WHERE ip=? AND created_at>?", [client_ip(), date('Y-m-d H:i:s', time()-900)]);
        if ((int) $recent['c'] >= 20) fail('too_many_attempts', 'Too many attempts. Try again later.', 429);
        Db::run("INSERT INTO signups (ip, created_at) VALUES (?,?)", [client_ip(), date('Y-m-d H:i:s')]);
        $user = Db::row("SELECT * FROM portal_users WHERE email=? AND status='active'", [$email]);
        if (!$user || !password_verify((string) ($in['password'] ?? ''), $user['password_hash'])) { usleep(250000); fail('invalid_login', 'The email or password is incorrect.', 401); }
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) Db::run("UPDATE portal_users SET password_hash=? WHERE id=?", [password_hash($in['password'], PASSWORD_DEFAULT), $user['id']]);
        $token = bin2hex(random_bytes(32)); $expires = time()+28800;
        Db::run("DELETE FROM portal_sessions WHERE expires_at < ?", [date('Y-m-d H:i:s')]);
        Db::run("INSERT INTO portal_sessions (user_id,token_hash,expires_at,created_at,last_seen_at) VALUES (?,?,?,?,?)", [(int)$user['id'],hash('sha256',$token),date('Y-m-d H:i:s',$expires),date('Y-m-d H:i:s'),date('Y-m-d H:i:s')]);
        Db::run("UPDATE portal_users SET last_login_at=? WHERE id=?", [date('Y-m-d H:i:s'),(int)$user['id']]); portalCookie($token,$expires); out(['ok'=>true,'next'=>$user['role']==='developer'?'/sandbox':'/dashboard']);
    }
    if ($path === '/v1/portal/logout' && $method === 'POST') {
        $token=$_COOKIE['isp_pay_session']??''; if ($token) Db::run("DELETE FROM portal_sessions WHERE token_hash=?",[hash('sha256',$token)]); portalCookie('',time()-3600); out(['ok'=>true]);
    }
    if ($path === '/v1/portal/overview' && $method === 'GET') {
        $user=portalSession(); if(!$user) fail('unauthorized','Sign in to continue.',401);
        $owner=$user['role']==='owner'; $where=$owner?'1=1':'merchant_id='.(int)$user['merchant_id'];
        $totals=Db::rows("SELECT currency, COALESCE(SUM(amount),0) total FROM payments WHERE $where AND kind='credit' AND reversed=0 GROUP BY currency");
        $summary=Db::row("SELECT COALESCE(SUM(CASE WHEN kind='credit' AND reversed=0 THEN amount ELSE 0 END),0) total, SUM(status='matched') matched, SUM(status='unmatched') unmatched, MAX(currency) currency FROM payments WHERE $where");
        $devices=Db::row("SELECT COUNT(*) total, SUM(status='active' AND last_seen>?) online FROM devices WHERE $where",[date('Y-m-d H:i:s',time()-900)]);
        $payments=Db::rows("SELECT payer_name,payer_msisdn,trx_id,provider,amount,currency,status,received_at FROM payments WHERE $where ORDER BY id DESC LIMIT 12");
        $merchant=$owner?null:Db::row("SELECT public_id,name,country,currency,webhook_url FROM merchants WHERE id=?",[(int)$user['merchant_id']]);
        out(['totals'=>$totals,'user'=>['email'=>$user['email'],'role'=>$user['role']], 'merchant'=>$merchant, 'metrics'=>['total'=>$summary['total'],'matched'=>(int)$summary['matched'],'unmatched'=>(int)$summary['unmatched'],'currency'=>$summary['currency']?:($merchant['currency']??'USD'),'devices'=>(int)$devices['total'],'online'=>(int)$devices['online']], 'payments'=>$payments]);
    }

    if ($path === '/v1/portal/operations' && $method === 'GET') {
        $user=portalSession(); if(!$user)fail('unauthorized','Sign in to continue.',401);
        $where=$user['role']==='owner'?'1=1':'merchant_id='.(int)$user['merchant_id'];
        out(['devices'=>Db::rows("SELECT public_id,label,provider,status,last_seen FROM devices WHERE $where ORDER BY id DESC LIMIT 50"),
          'webhooks'=>Db::rows("SELECT event_id,type,status,attempts,last_code,created_at FROM webhook_deliveries WHERE $where ORDER BY id DESC LIMIT 30"),
          'keys'=>Db::rows("SELECT hint,status,last_used_at,created_at FROM api_keys WHERE $where ORDER BY id DESC LIMIT 30")]);
    }
    if ($path === '/v1/portal/payments' && $method === 'GET') {
        $user=portalSession(); if(!$user)fail('unauthorized','Sign in to continue.',401);
        $where=$user['role']==='owner'?'1=1':'merchant_id='.(int)$user['merchant_id']; $args=[];
        $status=$_GET['status']??'';
        if(in_array($status,['matched','unmatched'],true)){ $where.=' AND status=?';$args[]=$status; }
        $before=max(0,(int)($_GET['before']??0)); if($before){$where.=' AND id<?';$args[]=$before;}
        $rows=Db::rows("SELECT id,public_id,trx_id,amount,currency,reference,status,reversed,received_at FROM payments WHERE $where ORDER BY id DESC LIMIT 51",$args);
        $more=count($rows)>50; $rows=array_slice($rows,0,50);
        out(['payments'=>$rows,'next_before'=>$more?(int)end($rows)['id']:null]);
    }

    // ------------------------------------------------------------ phones
    if ($path === '/v1/device/messages' && $method === 'POST') {
        $in = body();
        requireTextFields($in, ['key', 'version', 'from', 'text']);
        $key = $_SERVER['HTTP_X_DEVICE_KEY'] ?? ($_SERVER['HTTP_X_DIRECTPAY_KEY'] ?? ($in['key'] ?? ''));
        $device = Gateway::deviceByKey($key);
        if (!$device) {
            fail('unknown_key', 'This key is not recognised. Create a new key and paste it into the app.', 401);
        }
        $version = substr((string) ($in['version'] ?? ''), 0, 30);
        Db::run(
            "UPDATE devices SET last_seen = ?, last_ip = ?, app_version = IF(? = '', app_version, ?) WHERE id = ?",
            [date('Y-m-d H:i:s'), substr(client_ip(), 0, 45), $version, $version, (int) $device['id']]
        );
        $text = (string) ($in['text'] ?? '');
        if ($text === '') {
            out(['ok' => true, 'result' => 'pong']);
        }
        // Android sends milliseconds. An implausible time is dropped, not stored wrong.
        if (isset($in['sentStamp']) && !is_numeric($in['sentStamp'])) fail('bad_request', 'sentStamp must be numeric.');
        $sent = (float) ($in['sentStamp'] ?? 0);
        $sent = $sent > 9999999999 ? $sent / 1000 : $sent;
        $sent = ($sent > 1500000000 && $sent < time() + 86400) ? (int) $sent : null;
        out(['ok' => true, 'result' => Gateway::ingest($device, trim((string) ($in['from'] ?? '')), $text, $sent)]);
    }

    // ------------------------------------------------------------ joining
    if ($path === '/v1/merchants/register' && $method === 'POST') {
        $in = body();
        requireTextFields($in, ['name', 'email', 'phone', 'password', 'channel', 'webhook_url', 'dial_code', 'country', 'currency', 'nonce']);
        $url = trim((string) ($in['webhook_url'] ?? ''));
        $name = trim((string) ($in['name'] ?? ''));
        $dial = preg_replace('/\D+/', '', (string) ($in['dial_code'] ?? ''));
        $email = strtolower(trim((string) ($in['email'] ?? '')));
        $phone = preg_replace('/[^0-9+]/', '', (string) ($in['phone'] ?? ''));
        $password = (string) ($in['password'] ?? '');
        if ($name === '' || $dial === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            fail('bad_request', 'name, dial_code and webhook_url are required.');
        }
        $withPortal = isset($in['email']) || isset($in['password']);
        if ($withPortal && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190)) fail('bad_email', 'Enter a valid email address.');
        if ($withPortal && (strlen($password) < 12 || strlen($password) > 72)) fail('weak_password', 'Use a password between 12 and 72 bytes.');
        if (($in['channel'] ?? 'direct') !== 'direct') fail('bad_channel', 'That payment channel is not available yet.');
        $linkUser = null;
        if ($withPortal) {
            $existingUser=Db::row("SELECT * FROM portal_users WHERE email = ?",[$email]);
            if ($existingUser) {
                $sessionUser=portalSession();
                if (!$sessionUser || (int)$sessionUser['id']!==(int)$existingUser['id'] || $existingUser['merchant_id']!==null || !password_verify($password,$existingUser['password_hash'])) fail('email_exists','Sign in to your existing developer account before connecting it to a live merchant, or use your current merchant integration.',409);
                $linkUser=(int)$existingUser['id'];
            }
        }
        if (!preg_match('/^[A-Za-z]{3}$/', $in['currency'] ?? '')) {
            fail('bad_currency', 'currency must be a three-letter currency code.');
        }
        if (in_array($dial, (array) Config::get('blocked_dial_codes', []), true)) {
            fail('country_not_supported', 'Direct Number is not offered in this country.', 403);
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'https' && !($scheme === 'http' && Config::get('allow_insecure_webhooks', false))) {
            fail('https_required', 'The webhook address must use https.');
        }
        try {
            WebhookTarget::resolve($url, (bool) Config::get('allow_insecure_webhooks', false));
        } catch (InvalidArgumentException $e) {
            fail('bad_webhook_host', 'Use a publicly reachable webhook address without URL credentials or fragments.');
        }
        $ip = client_ip();
        $recent = Db::row("SELECT COUNT(*) c FROM signups WHERE ip = ? AND created_at > ?", [$ip, date('Y-m-d H:i:s', time() - 3600)]);
        if ((int) $recent['c'] >= 10) {
            fail('too_many_attempts', 'Too many attempts. Please try again later.', 429);
        }
        Db::run("INSERT INTO signups (ip, created_at) VALUES (?, ?)", [$ip, date('Y-m-d H:i:s')]);

        // Echoing a public challenge proves reachability, not authority to rotate keys.
        $existing = Db::row("SELECT * FROM merchants WHERE webhook_url = ?", [$url]);
        if ($existing) {
            $owner = Gateway::merchantByApiKey(bearerKey());
            if (!$owner || (int) $owner['id'] !== (int) $existing['id']) {
                fail('merchant_exists', 'This webhook is already registered. Its current merchant API key is required to reissue credentials.', 409);
            }
        }
        $challenge = bin2hex(random_bytes(16));
        $res = Gateway::httpPost($url, json_encode(['type' => 'challenge', 'challenge' => $challenge, 'nonce' => substr((string) ($in['nonce'] ?? ''), 0, 64)]), ['Content-Type: application/json', 'X-Gateway-Event: challenge'], 10);
        $echo = json_decode($res['body'], true);
        if ($res['code'] !== 200 || !is_array($echo) || !is_string($echo['challenge'] ?? null) || !hash_equals($challenge, $echo['challenge'])) {
            fail('challenge_failed', 'The webhook address did not answer the verification request.', 422);
        }

        if ($existing) {
            // Authenticated rotation: commit all credential changes together.
            $secret = 'whsec_' . bin2hex(random_bytes(24));
            $pdo = Db::pdo();
            $pdo->beginTransaction();
            try {
                Db::row("SELECT id FROM merchants WHERE id = ? FOR UPDATE", [(int) $existing['id']]);
                $activeKey = Db::row("SELECT id FROM api_keys WHERE id = ? AND merchant_id = ? AND status = 'active' FOR UPDATE", [(int) $owner['key_id'], (int) $existing['id']]);
                if (!$activeKey) {
                    $pdo->rollBack();
                    fail('credentials_changed', 'Credentials changed while registration was being verified. Retry with the current merchant API key.', 409);
                }
                Db::run("UPDATE api_keys SET status = 'revoked' WHERE merchant_id = ? AND status = 'active'", [(int) $existing['id']]);
                Db::run("UPDATE merchants SET webhook_secret = ? WHERE id = ?", [$secret, (int) $existing['id']]);
                $key = Gateway::issueApiKey($existing['id']);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            out(['merchant_id' => $existing['public_id'], 'api_key' => $key, 'webhook_secret' => $secret, 'reissued' => true]);
        }
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $made = Gateway::createMerchant($name, (string) ($in['country'] ?? ''), $dial, (string) ($in['currency'] ?? ''), $url);
            if ($linkUser) {
                $linked=Db::run("UPDATE portal_users SET merchant_id=?,phone=?,role=CASE WHEN role='owner' THEN 'owner' ELSE 'merchant' END WHERE id=? AND merchant_id IS NULL AND status='active'",[(int)$made['merchant']['id'],substr($phone,0,20),$linkUser]);
                if($linked!==1) { $pdo->rollBack(); fail('account_changed','Account changed during registration. Refresh and retry.',409); }
            } elseif ($withPortal) Db::run("INSERT INTO portal_users (merchant_id, email, phone, password_hash, role, status, created_at) VALUES (?,?,?,?, 'merchant', 'active', ?)", [(int) $made['merchant']['id'], $email, substr($phone, 0, 20), password_hash($password, PASSWORD_DEFAULT), date('Y-m-d H:i:s')]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1062) {
                fail('merchant_exists', 'This webhook was registered by another request. Use the existing merchant credentials.', 409);
            }
            throw $e;
        }
        out(['merchant_id' => $made['merchant']['public_id'], 'api_key' => $made['api_key'], 'webhook_secret' => $made['webhook_secret'], 'reissued' => false], 201);
    }

    // ------------------------------------------------------------ merchants
    $m = Gateway::merchantByApiKey(bearerKey());
    if (!$m) {
        fail('unauthorized', 'A valid API key is required.', 401);
    }

    if ($path === '/v1/me' && $method === 'GET') {
        $providers = [];
        foreach (Parser::defaultSenders($m['dial_code']) as $code => $names) {
            $providers[] = ['code' => $code, 'label' => Parser::providerLabel($code)];
        }
        out(['id' => $m['public_id'], 'name' => $m['name'], 'country' => $m['country'], 'dial_code' => $m['dial_code'], 'currency' => $m['currency'],
             'webhook_url' => $m['webhook_url'], 'providers' => array_merge($providers, [['code' => 'other', 'label' => 'Another network (type its sender name below)']]), 'pay_to' => Gateway::payTo($m['id'])]);
    }

    if ($path === '/v1/devices' && $method === 'GET') {
        out(['devices' => array_map(['Gateway', 'deviceOut'], Db::rows("SELECT * FROM devices WHERE merchant_id = ? ORDER BY id", [(int) $m['id']]))]);
    }
    if ($path === '/v1/devices' && $method === 'POST') {
        reply(Gateway::createDevice($m, body()), 201);
    }
    if (count($seg) === 4 && $seg[1] === 'devices' && $method === 'POST') {
        if ($seg[3] === 'rotate') {
            $r = Gateway::rotateDeviceKey($m, $seg[2]);
            $r ? out($r) : fail('not_found', 'Device not found.', 404);
        }
        if ($seg[3] === 'revoke') {
            Gateway::revokeDevice($m, $seg[2]) ? out(['ok' => true]) : fail('not_found', 'Device not found.', 404);
        }
    }

    if ($path === '/v1/intents' && $method === 'POST') {
        reply(Gateway::createIntent($m, body()), 201);
    }
    if (count($seg) === 3 && $seg[1] === 'intents' && $method === 'GET') {
        $i = Db::row("SELECT * FROM intents WHERE merchant_id = ? AND public_id = ?", [(int) $m['id'], $seg[2]]);
        $i ? out(['intent' => Gateway::intentOut($i)]) : fail('not_found', 'Intent not found.', 404);
    }
    if (count($seg) === 4 && $seg[1] === 'intents' && $seg[3] === 'claim' && $method === 'POST') {
        $in = body();
        requireTextFields($in, ['transaction_id', 'payer_ip']);
        $payerIp = $in['payer_ip'] ?? client_ip();
        if (!filter_var($payerIp, FILTER_VALIDATE_IP)) fail('bad_request', 'payer_ip must be a valid IP address.');
        reply(Gateway::claim($m, $seg[2], $in['transaction_id'] ?? '', $payerIp));
    }

    if ($path === '/v1/payments' && $method === 'GET') {
        $status = in_array($_GET['status'] ?? '', ['matched', 'unmatched'], true) ? $_GET['status'] : '';
        $after = (int) ($_GET['after'] ?? 0);
        $sql = "SELECT * FROM payments WHERE merchant_id = ?" . ($status ? " AND status = ?" : '') . " AND id > ? ORDER BY id DESC LIMIT 200";
        $args = $status ? [(int) $m['id'], $status, $after] : [(int) $m['id'], $after];
        $list = [];
        foreach (Db::rows($sql, $args) as $p) {
            $row = Gateway::paymentOut($p, true);
            $row['known_references'] = $p['status'] === 'unmatched' ? Gateway::knownReferences($m['id'], $p['payer_key']) : [];
            $list[] = $row;
        }
        out(['payments' => $list]);
    }
    if (count($seg) === 4 && $seg[1] === 'payments' && $seg[3] === 'assign' && $method === 'POST') {
        $in = body();
        reply(Gateway::assign($m, $seg[2], $in['reference'] ?? '', $in['rule'] ?? 'manual'));
    }

    fail('not_found', 'No such endpoint.', 404);
} catch (Throwable $e) {
    error_log('[gateway] ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
    fail('server_error', 'Something went wrong. Please try again.', 500);
}
