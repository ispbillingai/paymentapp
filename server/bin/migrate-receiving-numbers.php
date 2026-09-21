<?php
/**
 * Separates where customers pay from the phones that forward the messages.
 *
 *   php bin/migrate-receiving-numbers.php            shows what would change
 *   php bin/migrate-receiving-numbers.php --apply    makes the change
 *
 * Additive only: a new table, plus one column on devices for naming a network we
 * do not know. Any number already typed on a listener is copied across, so what
 * customers see does not change. Running it again does nothing.
 */
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/src/Db.php';
require dirname(__DIR__) . '/src/Gateway.php';
$apply = in_array('--apply', $argv, true);

$has = function ($table, $column = null) {
    $row = $column === null
        ? Db::row("SELECT COUNT(*) c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?", [$table])
        : Db::row("SELECT COUNT(*) c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?", [$table, $column]);
    return (int) $row['c'] > 0;
};

$steps = [];
if (!$has('devices', 'provider_name')) {
    $steps['devices.provider_name'] = "ALTER TABLE devices ADD COLUMN provider_name VARCHAR(40) NOT NULL DEFAULT '' AFTER provider";
}
if (!$has('receiving_numbers')) {
    $steps['receiving_numbers'] = "CREATE TABLE receiving_numbers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        public_id VARCHAR(40) NOT NULL,
        merchant_id INT NOT NULL,
        provider VARCHAR(30) NOT NULL DEFAULT '',
        provider_name VARCHAR(40) NOT NULL DEFAULT '',
        number VARCHAR(20) NOT NULL DEFAULT '',
        account_name VARCHAR(100) NOT NULL DEFAULT '',
        status VARCHAR(10) NOT NULL DEFAULT 'active',
        created_at DATETIME NOT NULL,
        UNIQUE KEY uq_num_public (public_id),
        KEY idx_num_merchant (merchant_id, status, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
}

if (!$steps) {
    echo "Schema already up to date.\n";
} else {
    foreach (array_keys($steps) as $what) {
        echo "$what would be added.\n";
    }
}

// Listeners that already carry a number keep telling customers the same thing.
$carry = [];
if ($has('receiving_numbers')) {
    foreach (Db::rows("SELECT * FROM devices WHERE status = 'active' AND receiving_number <> ''") as $d) {
        $there = Db::row("SELECT COUNT(*) c FROM receiving_numbers WHERE merchant_id = ? AND number = ?", [(int) $d['merchant_id'], $d['receiving_number']]);
        if ((int) $there['c'] === 0) {
            $carry[] = $d;
        }
    }
} else {
    $carry = Db::rows("SELECT * FROM devices WHERE status = 'active' AND receiving_number <> ''");
}
echo count($carry) . " listener number(s) would be copied across.\n";

if (!$apply) {
    echo "Dry run. Add --apply to make the change.\n";
    exit(0);
}
foreach ($steps as $sql) {
    Db::pdo()->exec($sql);
}
foreach ($carry as $d) {
    Db::run(
        "INSERT INTO receiving_numbers (public_id, merchant_id, provider, provider_name, number, account_name, status, created_at)
         VALUES (?,?,?,?,?,?, 'active', ?)",
        [Gateway::newId('num'), (int) $d['merchant_id'], $d['provider'], isset($d['provider_name']) ? $d['provider_name'] : '',
         $d['receiving_number'], $d['receiving_name'], date('Y-m-d H:i:s')]
    );
}
echo "Done.\n";
