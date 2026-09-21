<?php
/**
 * Adds the table that remembers when each listener was reporting.
 *
 *   php bin/migrate-uptime.php            shows what would change
 *   php bin/migrate-uptime.php --apply    makes the change
 *
 * Additive only: one table, nothing else touched, and running it again does nothing.
 */
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/src/Db.php';
$apply = in_array('--apply', $argv, true);
$there = Db::row("SELECT COUNT(*) c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'device_uptime'");
if ((int) $there['c'] > 0) { echo "Already up to date.\n"; exit(0); }
echo "The device_uptime table would be created.\n";
if (!$apply) { echo "Dry run. Add --apply to make the change.\n"; exit(0); }
$schema = file_get_contents(dirname(__DIR__) . '/schema.sql');
if (!preg_match('/CREATE TABLE IF NOT EXISTS device_uptime \(.*?\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;/s', $schema, $m)) {
    fwrite(STDERR, "Could not find the table definition in schema.sql.\n");
    exit(1);
}
Db::pdo()->exec($m[0]);
echo "Done.\n";
