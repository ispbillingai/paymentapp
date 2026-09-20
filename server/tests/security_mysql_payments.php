<?php
/** Optional concurrency check: explicit empty gateway_test_* database only. */
if (PHP_SAPI !== 'cli') exit;
$file = getenv('GATEWAY_PAYMENT_TEST_CONFIG');
if (!$file || !is_file($file)) {
    fwrite(STDERR, "Set GATEWAY_PAYMENT_TEST_CONFIG to an isolated, empty test database configuration.\n");
    exit(2);
}
putenv('GATEWAY_CONFIG=' . $file);
require dirname(__DIR__) . '/src/Db.php';
require dirname(__DIR__) . '/src/Parser.php';
require dirname(__DIR__) . '/src/Gateway.php';
date_default_timezone_set('UTC');
if (!preg_match('/^gateway_test_[a-z0-9_]+$/', Config::get('db')['name'])) throw new RuntimeException('Test database name must start with gateway_test_.');
if (($argv[1] ?? '') === '--worker') {
    $start = (float) $argv[3];
    while (microtime(true) < $start) usleep(1000);
    echo Gateway::matchPayment((int) $argv[2]) ? 'matched' : 'unmatched';
    exit;
}
$pdo = Db::pdo(false);
if ($pdo->query('SHOW TABLES')->fetch()) throw new RuntimeException('Refusing to modify a non-empty test database.');
foreach (array_filter(array_map('trim', explode(";\n", file_get_contents(dirname(__DIR__) . '/schema.sql')))) as $sql) $pdo->exec($sql);
$pdo->exec("INSERT INTO merchants (public_id, name, country, dial_code, currency, webhook_url, webhook_secret, created_at) VALUES ('mer_test', 'Test', 'uganda', '256', 'UGX', '', 'test-only', NOW())");
$pdo->exec("INSERT INTO intents (public_id, merchant_id, amount, currency, payer_msisdn, payer_key, payer_name, reference, status, created_at, expires_at) VALUES ('pi_test', 1, 1000, 'UGX', '256772123456', '772123456', 'Alice', 'customer_test', 'waiting', UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 DAY))");
for ($n = 1; $n <= 2; $n++) {
    $statement = $pdo->prepare("INSERT INTO payments (public_id, merchant_id, provider, receiving_key, trx_id, amount, currency, payer_msisdn, payer_key, payer_name, received_at) VALUES (?, 1, 'mtn_ug', 'receiver', ?, 1000, 'UGX', '256772123456', '772123456', 'Alice', UTC_TIMESTAMP())");
    $statement->execute(['pay_' . $n, 'TX' . $n]);
}
$workers = [];
$start = (string) (microtime(true) + 1.0);
for ($n = 1; $n <= 2; $n++) {
    $pipes = [];
    $process = proc_open([PHP_BINARY, __FILE__, '--worker', (string) $n, $start], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true, 'create_no_window' => true]);
    if (!is_resource($process)) throw new RuntimeException('Could not create isolated concurrency worker.');
    fclose($pipes[0]);
    $workers[] = [$process, $pipes];
}
$results = [];
foreach ($workers as [$process, $pipes]) {
    $results[] = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0 || $error !== '') throw new RuntimeException('Worker failed: ' . $error);
}
sort($results);
if ($results !== ['matched', 'unmatched']) throw new RuntimeException('Exactly one receipt must match: ' . json_encode($results));
if ((int) $pdo->query("SELECT COUNT(*) FROM payments WHERE status = 'matched'")->fetchColumn() !== 1) throw new RuntimeException('Receipt double match.');
if ((int) $pdo->query("SELECT COUNT(*) FROM webhook_deliveries WHERE type = 'payment.matched'")->fetchColumn() !== 1) throw new RuntimeException('Duplicate activation event.');
$intent = Db::row('SELECT * FROM intents WHERE id = 1');
$matched = Db::row("SELECT * FROM payments WHERE status = 'matched'");
if ((int) $intent['payment_id'] !== (int) $matched['id']) throw new RuntimeException('Payment/intent link disagree.');
echo "PASS: two concurrent receipts produced one paid intent and one activation event; the other receipt remains unmatched. No external requests.\n";

$pdo->exec("INSERT INTO intents (public_id, merchant_id, amount, currency, payer_msisdn, payer_key, payer_name, reference, status, created_at, expires_at) VALUES ('pi_reversed', 1, 1000, 'UGX', '256775555555', '775555555', 'Jane', 'customer_reversed', 'waiting', UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 DAY))");
$device = ['id' => 1, 'merchant_id' => 1, 'provider' => 'mtn_ug', 'extra_senders' => '', 'dial_code' => '256', 'receiving_key' => 'receiver', 'merchant_currency' => 'UGX'];
$reversal = 'Transaction ID REV00001 has been reversed.';
$credit = 'You have received UGX 1000 from Jane 0775555555. Transaction ID REV00001';
if (Gateway::ingest($device, 'MTNMoMo', $reversal) !== 'reversal') throw new RuntimeException('Early reversal was not retained.');
if (Gateway::ingest($device, 'MTNMoMo', $credit) !== 'duplicate') throw new RuntimeException('Original credit did not reuse reversal receipt.');
Gateway::ingest($device, 'MTNMoMo', $credit);
Gateway::ingest($device, 'MTNMoMo', $reversal);
$receipt = Db::row("SELECT * FROM payments WHERE trx_id = 'REV00001'");
if ((int) $receipt['reversed'] !== 1 || $receipt['kind'] !== 'credit' || (float) $receipt['amount'] !== 1000.0) throw new RuntimeException('Reversal state or actual amount lost.');
if (Db::row("SELECT status FROM intents WHERE public_id = 'pi_reversed'")['status'] !== 'waiting') throw new RuntimeException('A reversed receipt activated a purchase.');
if ((int) $pdo->query("SELECT COUNT(*) FROM webhook_deliveries WHERE type = 'payment.reversed'")->fetchColumn() !== 1) throw new RuntimeException('Reversal event missing or duplicated.');
if ((int) $pdo->query("SELECT COUNT(*) FROM webhook_deliveries WHERE type = 'payment.matched'")->fetchColumn() !== 1) throw new RuntimeException('Reversal unexpectedly created activation.');
echo "PASS: reversal before credit stays reversed, records actual receipt details, creates one reversal event, and never activates.\n";
