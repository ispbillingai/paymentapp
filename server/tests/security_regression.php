<?php
/** Isolated regressions: in-memory SQLite only, no config or network access. */
date_default_timezone_set('UTC');
require dirname(__DIR__) . '/src/Parser.php';
require dirname(__DIR__) . '/src/Gateway.php';

class Config { public static function get($key, $default = null) { return $default; } }
class Db
{
    private static $connection;
    public static $failEvent = false;
    public static function pdo()
    {
        if (!self::$connection) self::$connection = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        return self::$connection;
    }
    private static function statement($sql, $args)
    {
        if (self::$failEvent && strpos($sql, 'INSERT INTO webhook_deliveries') !== false) throw new RuntimeException('Injected outbox failure');
        $sql = str_replace(' FOR UPDATE', '', $sql);
        $sql = str_replace('ON DUPLICATE KEY UPDATE last_name = VALUES(last_name), last_seen = VALUES(last_seen)', 'ON CONFLICT(merchant_id, payer_key, reference) DO UPDATE SET last_name = excluded.last_name, last_seen = excluded.last_seen', $sql);
        $statement = self::pdo()->prepare($sql);
        $statement->execute($args);
        return $statement;
    }
    public static function row($sql, array $args = []) { return self::statement($sql, $args)->fetch() ?: null; }
    public static function rows($sql, array $args = []) { return self::statement($sql, $args)->fetchAll(); }
    public static function run($sql, array $args = []) { return self::statement($sql, $args)->rowCount(); }
    public static function lastId() { return (int) self::pdo()->lastInsertId(); }
}

$checks = 0;
function check($condition, $message) {
    global $checks;
    $checks++;
    if (!$condition) throw new RuntimeException($message);
}
function rejects($url, array $addresses = []) {
    try { WebhookTarget::resolve($url, false, function () use ($addresses) { return $addresses; }); }
    catch (InvalidArgumentException $e) { return true; }
    return false;
}
foreach (['http://example.com/hook', 'file:///etc/passwd', 'https://user:pass@example.com/hook', 'https://example.com/#secret',
    'https://127.0.0.1/hook', 'https://10.1.2.3/hook', 'https://169.254.169.254/latest/meta-data', 'https://100.64.1.2/',
    'https://192.0.2.1/', 'https://224.0.0.1/', 'https://[::1]/', 'https://[::ffff:127.0.0.1]/', 'https://[fe80::1]/', 'https://[2001:db8::1]/'] as $url) {
    check(rejects($url), 'Unsafe webhook accepted: ' . $url);
}
check(rejects('https://merchant.example/hook', ['8.8.8.8', '10.0.0.1']), 'Mixed public/private DNS must fail closed.');
check(rejects('https://merchant.example/hook', []), 'Empty DNS must fail closed.');
$target = WebhookTarget::resolve('https://merchant.example:8443/hook', false, function () { return ['8.8.8.8']; });
check($target['host'] === 'merchant.example' && $target['port'] === 8443 && $target['address'] === '8.8.8.8', 'Validated target must preserve TLS host and pinned address.');
check(WebhookTarget::isPublicAddress('2606:4700:4700::1111'), 'Ordinary public IPv6 should be allowed.');
check(WebhookTarget::resolve('http://127.0.0.1/hook', true)['address'] === '127.0.0.1', 'Explicit local testing mode should work.');

Db::pdo()->exec("CREATE TABLE merchants (id INTEGER PRIMARY KEY, webhook_url TEXT, webhook_secret TEXT);
    INSERT INTO merchants VALUES (1, '', 'test-only');
    CREATE TABLE intents (id INTEGER PRIMARY KEY, public_id TEXT, merchant_id INTEGER, payer_key TEXT, amount NUMERIC, currency TEXT,
        payer_name TEXT, payer_msisdn TEXT, reference TEXT, metadata TEXT, status TEXT, payment_id INTEGER DEFAULT 0, created_at TEXT, expires_at TEXT);
    CREATE TABLE payments (id INTEGER PRIMARY KEY, public_id TEXT, merchant_id INTEGER, payer_key TEXT, amount NUMERIC, currency TEXT,
        payer_name TEXT, payer_msisdn TEXT, reference TEXT DEFAULT '', status TEXT DEFAULT 'unmatched', kind TEXT DEFAULT 'credit',
        reversed INTEGER DEFAULT 0, hold_reason TEXT DEFAULT '', match_rule TEXT DEFAULT '', name_check TEXT DEFAULT '', intent_id INTEGER DEFAULT 0,
        matched_at TEXT, received_at TEXT, trx_id TEXT COLLATE NOCASE, source TEXT DEFAULT 'direct_number', provider TEXT DEFAULT 'mtn_ug', raw_message TEXT DEFAULT '',
        device_id INTEGER DEFAULT 0, receiving_key TEXT DEFAULT '', sender TEXT DEFAULT '', parser TEXT DEFAULT '', sms_time TEXT, reversed_at TEXT,
        UNIQUE(merchant_id, provider, receiving_key, trx_id));
    CREATE TABLE payers (merchant_id INTEGER, payer_key TEXT, reference TEXT, last_name TEXT, last_seen TEXT, UNIQUE(merchant_id, payer_key, reference));
    CREATE TABLE claims (merchant_id INTEGER, ip TEXT, trx_id TEXT, ok INTEGER, created_at TEXT);
    CREATE TABLE webhook_deliveries (id INTEGER PRIMARY KEY, event_id TEXT, merchant_id INTEGER, type TEXT, payload TEXT,
        status TEXT, attempts INTEGER, next_attempt_at TEXT, created_at TEXT, last_code INTEGER);");
function resetData() {
    foreach (['intents', 'payments', 'payers', 'claims', 'webhook_deliveries'] as $table) Db::run('DELETE FROM ' . $table);
}
function intent($id, $phone = '772123456', $reference = 'customer-a', $currency = 'UGX') {
    Db::run("INSERT INTO intents (id, public_id, merchant_id, payer_key, amount, currency, payer_name, payer_msisdn, reference, metadata, status, created_at, expires_at) VALUES (?, ?, 1, ?, 1000, ?, 'Alice', ?, ?, '{}', 'waiting', ?, ?)", [$id, 'pi_' . $id, $phone, $currency, $phone, $reference, date('Y-m-d H:i:s'), date('Y-m-d H:i:s', time() + 3600)]);
}
function payment($id, $phone = '772123456', $currency = 'UGX', $trx = null) {
    Db::run("INSERT INTO payments (id, public_id, merchant_id, payer_key, amount, currency, payer_name, payer_msisdn, received_at, trx_id, receiving_key) VALUES (?, ?, 1, ?, 1000, ?, 'Alice', ?, ?, ?, ?)", [$id, 'pay_' . $id, $phone, $currency, $phone, date('Y-m-d H:i:s'), $trx ?: 'TXN000' . $id, 'receiver_' . $id]);
}
$merchant = ['id' => 1, 'dial_code' => '256', 'currency' => 'UGX'];
foreach ([0, -1, '1.001', 'Infinity', 1e20, [], true] as $amount) {
    check((Gateway::createIntent($merchant, ['amount' => $amount])['error']['code'] ?? '') === 'bad_amount', 'Invalid amount accepted.');
}
check((Gateway::createIntent($merchant, ['amount' => 1000, 'payer_phone' => []])['error']['code'] ?? '') === 'bad_request', 'Structured payer phone must be rejected cleanly.');
check((Gateway::createDevice($merchant, ['provider' => []])['error']['code'] ?? '') === 'bad_request', 'Structured device provider must be rejected cleanly.');
check((Gateway::createDevice(['id' => 1, 'dial_code' => '244'], ['provider' => 'other', 'receiving_number' => '923123456', 'receiving_name' => 'Test'])['error']['code'] ?? '') === 'sender_required', 'Custom provider must require an explicit sender name.');
check(Parser::providerLabel('other') === 'Mobile money', 'Custom provider must retain its public label.');
check(Parser::providerForSender('CustomCash', '244', ['other' => ['CustomCash']]) === 'other', 'Configured custom sender should be recognised.');
check(Parser::providerForSender('UnknownSender', '244', ['other' => ['CustomCash']]) === '', 'Unconfigured custom sender must be ignored.');
$customReceipt = 'You have received AOA 1000 from Alice 923123456. Transaction ID CUSTOM001';
$parsedCustom = Parser::parseMessage('other', $customReceipt, 'AOA');
check($parsedCustom['kind'] === 'credit' && $parsedCustom['currency'] === 'AOA' && $parsedCustom['amount'] === 1000.0, 'Configured currency should parse in a recognised receipt format.');
check(Parser::parseMessage('other', $customReceipt)['kind'] === 'unread', 'Unconfigured currency must not be guessed.');
check(Parser::parseMessage('other', 'Recebeu AOA 1000 de Alice 923123456. Transaction ID CUSTOM001', 'AOA')['kind'] === 'other', 'Configured currency must not pretend to add an unsupported language parser.');
check(Parser::parseMessage('other', $customReceipt, 'AOA|.*')['kind'] === 'unread', 'Currency configuration must not inject a parser expression.');
$oversizedReceipt = Parser::parseMessage('mtn_ug', 'You have received UGX ' . str_repeat('9', 400) . ' from Alice 0772123456. Transaction ID TXN00001');
check($oversizedReceipt['kind'] === 'unread' && $oversizedReceipt['amount'] === 0.0, 'Unrepresentable receipt amount must be held for review.');
intent(1); payment(1, '775999999');
check(!Gateway::matchPayment(1), 'Equal amount must not match a different payer number.');
check(Db::row('SELECT status FROM intents WHERE id = 1')['status'] === 'waiting', 'Unrelated payer changed intent.');
resetData(); intent(1); payment(1, '772123456', 'GHS');
check(!Gateway::matchPayment(1), 'Equal amount and number must not cross currencies.');
resetData(); intent(1); intent(2, '772123456', 'customer-b'); payment(1);
check(!Gateway::matchPayment(1), 'Newest competing reference must not win automatically.');
check(Db::row('SELECT hold_reason FROM payments WHERE id = 1')['hold_reason'] === 'ambiguous_intents', 'Ambiguity must be reviewable.');
resetData(); intent(1); payment(1); payment(2);
check(Gateway::matchPayment(1), 'Valid receipt should match.');
check(!Gateway::matchPayment(2), 'Second receipt must not replace a paid intent.');
check(Db::row('SELECT payment_id FROM intents WHERE id = 1')['payment_id'] == 1, 'Original receipt link must remain.');
check(Db::row('SELECT COUNT(*) AS c FROM webhook_deliveries')['c'] == 1, 'Matching should create exactly one event.');
check(!Gateway::matchPayment(1), 'Retrying a matched payment must not emit another credit.');
$claimed = Gateway::claim($merchant, 'pi_1', 'TXN0001', '127.0.0.1');
check(($claimed['intent']['status'] ?? '') === 'paid', 'Same claim should return original paid intent.');
$claim2 = Gateway::claim($merchant, 'pi_1', 'TXN0002', '127.0.0.1');
check(($claim2['error']['code'] ?? '') === 'intent_not_available', 'Claim must not overwrite a paid purchase.');
resetData(); intent(1); payment(1, '');
$claimed = Gateway::claim($merchant, 'pi_1', 'TXN0001', '127.0.0.1');
check(($claimed['error']['code'] ?? '') === 'not_yours', 'Name alone must not establish claim ownership.');
resetData(); intent(1); payment(1, '772123456', 'UGX', 'SHARED01'); payment(2, '772123456', 'UGX', 'SHARED01');
$claimed = Gateway::claim($merchant, 'pi_1', 'SHARED01', '127.0.0.1');
check(($claimed['error']['code'] ?? '') === 'needs_review', 'Ambiguous transaction ID must not pick a receipt.');
resetData(); intent(1); payment(1); Db::$failEvent = true;
try { Gateway::matchPayment(1); check(false, 'Expected injected failure.'); }
catch (RuntimeException $e) { check($e->getMessage() === 'Injected outbox failure', 'Unexpected failure.'); }
Db::$failEvent = false;
check(Db::row('SELECT status FROM intents WHERE id = 1')['status'] === 'waiting', 'Failed outbox insert must roll back intent.');
check(Db::row('SELECT status FROM payments WHERE id = 1')['status'] === 'unmatched', 'Failed outbox insert must roll back payment.');
check(Gateway::matchPayment(1), 'Retry should recover after transaction rollback.');
resetData(); intent(1); payment(1); Db::run('UPDATE payments SET reversed = 1 WHERE id = 1');
check(!Gateway::matchPayment(1), 'A reversed receipt must never activate.');
resetData(); intent(1); payment(1); Db::run("UPDATE intents SET expires_at = '2020-01-01 00:00:00'");
check((Gateway::claim($merchant, 'pi_1', 'TXN0001', '127.0.0.1')['error']['code'] ?? '') === 'intent_not_available', 'Expired purchase must not activate through claim.');

resetData(); intent(1);
$listener = ['id' => 1, 'merchant_id' => 1, 'provider' => 'mtn_ug', 'extra_senders' => '', 'dial_code' => '256', 'receiving_key' => 'receiver', 'merchant_currency' => 'UGX'];
check(Gateway::ingest($listener, 'MTNMoMo', 'Transaction ID REV00001 has been reversed.') === 'reversal', 'An early reversal must be retained.');
check(Db::row('SELECT kind, reversed FROM payments')['kind'] === 'reversal', 'Early reversal must remain visibly distinct from a credit.');
check(Db::row('SELECT COUNT(*) AS c FROM webhook_deliveries')['c'] == 0, 'Early reversal must not invent a credit amount or emit activation.');
$originalCredit = 'You have received UGX 1000 from Alice 0772123456. Transaction ID REV00001';
check(Gateway::ingest($listener, 'MTNMoMo', $originalCredit) === 'duplicate', 'Original receipt must reuse the reserved transaction identity.');
$reversedReceipt = Db::row('SELECT * FROM payments');
check($reversedReceipt['kind'] === 'credit' && (int) $reversedReceipt['reversed'] === 1 && (float) $reversedReceipt['amount'] === 1000.0, 'Original amount must enrich the receipt without removing reversal.');
check(Db::row('SELECT status FROM intents WHERE id = 1')['status'] === 'waiting', 'Credit arriving after its reversal must not activate.');
check(Db::row('SELECT type FROM webhook_deliveries')['type'] === 'payment.reversed', 'Completed reversed receipt must notify the merchant.');
Gateway::ingest($listener, 'MTNMoMo', $originalCredit);
Gateway::ingest($listener, 'MTNMoMo', 'Transaction ID REV00001 has been reversed.');
check(Db::row('SELECT COUNT(*) AS c FROM webhook_deliveries')['c'] == 1, 'Repeated credit/reversal delivery must not duplicate the reversal event.');

echo "PASS: $checks isolated security regression checks. No live database or network used.\n";
