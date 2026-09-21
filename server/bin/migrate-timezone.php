<?php
/**
 * Adds the merchant's time zone.
 *
 *   php bin/migrate-timezone.php            shows what would change
 *   php bin/migrate-timezone.php --apply    makes the change
 *
 * Additive only: one column, empty by default, which means the reader's own zone
 * exactly as before. Running it again does nothing.
 */
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/src/Db.php';
$apply = in_array('--apply', $argv, true);
$there = Db::row("SELECT COUNT(*) c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'merchants' AND column_name = 'timezone'");
if ((int) $there['c'] > 0) { echo "Already up to date.\n"; exit(0); }
echo "merchants.timezone would be added.\n";
if (!$apply) { echo "Dry run. Add --apply to make the change.\n"; exit(0); }
Db::pdo()->exec("ALTER TABLE merchants ADD COLUMN timezone VARCHAR(64) NOT NULL DEFAULT '' AFTER webhook_secret");
echo "Done.\n";
