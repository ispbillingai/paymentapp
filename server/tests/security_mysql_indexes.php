<?php
/** Optional real-engine regression. Requires an EMPTY gateway_test_* database. */
if (PHP_SAPI !== 'cli') exit;
$config = getenv('GATEWAY_INDEX_TEST_CONFIG');
if (!$config || !is_file($config)) {
    fwrite(STDERR, "Set GATEWAY_INDEX_TEST_CONFIG to an isolated test database config. This test never uses the default gateway database.\n");
    exit(2);
}
putenv('GATEWAY_CONFIG=' . $config);
require dirname(__DIR__) . '/src/Db.php';
$settings = Config::get('db');
if (!preg_match('/^gateway_test_[a-z0-9_]+$/', $settings['name'])) throw new RuntimeException('Test database name must start with gateway_test_.');
$pdo = Db::pdo(false);
if (!in_array('--verify-only', $argv, true)) {
if ($pdo->query('SHOW TABLES')->fetch()) throw new RuntimeException('Refusing to modify a non-empty test database.');
$pdo->exec('SET SESSION lock_wait_timeout = 5');
foreach (array_filter(array_map('trim', explode(";\n", file_get_contents(dirname(__DIR__) . '/schema.sql')))) as $statement) $pdo->exec($statement);
if (GatewayIndexes::inspect($pdo)) throw new RuntimeException('New schema must already contain all required indexes.');

// Recreate the original index layout, only inside the fresh test database.
$legacy = [
    'api_keys' => 'DROP INDEX idx_key_merchant, ADD INDEX idx_key_merchant (merchant_id)',
    'devices' => 'DROP INDEX idx_dev_active',
    'intents' => 'DROP INDEX idx_int_match, ADD INDEX idx_int_match (merchant_id, payer_key, status, expires_at), DROP INDEX idx_int_reuse',
    'payments' => 'DROP INDEX idx_pay_feed, DROP INDEX idx_pay_payer, ADD INDEX idx_pay_payer (merchant_id, payer_key), DROP INDEX idx_pay_trxid, ADD INDEX idx_pay_trxid (merchant_id, trx_id)',
    'payers' => 'DROP INDEX idx_payer_recent',
    'claims' => 'DROP INDEX idx_claim_ip, ADD INDEX idx_claim_ip (merchant_id, ip, created_at)',
    'webhook_deliveries' => 'DROP INDEX idx_wh_due, ADD INDEX idx_wh_due (status, next_attempt_at)',
];
foreach ($legacy as $table => $definition) $pdo->exec('ALTER TABLE `' . $table . '` ' . $definition);

$payment = $pdo->prepare("INSERT INTO payments (public_id, merchant_id, provider, receiving_key, trx_id, amount, currency, payer_key, status, received_at) VALUES (?, ?, 'mtn_ug', 'receiver', ?, 1000, 'UGX', ?, ?, NOW())");
$intent = $pdo->prepare("INSERT INTO intents (public_id, merchant_id, amount, currency, payer_key, reference, status, created_at, expires_at) VALUES (?, ?, 1000, 'UGX', ?, ?, 'waiting', NOW(), DATE_ADD(NOW(), INTERVAL 1 DAY))");
$claim = $pdo->prepare("INSERT INTO claims (merchant_id, ip, trx_id, ok, created_at) VALUES (?, '127.0.0.1', ?, ?, NOW())");
$event = $pdo->prepare("INSERT INTO webhook_deliveries (event_id, merchant_id, type, payload, status, next_attempt_at, created_at) VALUES (?, ?, 'payment.matched', '{}', ?, DATE_SUB(NOW(), INTERVAL ? SECOND), NOW())");
$pdo->beginTransaction();
for ($n = 1; $n <= 40000; $n++) {
    $merchant = ($n % 20) + 1;
    $phone = (string) (700000000 + ($n % 400));
    $payment->execute(['pay_' . $n, $merchant, 'TX' . str_pad($n, 8, '0', STR_PAD_LEFT), $phone, $n % 3 ? 'matched' : 'unmatched']);
    $intent->execute(['pi_' . $n, $merchant, $phone, 'customer_' . $n]);
    $claim->execute([$merchant, 'TX' . $n, $n % 2]);
    $event->execute(['evt_' . $n, $merchant, $n % 5 ? 'sent' : 'pending', $n % 1000]);
}
$pdo->commit();
$plans = GatewayIndexes::inspect($pdo);
if (count($plans) !== 7) throw new RuntimeException('Expected seven table upgrades.');
foreach ($plans as $sql) $pdo->exec($sql);
if (GatewayIndexes::inspect($pdo)) throw new RuntimeException('Second migration run must have no work.');
foreach (['payments', 'intents', 'claims', 'webhook_deliveries'] as $table) {
    if ((int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn() !== 40000) throw new RuntimeException('Migration changed test rows.');
    $pdo->query('ANALYZE TABLE `' . $table . '`')->fetchAll();
}
}
if (GatewayIndexes::inspect($pdo)) throw new RuntimeException('Expected completed index migration.');
foreach (['payments', 'intents', 'claims', 'webhook_deliveries'] as $table) {
    if ((int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn() !== 40000) throw new RuntimeException('Expected 40,000 preserved synthetic rows per table.');
}
$queries = [
    'payment_feed' => ["SELECT * FROM payments WHERE merchant_id = 1 AND id > 10000 ORDER BY id DESC LIMIT 200", 'idx_pay_feed'],
    'claim_receipt' => ["SELECT * FROM payments WHERE merchant_id = 1 AND trx_id = 'TX00020000' AND kind = 'credit' AND received_at > DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY id DESC LIMIT 2", 'idx_pay_trxid'],
    'payer_match' => ["SELECT * FROM intents WHERE merchant_id = 1 AND payer_key = '700000000' AND status = 'waiting' AND amount = 1000 AND currency = 'UGX' AND expires_at > NOW() ORDER BY id DESC", 'idx_int_match'],
    'repeat_intent' => ["SELECT * FROM intents WHERE merchant_id = 1 AND reference = 'customer_20000' AND payer_key = '700000000' AND status = 'waiting' AND amount = 1000 AND expires_at > NOW() ORDER BY id DESC LIMIT 1", 'idx_int_reuse'],
    'early_payment' => ["SELECT id FROM payments WHERE merchant_id = 1 AND payer_key = '700000000' AND status = 'unmatched' AND kind = 'credit' AND reversed = 0 AND amount = 1000 AND currency = 'UGX' AND received_at > DATE_SUB(NOW(), INTERVAL 1 DAY) ORDER BY id DESC LIMIT 1", 'idx_pay_payer'],
    'failed_claims' => ["SELECT COUNT(*) FROM claims WHERE merchant_id = 1 AND ip = '127.0.0.1' AND ok = 0 AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)", 'idx_claim_ip'],
    'due_webhooks' => ["SELECT id FROM webhook_deliveries WHERE status = 'pending' AND next_attempt_at <= NOW() ORDER BY next_attempt_at, id LIMIT 100", 'idx_wh_due'],
];
foreach ($queries as $name => [$sql, $expected]) {
    $explain = $pdo->query('EXPLAIN ' . $sql)->fetch(PDO::FETCH_ASSOC);
    if ($explain['key'] !== $expected) throw new RuntimeException($name . ' did not use expected index: ' . json_encode($explain));
    if (in_array($name, ['payment_feed', 'due_webhooks'], true) && strpos($explain['Extra'], 'filesort') !== false) throw new RuntimeException($name . ' still sorts the backlog.');
    echo $name . ': ' . $explain['key'] . '; estimated rows=' . $explain['rows'] . '; ' . $explain['Extra'] . "\n";
}
echo "PASS: real MariaDB/MySQL upgrade and idempotency; 160,000 synthetic rows preserved; seven query plans use intended indexes.\n";
