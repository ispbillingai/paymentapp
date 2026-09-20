<?php
/** Usage: php bin/migrate-indexes.php [--apply]. Default is read-only planning. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/Db.php';

if (array_diff(array_slice($argv, 1), ['--apply', '--help'])) {
    fwrite(STDERR, "Usage: php bin/migrate-indexes.php [--apply]\n");
    exit(2);
}
if (in_array('--help', $argv, true)) {
    echo "Usage: php bin/migrate-indexes.php [--apply]\nWithout --apply, prints the required index changes without creating tables or changing data.\nUses GATEWAY_CONFIG when set, otherwise server/config.php.\n";
    exit;
}

$pdo = null;
$lockName = null;
try {
    // Do not bootstrap tables: even the dry-run must remain read-only.
    $pdo = Db::pdo(false);
    $apply = in_array('--apply', $argv, true);
    if ($apply) {
        $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        $lockName = 'gateway-indexes-' . substr(hash('sha256', $database), 0, 32);
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 0)');
        $lock->execute([$lockName]);
        if ((int) $lock->fetchColumn() !== 1) throw new RuntimeException('Another gateway index upgrade is running.');
        $pdo->exec('SET SESSION lock_wait_timeout = 5');
    }
    // Inspect all tables before changing any of them.
    $plans = GatewayIndexes::inspect($pdo);
    if (!$plans) echo "Gateway indexes are already up to date.\n";
    foreach ($plans as $table => $sql) {
        if (!$apply) {
            echo $sql . ";\n";
            continue;
        }
        echo "Updating indexes for $table...\n";
        $pdo->exec($sql);
        echo "Updated $table.\n";
    }
    if (!$apply && $plans) echo "Dry run only. Re-run with --apply after reviewing the plan and choosing a quiet deployment window.\n";
    if ($apply && GatewayIndexes::inspect($pdo)) throw new RuntimeException('Index verification found remaining changes. Re-run the dry-run to inspect.');
} catch (Throwable $e) {
    fwrite(STDERR, 'Index upgrade stopped: ' . $e->getMessage() . "\nNo automatic blocking/table-copy fallback is attempted. Successful tables are safe to skip on the next run.\n");
    exit(1);
} finally {
    if ($pdo && $lockName) {
        $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$lockName]);
    }
}
