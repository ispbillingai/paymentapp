<?php
/**
 * Lets a merchant exist before its webhook does.
 *
 *   php bin/migrate-webhook-nullable.php            shows what would change
 *   php bin/migrate-webhook-nullable.php --apply    makes the change
 *
 * merchants.webhook_url was NOT NULL DEFAULT '' under a unique key, so only one
 * merchant in the whole gateway could ever be without an address. NULL may repeat
 * under a unique key, so the column becomes nullable and any empty value becomes
 * NULL. Nothing else is touched, and running it again changes nothing.
 */
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/src/Db.php';
$apply = in_array('--apply', $argv, true);
$column = Db::row("SELECT IS_NULLABLE n FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'merchants' AND column_name = 'webhook_url'");
if (!$column) { fwrite(STDERR, "merchants.webhook_url was not found.\n"); exit(1); }
$empty = (int) Db::row("SELECT COUNT(*) c FROM merchants WHERE webhook_url = ''")['c'];
if ($column['n'] === 'YES' && $empty === 0) { echo "Already up to date.\n"; exit(0); }
echo ($column['n'] === 'YES' ? 'Column is already nullable.' : 'Column would become nullable.') . " Empty addresses to turn into NULL: $empty.\n";
if (!$apply) { echo "Dry run. Add --apply to make the change.\n"; exit(0); }
if ($column['n'] !== 'YES') Db::pdo()->exec("ALTER TABLE merchants MODIFY webhook_url VARCHAR(255) DEFAULT NULL");
Db::run("UPDATE merchants SET webhook_url = NULL WHERE webhook_url = ''");
echo "Done.\n";
