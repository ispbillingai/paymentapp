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
    return Db::row("SELECT u.*, s.acting_merchant_id, s.id AS session_id FROM portal_sessions s JOIN portal_users u ON u.id=s.user_id WHERE s.token_hash=? AND s.expires_at>? AND u.status='active'", [hash('sha256', $token), date('Y-m-d H:i:s')]);
}

/**
 * Which merchant the signed-in person is looking at, as a SQL condition.
 *
 * A merchant only ever sees their own. A platform owner sees everything, unless
 * they have chosen to look at one merchant's workspace, which narrows what is
 * shown without granting anything: an owner can already act on any merchant.
 */
function portalScope(array $user)
{
    if ($user['role'] !== 'owner') return 'merchant_id=' . (int) $user['merchant_id'];
    return $user['acting_merchant_id'] ? 'merchant_id=' . (int) $user['acting_merchant_id'] : '1=1';
}

function portalCookie($token, $expires)
{
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    setcookie('isp_pay_session', $token, ['expires' => $expires, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
    // A readable marker, so public pages can offer "Dashboard" instead of "Sign in".
    // It carries no authority: it is only ever a hint, and the session cookie above,
    // which scripts cannot read, is still what every request is checked against.
    setcookie('isp_pay_in', $token === '' ? '' : '1', ['expires' => $expires, 'path' => '/', 'secure' => $secure, 'httponly' => false, 'samesite' => 'Lax']);
}

/** Starts a browser session for this person: the row, the cookies, the last sign-in time. */
function portalSignIn($userId)
{
    $token = bin2hex(random_bytes(32));
    $expires = time() + 28800;
    $now = date('Y-m-d H:i:s');
    Db::run("DELETE FROM portal_sessions WHERE expires_at < ?", [$now]);
    Db::run("INSERT INTO portal_sessions (user_id,token_hash,expires_at,created_at,last_seen_at) VALUES (?,?,?,?,?)", [(int) $userId, hash('sha256', $token), date('Y-m-d H:i:s', $expires), $now, $now]);
    $sessionId = (int) Db::lastId();
    Db::run("UPDATE portal_users SET last_login_at=? WHERE id=?", [$now, (int) $userId]);
    portalCookie($token, $expires);
    return $sessionId;
}

/**
 * Remembers which workspace an owner was last in, so signing in again returns them
 * to it instead of making them switch every time. It is only a preference: it is
 * honoured for an owner alone, who may view any merchant anyway, and only if that
 * merchant still exists.
 */
function rememberView($publicId)
{
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    setcookie('isp_pay_view', (string) $publicId, ['expires' => $publicId === '' ? time() - 3600 : time() + 31536000, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
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

    if (in_array($path, ['/v1/download', '/v1/app/download', '/v1/app'], true) && in_array($method, ['GET', 'HEAD'], true)) {
        header('Location: /download', true, 302);
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
        $sessionId = portalSignIn((int) $user['id']);
        $wanted = (string) ($_COOKIE['isp_pay_view'] ?? '');
        if ($user['role'] === 'owner' && preg_match('/^mer_[a-f0-9]{8,40}$/', $wanted)) {
            $last = Db::row("SELECT id FROM merchants WHERE public_id = ?", [$wanted]);
            if ($last) Db::run("UPDATE portal_sessions SET acting_merchant_id = ? WHERE id = ?", [(int) $last['id'], $sessionId]);
        }
        out(['ok'=>true,'next'=>$user['role']==='developer'?'/sandbox':'/dashboard']);
    }

    /**
     * An ISP creating their account. It asks only for who they are, and they arrive
     * signed in on their dashboard, where they make their key, pair their phone and
     * set their webhook. The webhook used to be demanded here, before an account
     * existed, which nobody without a finished integration could get past; it is
     * still challenge-verified, at the point they actually add it.
     */
    if ($path === '/v1/portal/signup' && $method === 'POST') {
        $in = body(); requireTextFields($in, ['name', 'email', 'phone', 'password', 'country', 'dial_code', 'currency']);
        $name = trim((string) ($in['name'] ?? ''));
        $email = strtolower(trim((string) ($in['email'] ?? '')));
        $phone = preg_replace('/[^0-9+]/', '', (string) ($in['phone'] ?? ''));
        $password = (string) ($in['password'] ?? '');
        $dial = preg_replace('/[^0-9]+/', '', (string) ($in['dial_code'] ?? ''));
        if ($name === '' || $dial === '') fail('bad_request', 'Your business name and country calling code are required.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) fail('bad_email', 'Enter a valid email address.');
        if (strlen($password) < 12 || strlen($password) > 72) fail('weak_password', 'Use a password between 12 and 72 bytes.');
        if (!preg_match('/^[A-Za-z]{3}$/', (string) ($in['currency'] ?? ''))) fail('bad_currency', 'currency must be a three-letter currency code.');
        if (in_array($dial, (array) Config::get('blocked_dial_codes', []), true)) fail('country_not_supported', 'Direct Number is not offered in this country.', 403);
        $ip = client_ip();
        $recent = Db::row("SELECT COUNT(*) c FROM signups WHERE ip = ? AND created_at > ?", [$ip, date('Y-m-d H:i:s', time() - 3600)]);
        if ((int) $recent['c'] >= 10) fail('too_many_attempts', 'Too many attempts. Please try again later.', 429);
        Db::run("INSERT INTO signups (ip, created_at) VALUES (?, ?)", [$ip, date('Y-m-d H:i:s')]);
        if (Db::row("SELECT id FROM portal_users WHERE email = ?", [$email])) fail('email_exists', 'That email already has an account. Sign in instead.', 409);
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $made = Gateway::createMerchant($name, (string) ($in['country'] ?? ''), $dial, (string) ($in['currency'] ?? ''), '', false);
            Db::run("INSERT INTO portal_users (merchant_id, email, phone, password_hash, role, status, created_at) VALUES (?,?,?,?, 'merchant', 'active', ?)",
                [(int) $made['merchant']['id'], $email, substr($phone, 0, 20), password_hash($password, PASSWORD_DEFAULT), date('Y-m-d H:i:s')]);
            $userId = (int) Db::lastId();
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1062) fail('email_exists', 'That email already has an account. Sign in instead.', 409);
            throw $e;
        }
        portalSignIn($userId);
        out(['ok' => true, 'next' => '/dashboard'], 201);
    }
    if ($path === '/v1/portal/logout' && $method === 'POST') {
        $token=$_COOKIE['isp_pay_session']??''; if ($token) Db::run("DELETE FROM portal_sessions WHERE token_hash=?",[hash('sha256',$token)]); portalCookie('',time()-3600); out(['ok'=>true]);
    }
    if ($path === '/v1/portal/overview' && $method === 'GET') {
        $user=portalSession(); if(!$user) fail('unauthorized','Sign in to continue.',401);
        $owner=$user['role']==='owner' && !$user['acting_merchant_id']; $where=portalScope($user);
        $totals=Db::rows("SELECT currency, COALESCE(SUM(amount),0) total FROM payments WHERE $where AND kind='credit' AND reversed=0 GROUP BY currency");
        $summary=Db::row("SELECT COALESCE(SUM(CASE WHEN kind='credit' AND reversed=0 THEN amount ELSE 0 END),0) total, SUM(status='matched') matched, SUM(status='unmatched') unmatched, MAX(currency) currency FROM payments WHERE $where");
        $devices=Db::row("SELECT COUNT(*) total, SUM(status='active' AND last_seen>?) online FROM devices WHERE $where",[date('Y-m-d H:i:s',time()-900)]);
        $payments=Db::rows("SELECT payer_name,payer_msisdn,trx_id,provider,amount,currency,status,received_at FROM payments WHERE $where ORDER BY id DESC LIMIT 12");
        // The owner's own account has no merchant; while looking at one, that is the merchant shown.
        $shownId=$user['role']==='owner'?$user['acting_merchant_id']:$user['merchant_id'];
        $merchant=$shownId?Db::row("SELECT public_id,name,country,currency,webhook_url FROM merchants WHERE id=?",[(int)$shownId]):null;
        out(['totals'=>$totals,'user'=>['email'=>$user['email'],'role'=>$user['role']],
             'acting_as'=>$user['role']==='owner'&&$merchant?['id'=>$merchant['public_id'],'name'=>$merchant['name'],'own'=>(int)$shownId===(int)$user['merchant_id']]:null, 'merchant'=>$merchant, 'metrics'=>['total'=>$summary['total'],'matched'=>(int)$summary['matched'],'unmatched'=>(int)$summary['unmatched'],'currency'=>$summary['currency']?:($merchant['currency']??'USD'),'devices'=>(int)$devices['total'],'online'=>(int)$devices['online'],
             'keys'=>(int)Db::row("SELECT COUNT(*) c FROM api_keys WHERE $where AND status='active'")['c']], 'payments'=>$payments]);
    }

    if ($path === '/v1/portal/operations' && $method === 'GET') {
        $user=portalSession(); if(!$user)fail('unauthorized','Sign in to continue.',401);
        $where=portalScope($user);
        // deviceOut is what the API returns everywhere else, so the workspace shows the same fields.
        // Each row names its merchant, so a view across every merchant still says whose it is.
        $names=[]; $publicIds=[]; foreach(Db::rows("SELECT id,public_id,name FROM merchants") as $row){ $names[(int)$row['id']]=$row['name']; $publicIds[(int)$row['id']]=$row['public_id']; }
        $devices=array_map(static function($d) use ($names,$publicIds){ return Gateway::deviceOut($d)+['merchant'=>$names[(int)$d['merchant_id']]??'','merchant_id'=>$publicIds[(int)$d['merchant_id']]??'']; },Db::rows("SELECT * FROM devices WHERE $where ORDER BY id DESC LIMIT 50"));
        out(['devices'=>$devices,
          'webhooks'=>Db::rows("SELECT event_id,type,status,attempts,last_code,created_at FROM webhook_deliveries WHERE $where ORDER BY id DESC LIMIT 30"),
          'keys'=>array_map(static function($k) use ($names,$publicIds){ $id=(int)$k['merchant_id']; $k['merchant']=$names[$id]??''; $k['merchant_id']=$publicIds[$id]??''; return $k; },Db::rows("SELECT merchant_id,hint,status,last_used_at,created_at FROM api_keys WHERE $where ORDER BY id DESC LIMIT 30"))]);
    }
    if ($path === '/v1/portal/payments' && $method === 'GET') {
        $user=portalSession(); if(!$user)fail('unauthorized','Sign in to continue.',401);
        $where=portalScope($user); $args=[];
        $status=$_GET['status']??'';
        if(in_array($status,['matched','unmatched'],true)){ $where.=' AND status=?';$args[]=$status; }
        $before=max(0,(int)($_GET['before']??0)); if($before){$where.=' AND id<?';$args[]=$before;}
        // A typed search looks only at the fields a person would recognise on a receipt.
        $q=trim((string)($_GET['q']??''));
        if($q!==''){
            $like='%'.str_replace(['\\','%','_'],['\\\\','\%','\_'],substr($q,0,60)).'%';
            $where.=' AND (payer_name LIKE ? OR payer_msisdn LIKE ? OR reference LIKE ? OR trx_id LIKE ?)';
            array_push($args,$like,$like,$like,$like);
        }
        $rows=Db::rows("SELECT id,public_id,trx_id,amount,currency,reference,payer_name,payer_msisdn,provider,status,reversed,hold_reason,received_at FROM payments WHERE $where ORDER BY id DESC LIMIT 51",$args);
        $more=count($rows)>50; $rows=array_slice($rows,0,50);
        out(['payments'=>$rows,'next_before'=>$more?(int)end($rows)['id']:null]);
    }

    /**
     * Figures for the overview charts: one row per day, plus the split by network
     * and by state. Drawn on the page from these numbers, so no chart library and
     * no third-party request is involved.
     */
    if ($path === '/v1/portal/insights' && $method === 'GET') {
        $user = portalSession(); if (!$user) fail('unauthorized', 'Sign in to continue.', 401);
        $where = portalScope($user);
        $days = min(90, max(7, (int) ($_GET['days'] ?? 30)));
        $from = date('Y-m-d 00:00:00', time() - ($days - 1) * 86400);
        $daily = Db::rows("SELECT DATE(received_at) day, COUNT(*) count, COALESCE(SUM(amount),0) total,
                SUM(status='matched') matched, SUM(status<>'matched') pending
            FROM payments WHERE $where AND kind='credit' AND reversed=0 AND received_at >= ?
            GROUP BY DATE(received_at) ORDER BY day", [$from]);
        // Days without a payment still need a point, or the shape of the chart lies.
        $byDay = [];
        foreach ($daily as $row) $byDay[$row['day']] = $row;
        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = date('Y-m-d', time() - $i * 86400);
            $row = $byDay[$day] ?? null;
            $series[] = ['day' => $day, 'count' => (int) ($row['count'] ?? 0), 'total' => (float) ($row['total'] ?? 0),
                         'matched' => (int) ($row['matched'] ?? 0), 'pending' => (int) ($row['pending'] ?? 0)];
        }
        $providers = Db::rows("SELECT provider, COUNT(*) count, COALESCE(SUM(amount),0) total FROM payments
            WHERE $where AND kind='credit' AND reversed=0 AND received_at >= ? GROUP BY provider ORDER BY count DESC LIMIT 8", [$from]);
        foreach ($providers as $i => $row) $providers[$i]['label'] = Parser::providerLabel($row['provider']);
        $holds = Db::rows("SELECT hold_reason, COUNT(*) count FROM payments
            WHERE $where AND status <> 'matched' AND reversed=0 AND received_at >= ? GROUP BY hold_reason ORDER BY count DESC LIMIT 6", [$from]);
        $currency = Db::row("SELECT MAX(currency) currency FROM payments WHERE $where");
        out(['days' => $days, 'series' => $series, 'providers' => $providers, 'holds' => $holds,
             'currency' => $currency['currency'] ?: 'USD']);
    }

    // ---------------------------------------------- workspace: account and credentials
    /**
     * The merchant behind the signed-in session, or null for an owner, who has no
     * merchant of their own. Anything that issues a credential needs one.
     */
    $portalMerchant = static function (array $user) {
        // An owner looking at one merchant's workspace acts for that merchant.
        $id = $user['role'] === 'owner' ? $user['acting_merchant_id'] : $user['merchant_id'];
        if (!$id) return null;
        return Db::row("SELECT * FROM merchants WHERE id = ?", [(int) $id]);
    };
    /** Issuing or replacing a credential asks for the account password again. */
    $confirmPassword = static function (array $user, array $in) {
        $given = (string) ($in['password'] ?? '');
        if ($given === '' || !password_verify($given, $user['password_hash'])) {
            usleep(250000);
            fail('password_required', 'Enter your account password to confirm this change.', 403);
        }
    };

    if ($path === '/v1/portal/account' && $method === 'GET') {
        $user = portalSession(); if (!$user) fail('unauthorized', 'Sign in to continue.', 401);
        $merchant = $portalMerchant($user);
        $providers = [];
        if ($merchant) {
            foreach (array_keys(Parser::defaultSenders($merchant['dial_code'])) as $code) {
                $providers[] = ['code' => $code, 'label' => Parser::providerLabel($code)];
            }
            $providers[] = ['code' => 'other', 'label' => 'Another network (type its sender name)'];
        }
        // An owner may also run a merchant account of their own on this same sign-in.
        $own = ($user['role'] === 'owner' && $user['merchant_id'])
            ? Db::row("SELECT id, public_id, name FROM merchants WHERE id = ?", [(int) $user['merchant_id']]) : null;
        $acting = null;
        if ($user['role'] === 'owner' && $user['acting_merchant_id'] && $merchant) {
            $acting = ['id' => $merchant['public_id'], 'name' => $merchant['name'], 'own' => $own && (int) $own['id'] === (int) $merchant['id']];
        }
        out([
            'user' => ['email' => $user['email'], 'phone' => $user['phone'], 'role' => $user['role'],
                       'created_at' => $user['created_at'], 'last_login_at' => $user['last_login_at']],
            'acting_as' => $acting,
            'own_merchant' => $own ? ['id' => $own['public_id'], 'name' => $own['name']] : null,
            'merchant' => $merchant ? ['id' => $merchant['public_id'], 'name' => $merchant['name'], 'country' => $merchant['country'],
                'dial_code' => $merchant['dial_code'], 'currency' => $merchant['currency'], 'webhook_url' => (string) $merchant['webhook_url'],
                'created_at' => $merchant['created_at'], 'pay_to' => Gateway::payTo((int) $merchant['id'])] : null,
            'providers' => $providers,
        ]);
    }

    if ($path === '/v1/portal/password' && $method === 'POST') {
        $user = portalSession(); if (!$user) fail('unauthorized', 'Sign in to continue.', 401);
        $in = body(); requireTextFields($in, ['password', 'new_password']);
        $confirmPassword($user, $in);
        $new = (string) ($in['new_password'] ?? '');
        if (strlen($new) < 12 || strlen($new) > 72) fail('weak_password', 'Use a new password between 12 and 72 bytes.');
        if (hash_equals((string) $in['password'], $new)) fail('same_password', 'Choose a password you have not used here before.');
        // Every other session is signed out, so a stolen session cannot outlive the change.
        $token = $_COOKIE['isp_pay_session'] ?? '';
        Db::run("UPDATE portal_users SET password_hash = ? WHERE id = ?", [password_hash($new, PASSWORD_DEFAULT), (int) $user['id']]);
        Db::run("DELETE FROM portal_sessions WHERE user_id = ? AND token_hash <> ?", [(int) $user['id'], hash('sha256', $token)]);
        out(['ok' => true, 'other_sessions_ended' => true]);
    }

    /**
     * Platform owners have no merchant of their own, so they name the merchant they
     * are acting for. Everyone else may only ever act on their own.
     */
    $targetMerchant = static function (array $user, array $in) use ($portalMerchant) {
        if ($user['role'] !== 'owner') return $portalMerchant($user);
        $wanted = (string) ($in['merchant_id'] ?? '');
        // Named outright, else the merchant whose workspace the owner is looking at.
        if ($wanted === '') return $portalMerchant($user);
        return Db::row("SELECT * FROM merchants WHERE public_id = ?", [$wanted]);
    };

    /**
     * Looking at one merchant's workspace. This changes what the owner is shown and
     * what their actions default to; it grants nothing, since an owner may already
     * act on any merchant. It never signs them in as that merchant's own user, and
     * it lasts only for this browser session.
     */
    if ($path === '/v1/portal/view-as' && $method === 'POST') {
        $user = portalSession(); if (!$user) fail('unauthorized', 'Sign in to continue.', 401);
        if ($user['role'] !== 'owner') fail('forbidden', 'Only a platform owner can change whose workspace is shown.', 403);
        $in = body(); requireTextFields($in, ['merchant_id']);
        $wanted = trim((string) ($in['merchant_id'] ?? ''));
        if ($wanted === '') {
            Db::run("UPDATE portal_sessions SET acting_merchant_id = NULL WHERE id = ?", [(int) $user['session_id']]);
            rememberView('');
            out(['ok' => true, 'acting' => null]);
        }
        $merchant = Db::row("SELECT id, public_id, name FROM merchants WHERE public_id = ?", [$wanted]);
        if (!$merchant) fail('not_found', 'That merchant was not found.', 404);
        Db::run("UPDATE portal_sessions SET acting_merchant_id = ? WHERE id = ?", [(int) $merchant['id'], (int) $user['session_id']]);
        rememberView($merchant['public_id']);
        out(['ok' => true, 'acting' => ['id' => $merchant['public_id'], 'name' => $merchant['name']]]);
    }

    /** Which networks are built in for one merchant's dialling code. */
    if ($path === '/v1/portal/senders' && $method === 'GET') {
        $user = portalSession(); if (!$user) fail('unauthorized', 'Sign in to continue.', 401);
        $merchant = $user['role'] === 'owner'
            ? Db::row("SELECT * FROM merchants WHERE public_id = ?", [(string) ($_GET['merchant_id'] ?? '')])
            : $portalMerchant($user);
        if (!$merchant) fail('no_merchant', 'Choose a merchant to see its networks.', 409);
        $providers = [];
        foreach (array_keys(Parser::defaultSenders($merchant['dial_code'])) as $code) {
            $providers[] = ['code' => $code, 'label' => Parser::providerLabel($code)];
        }
        $providers[] = ['code' => 'other', 'label' => 'Another network (type its sender name)'];
        out(['providers' => $providers]);
    }

    if ($path === '/v1/portal/merchants' && $method === 'GET') {
        $user = portalSession(); if (!$user) fail('unauthorized', 'Sign in to continue.', 401);
        if ($user['role'] !== 'owner') fail('forbidden', 'Only a platform owner can list merchants.', 403);
        out(['merchants' => Db::rows("SELECT m.public_id id, m.name, m.country, m.dial_code, m.currency, m.webhook_url, m.created_at,
                (SELECT COUNT(*) FROM devices d WHERE d.merchant_id = m.id) devices,
                (SELECT COUNT(*) FROM api_keys k WHERE k.merchant_id = m.id AND k.status = 'active') keys_active,
                (SELECT COUNT(*) FROM payments p WHERE p.merchant_id = m.id) payments
            FROM merchants m ORDER BY m.id DESC LIMIT 200")]);
    }

    /**
     * Creating a merchant from inside the workspace. The webhook still has to answer
     * the challenge, so a merchant can never be pointed at an address its operator
     * does not control; this only removes the need to do it before having an account.
     */
    if ($path === '/v1/portal/merchants' && $method === 'POST') {
        $user = portalSession(); if (!$user) fail('unauthorized', 'Sign in to continue.', 401);
        if ($user['role'] !== 'owner') fail('forbidden', 'Only a platform owner can add a merchant here.', 403);
        $in = body(); requireTextFields($in, ['name', 'country', 'dial_code', 'currency', 'webhook_url', 'password']);
        $confirmPassword($user, $in);
        $name = trim((string) ($in['name'] ?? ''));
        $dial = preg_replace('/\D+/', '', (string) ($in['dial_code'] ?? ''));
        $url = trim((string) ($in['webhook_url'] ?? ''));
        if ($name === '' || $dial === '' || !filter_var($url, FILTER_VALIDATE_URL)) fail('bad_request', 'A business name, dialling code and webhook address are all required.');
        if (!preg_match('/^[A-Za-z]{3}$/', (string) ($in['currency'] ?? ''))) fail('bad_currency', 'currency must be a three-letter currency code.');
        if (in_array($dial, (array) Config::get('blocked_dial_codes', []), true)) fail('country_not_supported', 'Direct Number is not offered in this country.', 403);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'https' && !($scheme === 'http' && Config::get('allow_insecure_webhooks', false))) fail('https_required', 'The webhook address must use https.');
        try {
            WebhookTarget::resolve($url, (bool) Config::get('allow_insecure_webhooks', false));
        } catch (InvalidArgumentException $e) {
            fail('bad_webhook_host', 'Use a publicly reachable webhook address without URL credentials or fragments.');
        }
        if (Db::row("SELECT id FROM merchants WHERE webhook_url = ?", [$url])) fail('merchant_exists', 'A merchant already delivers to that address.', 409);
        $challenge = bin2hex(random_bytes(16));
        $res = Gateway::httpPost($url, json_encode(['type' => 'challenge', 'challenge' => $challenge, 'nonce' => '']), ['Content-Type: application/json', 'X-Gateway-Event: challenge'], 10);
        $echo = json_decode($res['body'], true);
        if ($res['code'] !== 200 || !is_array($echo) || !is_string($echo['challenge'] ?? null) || !hash_equals($challenge, $echo['challenge'])) {
            fail('challenge_failed', 'That address did not answer the verification request. Check it is live, then try again.', 422);
        }
        $made = Gateway::createMerchant($name, (string) ($in['country'] ?? ''), $dial, (string) ($in['currency'] ?? ''), $url);
        out(['merchant_id' => $made['merchant']['public_id'], 'api_key' => $made['api_key'], 'webhook_secret' => $made['webhook_secret']], 201);
    }

    /**
     * The owner's own merchant account, on the same sign-in. It is created without a
     * webhook, because the point is to have somewhere to set one up: the address is
     * added afterwards under API keys and webhooks, where it still has to answer the
     * challenge before it is saved. Until then payments are recorded and their
     * events wait. The session moves straight into the new account.
     */
    if ($path === '/v1/portal/my-merchant' && $method === 'POST') {
        $user = portalSession(); if (!$user) fail('unauthorized', 'Sign in to continue.', 401);
        if ($user['role'] !== 'owner') fail('forbidden', 'This is for a platform owner adding a merchant account of their own.', 403);
        if ($user['merchant_id']) fail('already_set_up', 'This sign-in already has a merchant account of its own.', 409);
        // No password is asked for here: nothing secret is issued or shown. The first
        // key is made afterwards in the workspace, and that is where it is asked for.
        $in = body(); requireTextFields($in, ['name', 'country', 'dial_code', 'currency']);
        $name = trim((string) ($in['name'] ?? ''));
        $dial = preg_replace('/\D+/', '', (string) ($in['dial_code'] ?? ''));
        if ($name === '' || $dial === '') fail('bad_request', 'A business name and dialling code are required.');
        if (!preg_match('/^[A-Za-z]{3}$/', (string) ($in['currency'] ?? ''))) fail('bad_currency', 'currency must be a three-letter currency code.');
        if (in_array($dial, (array) Config::get('blocked_dial_codes', []), true)) fail('country_not_supported', 'Direct Number is not offered in this country.', 403);
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $made = Gateway::createMerchant($name, (string) ($in['country'] ?? ''), $dial, (string) ($in['currency'] ?? ''), '', false);
            $linked = Db::run("UPDATE portal_users SET merchant_id = ? WHERE id = ? AND merchant_id IS NULL", [(int) $made['merchant']['id'], (int) $user['id']]);
            if ($linked !== 1) { $pdo->rollBack(); fail('account_changed', 'This sign-in changed while the account was being created. Refresh and try again.', 409); }
            Db::run("UPDATE portal_sessions SET acting_merchant_id = ? WHERE id = ?", [(int) $made['merchant']['id'], (int) $user['session_id']]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        rememberView($made['merchant']['public_id']);
        out(['ok' => true, 'merchant_id' => $made['merchant']['public_id'], 'name' => $made['merchant']['name'], 'next' => '/dashboard'], 201);
    }

    if ($path === '/v1/portal/keys' && $method === 'POST') {
        $user = portalSession(); if (!$user) fail('unauthorized', 'Sign in to continue.', 401);
        $in = body(); requireTextFields($in, ['password', 'merchant_id']);
        $merchant = $targetMerchant($user, $in);
        if (!$merchant) fail('no_merchant', $user['role'] === 'owner' ? 'Choose the merchant this key is for.' : 'Live API keys belong to a merchant account. Complete live registration first.', 409);
        $confirmPassword($user, $in);
        $active = Db::row("SELECT COUNT(*) c FROM api_keys WHERE merchant_id = ? AND status = 'active'", [(int) $merchant['id']]);
        if ((int) $active['c'] >= 5) fail('too_many_keys', 'You already have five active keys. Revoke one before creating another.', 409);
        $key = Gateway::issueApiKey((int) $merchant['id']);
        // Shown once. Only the hash is stored, so it cannot be retrieved later.
        out(['api_key' => $key, 'hint' => substr($key, 0, 12)], 201);
    }

    if ($path === '/v1/portal/keys/revoke' && $method === 'POST') {
        $user = portalSession(); if (!$user) fail('unauthorized', 'Sign in to continue.', 401);
        $in = body(); requireTextFields($in, ['hint', 'password', 'merchant_id']);
        $merchant = $targetMerchant($user, $in);
        if (!$merchant) fail('no_merchant', 'This account has no live API keys.', 409);
        $confirmPassword($user, $in);
        $n = Db::run("UPDATE api_keys SET status = 'revoked' WHERE merchant_id = ? AND hint = ? AND status = 'active'", [(int) $merchant['id'], (string) ($in['hint'] ?? '')]);
        $n ? out(['ok' => true]) : fail('not_found', 'That key is not active on this account.', 404);
    }

    if ($path === '/v1/portal/devices' && $method === 'POST') {
        $user = portalSession(); if (!$user) fail('unauthorized', 'Sign in to continue.', 401);
        $in = body();
        $merchant = $targetMerchant($user, $in);
        if (!$merchant) fail('no_merchant', $user['role'] === 'owner' ? 'Choose the merchant this listener belongs to.' : 'Listener devices belong to a merchant account. Complete live registration first.', 409);
        reply(Gateway::createDevice($merchant, $in), 201);
    }

    if ($path === '/v1/portal/devices/rotate' && $method === 'POST') {
        $user = portalSession(); if (!$user) fail('unauthorized', 'Sign in to continue.', 401);
        $in = body(); requireTextFields($in, ['device_id', 'password', 'merchant_id']);
        $merchant = $targetMerchant($user, $in);
        if (!$merchant) fail('no_merchant', 'This account has no listener devices.', 409);
        $confirmPassword($user, $in);
        $r = Gateway::rotateDeviceKey($merchant, (string) ($in['device_id'] ?? ''));
        $r ? out($r) : fail('not_found', 'Device not found.', 404);
    }

    if ($path === '/v1/portal/devices/revoke' && $method === 'POST') {
        $user = portalSession(); if (!$user) fail('unauthorized', 'Sign in to continue.', 401);
        $in = body(); requireTextFields($in, ['device_id', 'password', 'merchant_id']);
        $merchant = $targetMerchant($user, $in);
        if (!$merchant) fail('no_merchant', 'This account has no listener devices.', 409);
        $confirmPassword($user, $in);
        Gateway::revokeDevice($merchant, (string) ($in['device_id'] ?? '')) ? out(['ok' => true]) : fail('not_found', 'Device not found.', 404);
    }

    /**
     * Changing where events are delivered. The new address has to answer the same
     * challenge as at registration, so events can never be pointed at an address
     * that is not under the merchant's control. The signing secret is left alone;
     * rotating it is a separate, deliberate step below.
     */
    if ($path === '/v1/portal/webhook' && $method === 'POST') {
        $user = portalSession(); if (!$user) fail('unauthorized', 'Sign in to continue.', 401);
        // No password here. Changing the address reveals nothing: the signing secret
        // stays where it is, and an address that cannot verify signatures gains
        // nothing from receiving events. Keys and the secret still ask for it.
        $in = body(); requireTextFields($in, ['webhook_url', 'merchant_id']);
        $merchant = $targetMerchant($user, $in);
        if (!$merchant) fail('no_merchant', 'Webhook delivery is configured on a merchant account.', 409);
        $url = trim((string) ($in['webhook_url'] ?? ''));
        if (!filter_var($url, FILTER_VALIDATE_URL)) fail('bad_request', 'Enter the full https address that should receive events.');
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'https' && !($scheme === 'http' && Config::get('allow_insecure_webhooks', false))) {
            fail('https_required', 'The webhook address must use https.');
        }
        try {
            WebhookTarget::resolve($url, (bool) Config::get('allow_insecure_webhooks', false));
        } catch (InvalidArgumentException $e) {
            fail('bad_webhook_host', 'Use a publicly reachable webhook address without URL credentials or fragments.');
        }
        $taken = Db::row("SELECT id FROM merchants WHERE webhook_url = ? AND id <> ?", [$url, (int) $merchant['id']]);
        if ($taken) fail('webhook_in_use', 'Another merchant account already delivers to that address.', 409);
        $ip = client_ip();
        $recent = Db::row("SELECT COUNT(*) c FROM signups WHERE ip = ? AND created_at > ?", [$ip, date('Y-m-d H:i:s', time() - 3600)]);
        if ((int) $recent['c'] >= 20) fail('too_many_attempts', 'Too many attempts. Please try again later.', 429);
        Db::run("INSERT INTO signups (ip, created_at) VALUES (?, ?)", [$ip, date('Y-m-d H:i:s')]);
        $challenge = bin2hex(random_bytes(16));
        $res = Gateway::httpPost($url, json_encode(['type' => 'challenge', 'challenge' => $challenge, 'nonce' => '']), ['Content-Type: application/json', 'X-Gateway-Event: challenge'], 10);
        $echo = json_decode($res['body'], true);
        $echoed = $res['code'] === 200 && is_array($echo) && is_string($echo['challenge'] ?? null) && hash_equals($challenge, $echo['challenge']);
        // Echoing the challenge is the full proof. An address that simply accepts the
        // request with a 2xx is taken as reachable and saved too: this is the
        // merchant's own account, and nothing is issued by saving it. What is refused
        // is an address that cannot be reached or that turns the request away, and the
        // reason given is what it actually answered, not a guess.
        $accepted = $res['code'] >= 200 && $res['code'] < 300;
        if (!$echoed && !$accepted) {
            $code = (int) $res['code'];
            $why = $code === 0 ? 'It could not be reached at all: check the address, its certificate, and that it is open to the internet.'
                : ($code === 401 || $code === 403 ? 'It answered HTTP ' . $code . ', which means it is there but refused the request. An ISP Ledger billing panel refuses until it is linked from its own side: in the panel open Payment Gateway, Direct Number, and link this account with your API key. That sets this address for you.'
                : 'It answered HTTP ' . $code . ' instead of 200.');
            fail('challenge_failed', 'That address did not verify. ' . $why, 422);
        }
        Db::run("UPDATE merchants SET webhook_url = ? WHERE id = ?", [$url, (int) $merchant['id']]);
        out(['ok' => true, 'webhook_url' => $url, 'verified' => $echoed ? 'challenge' : 'reachable']);
    }

    /** A new signing secret, shown once. Existing deliveries in flight keep the old one. */
    if ($path === '/v1/portal/webhook/secret' && $method === 'POST') {
        $user = portalSession(); if (!$user) fail('unauthorized', 'Sign in to continue.', 401);
        $in = body(); requireTextFields($in, ['password', 'merchant_id']);
        $merchant = $targetMerchant($user, $in);
        if (!$merchant) fail('no_merchant', 'Webhook signing is configured on a merchant account.', 409);
        $confirmPassword($user, $in);
        $secret = 'whsec_' . bin2hex(random_bytes(24));
        Db::run("UPDATE merchants SET webhook_secret = ? WHERE id = ?", [$secret, (int) $merchant['id']]);
        out(['webhook_secret' => $secret]);
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
        // The phone tells us the senders it is watching, so the two lists cannot drift
        // apart and quietly drop a payment. It grants nothing: this device already
        // states the sender of every message it reports.
        if (isset($in['senders']) && is_string($in['senders']) && trim($in['senders']) !== '' && $device['provider'] === 'other') {
            $list = substr(trim($in['senders']), 0, 255);
            if ($list !== $device['extra_senders']) {
                Db::run("UPDATE devices SET extra_senders = ? WHERE id = ?", [$list, (int) $device['id']]);
                $device['extra_senders'] = $list;
            }
        }
        $text = (string) ($in['text'] ?? '');
        if ($text === '') {
            out(['ok' => true, 'result' => 'pong']);
        }
        // Android sends milliseconds. An implausible time is dropped, not stored wrong.
        if (isset($in['sentStamp']) && !is_numeric($in['sentStamp'])) fail('bad_request', 'sentStamp must be numeric.');
        $sent = (float) ($in['sentStamp'] ?? 0);
        $sent = $sent > 9999999999 ? $sent / 1000 : $sent;
        $sent = ($sent > 1500000000 && $sent < time() + 86400) ? (int) $sent : null;
        $result = Gateway::ingest($device, trim((string) ($in['from'] ?? '')), $text, $sent);
        // Recorded or not, the message is settled and the phone should stop holding it.
        // ok says that; result and reason say what actually became of it.
        $reasons = [
            'unknown_sender' => 'This listener is not set up to accept that sender name. Add it on the phone, or in Listener devices.',
            'not_a_payment'  => 'That message was not a payment confirmation this service could read.',
            'ignored'        => 'That message could not be read.',
        ];
        $reply = ['ok' => true, 'result' => $result];
        if (isset($reasons[$result])) $reply['reason'] = $reasons[$result];
        out($reply);
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

    /**
     * A billing panel joining an account that already exists, using that account's
     * API key. The panel names its own webhook and a nonce it has just made; the
     * gateway calls the webhook back with that nonce, which the panel answers only
     * because it started this. The address is then saved and a fresh signing secret
     * is returned, so the owner never types an address or copies a secret by hand.
     *
     * The echo is required here, unlike a save from the workspace, because this call
     * hands out the signing secret.
     */
    if ($path === '/v1/link' && $method === 'POST') {
        $in = body(); requireTextFields($in, ['webhook_url', 'nonce']);
        $url = trim((string) ($in['webhook_url'] ?? ''));
        if (!filter_var($url, FILTER_VALIDATE_URL)) fail('bad_request', 'webhook_url is required.');
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'https' && !($scheme === 'http' && Config::get('allow_insecure_webhooks', false))) fail('https_required', 'The webhook address must use https.');
        try {
            WebhookTarget::resolve($url, (bool) Config::get('allow_insecure_webhooks', false));
        } catch (InvalidArgumentException $e) {
            fail('bad_webhook_host', 'Use a publicly reachable webhook address without URL credentials or fragments.');
        }
        if (Db::row("SELECT id FROM merchants WHERE webhook_url = ? AND id <> ?", [$url, (int) $m['id']])) {
            fail('webhook_in_use', 'Another merchant account already delivers to that address.', 409);
        }
        $challenge = bin2hex(random_bytes(16));
        $res = Gateway::httpPost($url, json_encode(['type' => 'challenge', 'challenge' => $challenge, 'nonce' => substr((string) ($in['nonce'] ?? ''), 0, 64)]), ['Content-Type: application/json', 'X-Gateway-Event: challenge'], 10);
        $echo = json_decode($res['body'], true);
        if ($res['code'] !== 200 || !is_array($echo) || !is_string($echo['challenge'] ?? null) || !hash_equals($challenge, $echo['challenge'])) {
            fail('challenge_failed', 'The billing panel did not answer the verification request (HTTP ' . (int) $res['code'] . ').', 422);
        }
        $secret = 'whsec_' . bin2hex(random_bytes(24));
        Db::run("UPDATE merchants SET webhook_url = ?, webhook_secret = ? WHERE id = ?", [$url, $secret, (int) $m['id']]);
        out(['merchant_id' => $m['public_id'], 'name' => $m['name'], 'country' => $m['country'], 'dial_code' => $m['dial_code'],
             'currency' => $m['currency'], 'webhook_url' => $url, 'webhook_secret' => $secret]);
    }

    if ($path === '/v1/me' && $method === 'GET') {
        $providers = [];
        foreach (Parser::defaultSenders($m['dial_code']) as $code => $names) {
            $providers[] = ['code' => $code, 'label' => Parser::providerLabel($code)];
        }
        out(['id' => $m['public_id'], 'name' => $m['name'], 'country' => $m['country'], 'dial_code' => $m['dial_code'], 'currency' => $m['currency'],
             'webhook_url' => (string) $m['webhook_url'], 'providers' => array_merge($providers, [['code' => 'other', 'label' => 'Another network (type its sender name below)']]), 'pay_to' => Gateway::payTo($m['id'])]);
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
