<?php
/**
 * Retries webhooks a merchant did not accept the first time.
 * Run every minute:  * * * * * php /path/to/server/bin/deliver-webhooks.php
 */
require dirname(__DIR__) . '/src/Db.php';
require dirname(__DIR__) . '/src/Parser.php';
require dirname(__DIR__) . '/src/Gateway.php';
date_default_timezone_set('UTC');

$due = Db::rows("SELECT id FROM webhook_deliveries WHERE status = 'pending' AND next_attempt_at <= ? ORDER BY id LIMIT 100", [date('Y-m-d H:i:s')]);
$sent = 0;
foreach ($due as $d) {
    if (Gateway::deliver((int) $d['id'])) {
        $sent++;
    }
}
if ($due) {
    // Quiet when there is nothing to do, so the log only shows real retries.
    echo date('c') . ' due=' . count($due) . ' sent=' . $sent . PHP_EOL;
}
