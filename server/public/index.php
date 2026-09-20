<?php
/**
 * Every request comes through here.
 *
 *   Listener phones      POST /v1/device/messages            X-Device-Key
 *   Merchants            everything else under /v1           Authorization: Bearer sk_live_...
 *   New merchants        POST /v1/merchants/register         open, proven by a challenge
 *
 * Replies are JSON. Errors look like { "error": { "code": "...", "message": "..." } }.
 */

require dirname(__DIR__) . '/src/Db.php';
require dirname(__DIR__) . '/src/Parser.php';
require dirname(__DIR__) . '/src/Gateway.php';

date_default_timezone_set('UTC');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

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
    $data = json_decode((string) file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
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
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$pos = strpos($path, '/v1/');
$path = $pos === false ? '/' : substr($path, $pos);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$seg = array_values(array_filter(explode('/', $path), 'strlen'));

try {
    // The public site: a front page, the API reference and the app download.
    if ($path === '/') {
        $page = trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
        $page = $page === '' ? '' : basename($page);
        if ($page === 'download') {
            $apk = dirname(__DIR__, 2) . '/dist/PaymentBridge.apk';
            if (!is_file($apk)) {
                fail('not_found', 'The app is not available right now.', 404);
            }
            header('Content-Type: application/vnd.android.package-archive');
            header('Content-Disposition: attachment; filename="PaymentBridge.apk"');
            header('Content-Length: ' . filesize($apk));
            readfile($apk);
            exit;
        }
        $file = dirname(__DIR__) . '/site/' . ($page === 'docs' ? 'docs.html' : 'home.html');
        if ($page !== '' && $page !== 'docs') {
            http_response_code(404);
        }
        header('Content-Type: text/html; charset=utf-8');
        echo str_replace('/*STYLE*/', file_get_contents(dirname(__DIR__) . '/site/_style.css'), file_get_contents($file));
        exit;
    }

    // ------------------------------------------------------------ phones
    if ($path === '/v1/device/messages' && $method === 'POST') {
        $in = body();
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
        $sent = (float) ($in['sentStamp'] ?? 0);
        $sent = $sent > 9999999999 ? $sent / 1000 : $sent;
        $sent = ($sent > 1500000000 && $sent < time() + 86400) ? (int) $sent : null;
        out(['ok' => true, 'result' => Gateway::ingest($device, trim((string) ($in['from'] ?? '')), $text, $sent)]);
    }

    // ------------------------------------------------------------ joining
    if ($path === '/v1/merchants/register' && $method === 'POST') {
        $in = body();
        $url = trim((string) ($in['webhook_url'] ?? ''));
        $name = trim((string) ($in['name'] ?? ''));
        $dial = preg_replace('/\D+/', '', (string) ($in['dial_code'] ?? ''));
        if ($name === '' || $dial === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            fail('bad_request', 'name, dial_code and webhook_url are required.');
        }
        if (in_array($dial, (array) Config::get('blocked_dial_codes', []), true)) {
            fail('country_not_supported', 'Direct Number is not offered in this country.', 403);
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'https' && !($scheme === 'http' && Config::get('allow_insecure_webhooks', false))) {
            fail('https_required', 'The webhook address must use https.');
        }
        if (!Config::get('allow_insecure_webhooks', false)) {
            $ip = gethostbyname((string) parse_url($url, PHP_URL_HOST));
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                fail('bad_webhook_host', 'The webhook address must be reachable from the internet.');
            }
        }
        $ip = client_ip();
        $recent = Db::row("SELECT COUNT(*) c FROM signups WHERE ip = ? AND created_at > ?", [$ip, date('Y-m-d H:i:s', time() - 3600)]);
        if ((int) $recent['c'] >= 10) {
            fail('too_many_attempts', 'Too many attempts. Please try again later.', 429);
        }
        Db::run("INSERT INTO signups (ip, created_at) VALUES (?, ?)", [$ip, date('Y-m-d H:i:s')]);

        // Whoever answers at that address owns it. No shared secret is needed
        // to join, and nobody can register an address they do not control.
        $challenge = bin2hex(random_bytes(16));
        $res = Gateway::httpPost($url, json_encode(['type' => 'challenge', 'challenge' => $challenge, 'nonce' => substr((string) ($in['nonce'] ?? ''), 0, 64)]), ['Content-Type: application/json', 'X-Gateway-Event: challenge'], 10);
        $echo = json_decode($res['body'], true);
        if ($res['code'] !== 200 || !is_array($echo) || !hash_equals($challenge, (string) ($echo['challenge'] ?? ''))) {
            fail('challenge_failed', 'The webhook address did not answer the verification request.', 422);
        }

        $existing = Db::row("SELECT * FROM merchants WHERE webhook_url = ?", [$url]);
        if ($existing) {
            // Same address proving itself again: lost keys. Old keys stop working.
            $secret = 'whsec_' . bin2hex(random_bytes(24));
            Db::run("UPDATE api_keys SET status = 'revoked' WHERE merchant_id = ? AND status = 'active'", [(int) $existing['id']]);
            Db::run("UPDATE merchants SET webhook_secret = ?, status = 'active' WHERE id = ?", [$secret, (int) $existing['id']]);
            out(['merchant_id' => $existing['public_id'], 'api_key' => Gateway::issueApiKey($existing['id']), 'webhook_secret' => $secret, 'reissued' => true]);
        }
        $made = Gateway::createMerchant($name, (string) ($in['country'] ?? ''), $dial, (string) ($in['currency'] ?? ''), $url);
        out(['merchant_id' => $made['merchant']['public_id'], 'api_key' => $made['api_key'], 'webhook_secret' => $made['webhook_secret'], 'reissued' => false], 201);
    }

    // ------------------------------------------------------------ merchants
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($auth === '' && function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $k => $v) {
            if (strtolower($k) === 'authorization') {
                $auth = $v;
            }
        }
    }
    $m = Gateway::merchantByApiKey(preg_replace('/^Bearer\s+/i', '', trim($auth)));
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
        reply(Gateway::claim($m, $seg[2], $in['transaction_id'] ?? '', substr((string) ($in['payer_ip'] ?? ''), 0, 45)));
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
