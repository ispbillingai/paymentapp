<?php
/**
 * Reads unread payments again with the current parser, so money that came in
 * before the parser knew its country's format still connects the customer.
 *
 *   php bin/reread-unread.php            shows what would change
 *   php bin/reread-unread.php --apply    re-reads and matches them
 *   php bin/reread-unread.php --apply --days=7
 *
 * Only payments still unread and unmatched are touched. Running it again does
 * nothing to a payment it already read.
 */
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/src/Db.php';
require dirname(__DIR__) . '/src/Parser.php';
require dirname(__DIR__) . '/src/Gateway.php';
$apply = in_array('--apply', $argv, true);
$days = 2;
foreach ($argv as $a) {
    if (preg_match('/^--days=(\d{1,3})$/', $a, $m)) $days = max(1, (int) $m[1]);
}
$rows = Db::rows(
    "SELECT p.id, p.public_id, p.provider, p.raw_message, m.currency FROM payments p JOIN merchants m ON m.id = p.merchant_id
      WHERE p.kind = 'unread' AND p.status = 'unmatched' AND p.reversed = 0 AND p.received_at > ? ORDER BY p.id",
    [date('Y-m-d H:i:s', time() - $days * 86400)]
);
$readable = 0;
foreach ($rows as $r) {
    $p = Parser::parseMessage($r['provider'], (string) $r['raw_message'], (string) $r['currency']);
    if ($p['kind'] !== 'credit') {
        echo "{$r['public_id']}  still unread\n";
        continue;
    }
    $readable++;
    $line = "{$r['public_id']}  {$p['amount']} {$p['currency']} from {$p['payer_msisdn']} {$p['payer_name']}";
    echo $apply ? $line . '  ' . (Gateway::reread((int) $r['id']) ?: 'unchanged') . "\n" : $line . "\n";
}
echo count($rows) . " unread, $readable readable now.\n";
if (!$apply) echo "Dry run. Add --apply to re-read them.\n";
